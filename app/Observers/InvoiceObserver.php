<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Models\ServiceContract;
use App\Services\CustomerLedger\CustomerLedgerBuilder;
use App\Services\Domains\DomainRenewalInProgress;
use App\Services\Domains\DomainRenewalService;
use App\Services\InvoiceBalance;
use App\Services\RecomputeReturnedQuantities;
use App\Services\Stock\StockService;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * When a credit note (credited_invoice_id set) is created / saved /
 * (soft-)deleted / restored — including a CANCEL that flips its
 * mydata_state — the ORIGINAL invoice's cached credited_total +
 * payment_status must be refreshed. The live InvoiceBalance::for() is
 * always correct; this keeps the denormalised cache (used by the list
 * badge + dashboard) in sync.
 *
 * No infinite loop: recompute() saves the ORIGINAL, whose
 * credited_invoice_id is null, so the recursive guard returns early.
 * Normal (non-credit-note) invoice saves are a cheap null-check no-op.
 */
class InvoiceObserver
{
    public function __construct(private readonly InvoiceBalance $balance) {}

    public function saved(Invoice $invoice): void
    {
        $this->recomputeOriginal($invoice);
        $this->applyStockSaleIfActivated($invoice);
        // BEFORE the cursor advance: the renewal service reads the SC's
        // next_due_date as the period the invoice covers (§6.6 adopt guard).
        $this->renewDomainOnIssue($invoice);
        $this->advanceServiceContractOnIssue($invoice);
        $this->captureCustomerBalanceSnapshot($invoice);
    }

    /**
     * Domains (Πυλώνας A / A3a, §6.1 «renew = on-issue»): when a renewal
     * invoice for a DOMAIN-linked contract is FIRST issued (draft→active —
     * NOT a restore of a cancelled one: a pre-feature invoice restored today
     * must not fire an unrequested charge), drive the registrar renewal
     * through DomainRenewalService (sync-first, ADOPTS if someone already
     * renewed — the §6.6 war-story guard).
     *
     * DEFERRED to AFTER COMMIT: the finalize action wraps numbering + status
     * in one DB transaction — real registrar HTTP inside it would hold the
     * invoice-type counter lock across up to ~90s of network, and a rollback
     * after the POST would leave a real charge with its audit row rolled
     * back. The SC cursor (the invoice's period start) is captured HERE,
     * in-transaction, before advanceServiceContractOnIssue moves it.
     * Best-effort like its siblings: a registrar failure must never look
     * like a failed issue — it logs, lands in the API history and rings the
     * operators' bell instead.
     */
    private function renewDomainOnIssue(Invoice $invoice): void
    {
        if (! $invoice->wasChanged('local_status') || $invoice->local_status !== 'active') {
            return;
        }
        if ($invoice->getOriginal('local_status') !== 'draft') {
            return; // restore of a cancelled invoice — never an implicit charge
        }
        if ($invoice->service_contract_id === null) {
            return;
        }

        $contract = ServiceContract::query()
            ->where('company_id', $invoice->company_id)
            ->whereKey($invoice->service_contract_id)
            ->first();
        $periodStart = $contract?->next_due_date !== null ? Carbon::parse($contract->next_due_date)->toDateString() : null;
        // Re-issue after «Επαναφορά σε πρόχειρο»: the cursor already advanced
        // FOR this invoice on its first issue (and won't advance again — the
        // last_renewal_invoice_id guard), so the CURRENT cursor is one period
        // ahead of what this invoice bills. Step back one cycle, else the
        // renew targets the wrong period (the war story through the revert
        // door) and the short-flag misfires.
        if ($periodStart !== null
            && $contract !== null
            && (int) $contract->last_renewal_invoice_id === (int) $invoice->id) {
            $months = $contract->billing_cycle?->months();
            $periodStart = $months !== null
                ? Carbon::parse($periodStart)->subMonths($months)->toDateString()
                : $periodStart;
        }
        $invoiceId = $invoice->id;

        DB::afterCommit(function () use ($invoiceId, $periodStart): void {
            $invoice = Invoice::query()->find($invoiceId);
            if ($invoice === null) {
                return;
            }
            try {
                $log = app(DomainRenewalService::class)->renewForInvoice($invoice, $periodStart);
                if ($log !== null && ($log->response['short_of_target'] ?? false)) {
                    // Charged for real but landed SHORT of the billed period
                    // (registrar expiry lagged the cursor) — operator must see.
                    $this->notifyDomainRenewalProblem(
                        $invoice,
                        'Η ανανέωση εκτελέστηκε αλλά η νέα λήξη ('.($log->response['registrar_expiry'] ?? '—').') ΔΕΝ φτάνει την περίοδο που χρεώθηκε ('.($log->request['target_expiry'] ?? '—').') — ελέγξτε το domain.'
                    );
                }
            } catch (DomainRenewalInProgress $e) {
                // A concurrent renewal (the View button, most likely) holds the
                // lock — it IS this period's renewal. «Try again» advice here
                // would cause the double charge: the button's baseline is the
                // (by then extended) current expiry, which can never adopt.
                Log::info('Domain renewal on issue skipped — another renewal in progress', [
                    'invoice_id' => $invoice->id, 'error' => $e->getMessage(),
                ]);
                $this->notifyDomainRenewalProblem(
                    $invoice,
                    'Το παραστατικό '.($invoice->code ?: '#'.$invoice->id).' εκδόθηκε ενώ έτρεχε ήδη άλλη ανανέωση του domain. ΜΗΝ ξαναζητήσετε ανανέωση — ελέγξτε το ιστορικό API του domain σε λίγο.'
                );
            } catch (Throwable $e) {
                Log::warning('Domain renewal on invoice issue failed (the issue succeeded)', [
                    'invoice_id' => $invoice->id,
                    'service_contract_id' => $invoice->service_contract_id,
                    'error' => $e->getMessage(),
                ]);
                $this->notifyDomainRenewalProblem(
                    $invoice,
                    'Το παραστατικό '.($invoice->code ?: '#'.$invoice->id).' εκδόθηκε, αλλά η ανανέωση στον registrar απέτυχε: '.$e->getMessage().' Δοκιμάστε «Ανανέωση στον registrar» από το domain.'
                );
            }
        });
    }

