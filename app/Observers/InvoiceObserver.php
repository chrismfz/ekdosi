<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Services\InvoiceBalance;
use App\Services\Stock\StockService;
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
