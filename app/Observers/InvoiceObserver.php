<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Models\ServiceContract;
use App\Services\InvoiceBalance;
use App\Services\Stock\StockService;
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
        $this->advanceServiceContractOnIssue($invoice);
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
     *   • → cancelled, normal       → reverse the sale-OUT (+qty back, S3)
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
            } elseif ($status === 'cancelled' && ! $isCreditNote) {
                $stock->reverseSaleForInvoice($invoice);
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

        DB::transaction(fn () => $this->balance->recompute($original));
    }
}