    /** Best-effort operators' bell for a registrar-renewal problem. */
    private function notifyDomainRenewalProblem(Invoice $invoice, string $body): void
    {
        try {
            $recipients = $invoice->company?->users;
            if ($recipients === null || $recipients->isEmpty()) {
                return;
            }
            Notification::make()
                ->title('Πρόβλημα ανανέωσης domain στον registrar')
                ->body($body)
                ->icon('heroicon-o-globe-alt')
                ->danger()
                ->sendToDatabase($recipients);
        } catch (Throwable $notify) {
            Log::warning('Domain renewal notification failed', ['error' => $notify->getMessage()]);
        }
    }

    /**
     * Capture the customer's TOTAL running balance (Καρτέλα «υπόλοιπο», incl.
     * on-account credit) at the moment an invoice is ISSUED — so the PDF can
     * print a legacy-true «Νέο υπόλοιπο» block that stays STABLE on reprint
     * (a live recompute would drift as later invoices/payments land).
     *
     * Fires exactly once per invoice: only when it FIRST becomes active with a
     * customer (`customer_balance_snapshot` still null) AND actually moves the
     * balance (credit-term sale or credit note — cash-term is settled at issue,
     * so a «Νέο υπόλοιπο» there is meaningless and we skip it). App-issued only:
     * the ETL/Epsilon importers write via raw query-builder (no observer), and
     * the `legacy_id` guard belts-and-suspenders any Eloquent-path import.
     *
     * Best-effort: the issue already persisted, so a hiccup computing the
     * balance must never look like a failed finalize. saveQuietly avoids
     * re-entering the observer.
     *
     * Deliberately NOT gated on wasChanged('local_status') (unlike the stock /
     * service-contract hooks): the `snapshot !== null` guard already short-circuits
     * every already-captured invoice BEFORE the read-heavy buildStatsBlock, so the
     * only builds are the at-issue capture and a retry on a still-null one (a prior
     * best-effort failure) — both wanted. Omitting the transition guard keeps that
     * retry resilience.
     */
    private function captureCustomerBalanceSnapshot(Invoice $invoice): void
    {
        if ($invoice->local_status !== 'active'
            || $invoice->customer_balance_snapshot !== null
            || $invoice->customer_id === null
            || $invoice->legacy_id !== null
            || ! $invoice->affectsCustomerBalance()) {
            return;
        }

        try {
            $customer = $invoice->customer;
            if ($customer === null) {
                return;
            }

            $balance = app(CustomerLedgerBuilder::class)
                ->buildStatsBlock($customer)['stats']['balance'] ?? null;

            if ($balance === null) {
                return;
            }

            $invoice->forceFill([
                'customer_balance_snapshot' => round((float) $balance, 2),
            ])->saveQuietly();
        } catch (Throwable $e) {
            Log::warning('Capturing customer balance snapshot failed (the issue succeeded)', [
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Recurring services: the billing cursor advances when a contract's renewal
     * is ISSUED (draft→active) — NOT when StageServiceRenewal merely staged the
     * draft. So an un-billed/un-paid renewal keeps next_due_date in the past (the
     * dunning signal), and only an actually-issued renewal moves the clock. The
     * `last_renewal_invoice_id` guard makes this fire exactly once per invoice
     * (re-finalize / repeated saves are no-ops). Best-effort: the status change
     * already persisted, so a hiccup here must never look like a failed issue.
     */
    private function advanceServiceContractOnIssue(Invoice $invoice): void
    {
        if (! $invoice->wasChanged('local_status') || $invoice->local_status !== 'active') {
            return;
        }
        if ($invoice->service_contract_id === null) {
            return;
        }

        try {
            DB::transaction(function () use ($invoice) {
                $contract = ServiceContract::query()
                    ->whereKey($invoice->service_contract_id)
                    ->lockForUpdate()
                    ->first();
                if ($contract === null
                    || (int) $contract->last_renewal_invoice_id === (int) $invoice->id) {
                    return; // gone, or already advanced by this invoice
                }

                // Advance one cycle from the current cursor (the billed period).
                // One-Time → advance() is null → cursor nulled (bills once).
                $next = $contract->next_due_date
                    ? $contract->billing_cycle?->advance(Carbon::parse($contract->next_due_date))
                    : null;

                $contract->forceFill([
                    'next_due_date' => $next?->toDateString(),
                    'last_invoiced_at' => now(),
                    'last_renewal_invoice_id' => $invoice->id,
                ])->save();
            });
        } catch (Throwable $e) {
            Log::warning('Advancing service contract on renewal issue failed (the issue succeeded)', [
                'invoice_id' => $invoice->id,
                'service_contract_id' => $invoice->service_contract_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Drive stock on a local_status transition (guarded so the many other saves —
     * recompute, payments, edits — are a cheap no-op):
     *   • → active, normal invoice  → sale-OUT  (−qty, whichever-first)
     *   • → active, credit note     → return-IN (+qty, S3)
     *   • → cancelled, normal        → reverse the sale-OUT   (+qty back, S3)
     *   • → cancelled, credit note   → reverse the return-IN  (−qty back out, STOCK-001)
     * Best-effort: the status was already persisted, so a stock-write hiccup must
     * never surface as a false "finalize/cancel failed".
     */
    private function applyStockSaleIfActivated(Invoice $invoice): void
    {
        if (! $invoice->wasChanged('local_status')) {
            return;
        }

        $status = $invoice->local_status;
        $isCreditNote = $invoice->credited_invoice_id !== null;

        try {
            $stock = app(StockService::class);
            if ($status === 'active') {
                $isCreditNote
                    ? $stock->recordReturnForCreditNote($invoice)
                    : $stock->recordSaleForInvoice($invoice);
            } elseif ($status === 'cancelled') {
                // STOCK-001: cancelling a credit note must reverse its return-IN, or
                // the freed qty_returned (MON-1) lets a later invoice-cancel reverse
                // the full sale again and inflates stock. Symmetric with the sale case.
                $isCreditNote
                    ? $stock->reverseReturnForCreditNote($invoice)
                    : $stock->reverseSaleForInvoice($invoice);
            }
        } catch (Throwable $e) {
            Log::warning('Stock movement on invoice status change failed (the status change succeeded)', [
                'invoice_id' => $invoice->id,
                'local_status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function deleted(Invoice $invoice): void
    {
        $this->recomputeOriginal($invoice);
    }

    public function restored(Invoice $invoice): void
    {
        $this->recomputeOriginal($invoice);
    }

    private function recomputeOriginal(Invoice $invoice): void
    {
        $originalId = $invoice->credited_invoice_id;
        if ($originalId === null) {
            return;
        }

        $original = Invoice::find($originalId);
        if (! $original) {
            return;
        }

        // Cycle guard: a true original has credited_invoice_id = null.
        // IssueCreditNote forbids crediting a credit note, but the schema
        // (self-FK) allows a direct DB write to chain A→B→A; refusing to
        // recompute when the "original" is itself a credit note breaks
        // any such cycle instead of recursing until stack/lock exhaustion.
        if ($original->credited_invoice_id !== null) {
            return;
        }

        DB::transaction(function () use ($original) {
            $this->balance->recompute($original);
            // MON-1: keep qty_returned in sync with the LIVE credit notes too,
            // so a cancelled/deleted credit note frees the returned quantity
            // and the original can be re-credited (mirrors credited_total).
            app(RecomputeReturnedQuantities::class)($original);
        });
    }
}
