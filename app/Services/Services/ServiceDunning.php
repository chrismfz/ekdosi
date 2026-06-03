<?php

namespace App\Services\Services;

use App\Enums\ServiceContractStatus;
use App\Models\Invoice;
use App\Models\ServiceContract;
use App\Services\Payments\PaymentAllocator;
use App\Services\Provisioning\ProvisioningModuleRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PR-D — the dunning decision engine for recurring services.
 *
 * Given a contract, it decides whether the contract's overdue arrears warrant a
 * lifecycle transition (suspend / terminate) — or, for a suspended contract that
 * was paid, an unsuspend — and APPLIES it (status change + provisioning hook +
 * unissued-draft cascade on terminate). The overdue signal is reused verbatim
 * from {@see Invoice::isOverdue()} / {@see Invoice::dueDate()} — no due-date math
 * is reinvented here.
 *
 * SAFETY (this is a DESTRUCTIVE feature):
 *   - Respects the per-product «Knob»: effective_enabled =
 *     contract.dunning_enabled ?? product.dunning_enabled ?? false. Default OFF
 *     → a contract is never touched until an operator opts its product in (or
 *     overrides on the contract). Disabled → an immediate no-op.
 *   - Only acts when the day-threshold is non-null AND the state machine
 *     (ServiceContractStatus::canTransitionTo) allows the move. Terminate takes
 *     priority over suspend.
 *   - Touches ONLY the contract's lifecycle columns + the provisioning hook.
 *     NEVER money: no invoice/balance/payment write. Unissued draft renewals are
 *     cancelled on terminate (same predicate as the ViewServiceContract cascade)
 *     — those are local drafts with no MARK, not money/legal documents.
 *   - {@see wouldDo()} is a genuinely read-only sibling of {@see evaluate()} for
 *     --dry-run: it runs the SAME decision but persists/calls nothing.
 *
 * The status change persists via the model (so TracksActivity logs «ποιος/πότε»).
 * Tenant scoping: the caller hands us a contract; all sub-queries scope
 * company_id explicitly (no global scope in CLI).
 */
class ServiceDunning
{
    public function __construct(private ProvisioningModuleRegistry $modules) {}

    /**
     * Decide + APPLY the transition. Returns the action taken
     * ('suspended'|'terminated'|'unsuspended') or null when nothing is due.
     */
    public function evaluate(ServiceContract $contract, ?Carbon $asOf = null): ?string
    {
        $action = $this->decide($contract, $asOf);
        if ($action === null) {
            return null;
        }

        $this->apply($contract, $action);

        return $action;
    }

    /**
     * READ-ONLY: what evaluate() WOULD do, persisting/calling nothing. Backs
     * --dry-run. Same decision path as evaluate(), zero side effects.
     */
    public function wouldDo(ServiceContract $contract, ?Carbon $asOf = null): ?string
    {
        return $this->decide($contract, $asOf);
    }

    /**
     * The pure decision (no side effects): returns the action string or null.
     * 'suspended' / 'terminated' / 'unsuspended'.
     */
    private function decide(ServiceContract $contract, ?Carbon $asOf = null): ?string
    {
        $asOf ??= Carbon::today();

        if (! $this->effectiveEnabled($contract)) {
            return null; // the per-product Knob is OFF (or unset) — the safety.
        }

        $product = $contract->product;
        $suspendDays = $contract->suspend_after_days ?? $product?->default_suspend_after_days;
        $terminateDays = $contract->terminate_after_days ?? $product?->default_terminate_after_days;

        $overdueDays = $this->overdueDays($contract, $asOf);
        $status = $contract->status;

        // Terminate has priority over suspend.
        if ($terminateDays !== null
            && $overdueDays > $terminateDays
            && $status->canTransitionTo(ServiceContractStatus::Terminated)) {
            return 'terminated';
        }

        if ($suspendDays !== null
            && $overdueDays > $suspendDays
            && $status === ServiceContractStatus::Active) {
            return 'suspended';
        }

        // Reactivation: a contract that DUNNING suspended (marker set) whose
        // arrears cleared (overdueDays back to 0 → the customer paid) comes back
        // to life. GUARD on the dunning marker — NOT on overdueDays alone — so we
        // can never undo a MANUAL «Αναστολή» (abuse/fraud/customer hold) of a
        // paid-up contract (that has no marker).
        if ($status === ServiceContractStatus::Suspended
            && $contract->dunning_suspended_at !== null
            && $overdueDays === 0
            && $status->canTransitionTo(ServiceContractStatus::Active)) {
            return 'unsuspended';
        }

        return null;
    }

