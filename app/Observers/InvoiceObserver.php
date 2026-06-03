<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Services\InvoiceBalance;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\DB;

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
     * When an invoice transitions INTO `active` (finalize / issue), decrement
     * stock for its track_stock goods lines — whichever-first, idempotent (see
     * StockService). Guarded to the local_status→active change so the many other
     * saves (recompute, payments, edits) are a cheap no-op. Credit notes are
     * skipped here (they are a return = stock-IN, S3).
     */
    private function applyStockSaleIfActivated(Invoice $invoice): void
    {
        if ($invoice->credited_invoice_id !== null) {
            return;
        }
        if (! $invoice->wasChanged('local_status') || $invoice->local_status !== 'active') {
            return;
        }

        app(StockService::class)->recordSaleForInvoice($invoice);
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
