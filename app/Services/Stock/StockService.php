<?php

namespace App\Services\Stock;

use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\StockMovement;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Single source for stock on-hand. The level is ALWAYS derived (SUM of the
 * ledger) — there is no cached quantity column to drift, mirroring how
 * InvoiceBalance derives money. Stock is informational: nothing here ever blocks
 * a sale/dispatch; an over-sell simply goes negative and surfaces a warning.
 *
 * Sale-out (S2) is whichever-first: a sale moves stock ONCE. An invoice and a
 * Πώληση δελτίο that are linked (delivery_notes.invoice_id) form one «sale
 * group»; the first of them to be issued records the −out, the second sees the
 * group already moved that product and skips. Unlinked documents are independent
 * (a standalone ΤΙΜ and a standalone ΔΑΠ for the same goods would each move —
 * the operator links them, or adjusts).
 */
class StockService
{
    /** Current on-hand for a tracked product (0.0 for untracked or no movements). */
    public function currentStock(Product $product): float
    {
        if (! $product->track_stock) {
            return 0.0;
        }

        return (float) $product->stockMovements()->sum('qty_change');
    }

    /**
     * Record one signed movement. $source is the document line that caused it
     * (invoice_line / delivery_note_line) or null for a manual entry.
     */
    public function record(
        Product $product,
        float $qtyChange,
        string $reason,
        ?Model $source = null,
        ?string $note = null,
        ?Carbon $occurredAt = null,
    ): StockMovement {
        return $product->stockMovements()->create([
            'company_id' => $product->company_id,
            'qty_change' => $qtyChange,
            'reason' => $reason,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'note' => $note,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }

    /**
     * Stock-OUT for a sale invoice (called when it becomes active). Per goods
     * line of a track_stock product: record −qty, UNLESS this exact line already
     * moved (idempotent re-fire) or the linked δελτίο already moved that product
     * (whichever-first). Credit notes are NOT handled here (they are a return =
     * stock-IN, S3).
     */
    public function recordSaleForInvoice(Invoice $invoice): void
    {
        if ($invoice->credited_invoice_id !== null) {
            return; // a credit note is a return (S3), never a sale-out
        }

        $linkedDeliveryLineIds = $this->linkedDeliveryLineIds($invoice);

        foreach ($invoice->lines()->get() as $line) {
            $product = $line->product;
            if (! $product || ! $product->track_stock) {
                continue;
            }
            if ($this->lineAlreadyMoved(InvoiceLine::class, $line->getKey())) {
                continue;
            }
            if ($this->groupMovedProduct(DeliveryNoteLine::class, $linkedDeliveryLineIds, (int) $product->id)) {
                continue;
            }
            $this->record($product, -(float) $line->qty, StockMovement::REASON_SALE, source: $line, occurredAt: $invoice->issued_at);
        }
    }

    /**
     * Stock-OUT for a Πώληση δελτίο (called on issue). Only move_purpose=1
     * (Πώληση) is a sale-out — every other purpose (ενδοδιακίνηση / σέρβις /
     * φύλαξη / επιστροφή) is NOT a stock-out (goods stay yours). Same idempotent
     * + whichever-first guards, keyed off the linked invoice.
     */
    public function recordSaleForDeliveryNote(DeliveryNote $note): void
    {
        if ((int) $note->move_purpose !== 1) {
            return; // only «Πώληση» reduces sellable stock
        }

        $linkedInvoiceLineIds = $note->invoice_id
            ? InvoiceLine::where('invoice_id', $note->invoice_id)->pluck('id')->all()
            : [];

        foreach ($note->lines()->get() as $line) {
            $product = $line->product;
            if (! $product || ! $product->track_stock) {
                continue;
            }
            if ($this->lineAlreadyMoved(DeliveryNoteLine::class, $line->getKey())) {
                continue;
            }
            if ($this->groupMovedProduct(InvoiceLine::class, $linkedInvoiceLineIds, (int) $product->id)) {
                continue;
            }
            $this->record($product, -(float) $line->qty, StockMovement::REASON_SALE, source: $line, occurredAt: $note->issued_at);
        }
    }

    /**
     * Stock-IN for a credit note becoming active (a return). Each line of a
     * track_stock product → +qty back into stock (credit-note lines are stored
     * POSITIVE). Idempotent per source line.
     */
    public function recordReturnForCreditNote(Invoice $creditNote): void
    {
        if ($creditNote->credited_invoice_id === null) {
            return; // not a credit note
        }

        foreach ($creditNote->lines()->get() as $line) {
            $product = $line->product;
            if (! $product || ! $product->track_stock) {
                continue;
            }
            if ($this->lineHasMovement(InvoiceLine::class, $line->getKey(), StockMovement::REASON_RETURN)) {
                continue;
            }
            $this->record($product, (float) $line->qty, StockMovement::REASON_RETURN, source: $line, occurredAt: $creditNote->issued_at);
        }
    }

    /**
     * Reverse the sale-OUT of a cancelled invoice (goods come back). Only lines
     * that THIS invoice actually moved as a sale are reversed (+qty,
     * REASON_CANCEL); idempotent (won't reverse twice). If the linked δελτίο
     * moved the product instead, this invoice's line has no sale movement → not
     * reversed here (a δελτίο-cancel reversal is a follow-up).
     */
    public function reverseSaleForInvoice(Invoice $invoice): void
    {
        if ($invoice->credited_invoice_id !== null) {
            return; // credit notes never produced a sale-out
        }

        foreach ($invoice->lines()->get() as $line) {
            $product = $line->product;
            if (! $product || ! $product->track_stock) {
                continue;
            }
            if (! $this->lineHasMovement(InvoiceLine::class, $line->getKey(), StockMovement::REASON_SALE)) {
                continue; // this line never moved (e.g. the linked δελτίο did) — nothing to reverse
            }
            if ($this->lineHasMovement(InvoiceLine::class, $line->getKey(), StockMovement::REASON_CANCEL)) {
                continue; // already reversed
            }
            $this->record($product, (float) $line->qty, StockMovement::REASON_CANCEL, source: $line, note: 'Αναστροφή ακύρωσης');
        }
    }

    /** @return list<int> line ids of δελτία linked to this invoice */
    private function linkedDeliveryLineIds(Invoice $invoice): array
    {
        $noteIds = DeliveryNote::where('invoice_id', $invoice->id)->pluck('id');

        return $noteIds->isEmpty()
            ? []
            : DeliveryNoteLine::whereIn('delivery_note_id', $noteIds)->pluck('id')->all();
    }

    // NOTE: the dedup/idempotency queries match `source_type` against the FQCN
    // (InvoiceLine::class / DeliveryNoteLine::class) because no morph map is
    // configured — `record()` stores the FQCN via getMorphClass(). If a
    // `Relation::enforceMorphMap([...])` is ever added, store + query must use the
    // SAME alias or these `where('source_type', FQCN)` filters silently stop
    // matching → double-counting.

    /** Has THIS exact source line already produced a sale movement? (idempotent re-fire) */
    private function lineAlreadyMoved(string $sourceType, int|string $sourceId): bool
    {
        return $this->lineHasMovement($sourceType, $sourceId, StockMovement::REASON_SALE);
    }

    /** Has THIS exact source line already produced a movement of the given reason? */
    private function lineHasMovement(string $sourceType, int|string $sourceId, string $reason): bool
    {
        return StockMovement::query()
            ->where('reason', $reason)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->exists();
    }

    /** Has the linked counterpart (sale group) already moved this product? (whichever-first) */
    private function groupMovedProduct(string $sourceType, array $sourceLineIds, int $productId): bool
    {
        if ($sourceLineIds === []) {
            return false;
        }

        return StockMovement::query()
            ->where('reason', StockMovement::REASON_SALE)
            ->where('product_id', $productId)
            ->where('source_type', $sourceType)
            ->whereIn('source_id', $sourceLineIds)
            ->exists();
    }
}