    /**
     * effective_enabled = contract override ?? product default ?? false.
     * The per-product Knob (default OFF) is the master safety.
     */
    private function effectiveEnabled(ServiceContract $contract): bool
    {
        return $contract->dunning_enabled
            ?? $contract->product?->dunning_enabled
            ?? false;
    }

    /**
     * Days the contract is in arrears = days since the due date of its MOST
     * overdue unpaid, issued renewal invoice. 0 when none is overdue (current
     * or fully paid). Reuses Invoice::isOverdue()/dueDate() — the same signal
     * the receivables surfaces use. Tenant-scoped explicitly by company_id.
     */
    private function overdueDays(ServiceContract $contract, Carbon $asOf): int
    {
        $invoices = Invoice::query()
            ->where('company_id', $contract->company_id)
            ->where('service_contract_id', $contract->id)
            ->with('paymentMethod')
            ->get();

        $max = 0;
        $overdueBalance = 0.0;
        foreach ($invoices as $invoice) {
            if (! $invoice->isOverdue($asOf)) {
                continue;
            }
            $due = $invoice->dueDate();
            if ($due === null) {
                continue;
            }
            // diffInDays gives a positive whole-day count from due → asOf.
            $days = (int) $due->startOfDay()->diffInDays($asOf->copy()->startOfDay());
            $max = max($max, $days);
            $overdueBalance += (float) $invoice->balanceData()->balance;
        }

        if ($max === 0) {
            return 0;
        }

        // SAFETY (don't cut off a paying customer): if the customer has enough
        // UNALLOCATED on-account credit to cover the overdue renewal balance,
        // they've effectively paid — the operator just hasn't allocated it.
        // Treat as not-in-arrears. Conservative: err toward NOT suspending.
        $credit = app(PaymentAllocator::class)->availableCredit($contract->customer);
        if ($credit + 0.005 >= round($overdueBalance, 2)) {
            return 0;
        }

        return $max;
    }

    /** Persist the transition + fire the (best-effort) provisioning hook. */
    private function apply(ServiceContract $contract, string $action): void
    {
        switch ($action) {
            case 'terminated':
                $cancelled = $this->cancelUnissuedDrafts($contract);
                $contract->update([
                    'status' => ServiceContractStatus::Terminated,
                    'terminated_at' => now(),
                    'next_due_date' => null,
                ]);
                $this->provision($contract, 'terminate');
                Log::info('Dunning terminated a service contract.', [
                    'who' => 'System',
                    'company_id' => $contract->company_id,
                    'service_contract_id' => $contract->id,
                    'cancelled_drafts' => $cancelled,
                ]);
                break;

            case 'suspended':
                $contract->update([
                    'status' => ServiceContractStatus::Suspended,
                    'suspended_at' => now(),
                    // Mark as DUNNING-suspended so auto-unsuspend can later
                    // reactivate it — and ONLY it (never a manual hold).
                    'dunning_suspended_at' => now(),
                ]);
                $this->provision($contract, 'suspend');
                Log::info('Dunning suspended a service contract.', [
                    'who' => 'System',
                    'company_id' => $contract->company_id,
                    'service_contract_id' => $contract->id,
                ]);
                break;

            case 'unsuspended':
                $contract->update([
                    'status' => ServiceContractStatus::Active,
                    'suspended_at' => null,
                    'dunning_suspended_at' => null,
                ]);
                $this->provision($contract, 'unsuspend');
                Log::info('Dunning reactivated a paid service contract.', [
                    'who' => 'System',
                    'company_id' => $contract->company_id,
                    'service_contract_id' => $contract->id,
                ]);
                break;
        }
    }

    /**
     * Best-effort provisioning call: the local status is already persisted, so a
     * future real module that throws on a transport error is logged and swallowed
     * — never rolls back the contract's state. The Null module never throws.
     */
    private function provision(ServiceContract $contract, string $method): void
    {
        try {
            $module = $this->modules->for((string) $contract->provisioning_module);
            $module->{$method}($contract);
        } catch (Throwable $e) {
            Log::error('Provisioning hook failed (local status already applied).', [
                'company_id' => $contract->company_id,
                'service_contract_id' => $contract->id,
                'module' => $contract->provisioning_module,
                'method' => $method,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cancel the contract's UNISSUED draft renewals (draft + no MARK) — the same
     * predicate as the ViewServiceContract terminate cascade. Explicit
     * company_id scope. Returns the number cancelled.
     */
    private function cancelUnissuedDrafts(ServiceContract $contract): int
    {
        return Invoice::query()
            ->where('company_id', $contract->company_id)
            ->where('service_contract_id', $contract->id)
            ->where('local_status', 'draft')
            ->whereNull('mydata_mark')
            ->update(['local_status' => 'cancelled']);
    }
}
