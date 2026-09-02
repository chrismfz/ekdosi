<?php

namespace App\Services\Stock;

use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\ReturnInvoiceExtra;
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
            if ($this->lineAlreadyMoved($product->company_id, InvoiceLine::class, $line->getKey())) {
                continue;
            }
            if ($this->groupMovedProduct($product->company_id, DeliveryNoteLine::class, $linkedDeliveryLineIds, (int) $product->id)) {
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
            if ($this->lineAlreadyMoved($product->company_id, DeliveryNoteLine::class, $line->getKey())) {
                continue;
            }
            if ($this->groupMovedProduct($product->company_id, InvoiceLine::class, $linkedInvoiceLineIds, (int) $product->id)) {
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
            if ($this->lineHasMovement($product->company_id, InvoiceLine::class, $line->getKey(), StockMovement::REASON_RETURN)) {
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
     *
     * CRITICAL: reverse only the UN-RETURNED remainder (`qty − qty_returned`). If
     * a credit note already returned some/all of the line (return-IN recorded +
     * `return_invoice_extras.qty_returned` set), reversing the full qty on top
     * would double-count and silently inflate stock (the cancel can come via the
     * unguarded myDATA path even when a credit note exists). A line already fully
     * returned reverses nothing.
     *
     * The mirror case — cancelling the CREDIT NOTE itself — is handled by
     * reverseReturnForCreditNote() (STOCK-001): it reverses the return-IN, while
     * RecomputeReturnedQuantities (MON-1) frees `qty_returned` from the now-cancelled
     * credit note (LIVE-scoped Σ). The two move together, so a
     * cancel-the-credit-note-then-cancel-the-invoice sequence nets correctly — this
     * reversal then sees the freed remainder and reverses the full sale.
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
            if (! $this->lineHasMovement($product->company_id, InvoiceLine::class, $line->getKey(), StockMovement::REASON_SALE)) {
                continue; // this line never moved (e.g. the linked δελτίο did) — nothing to reverse
            }
            if ($this->lineHasMovement($product->company_id, InvoiceLine::class, $line->getKey(), StockMovement::REASON_CANCEL)) {
                continue; // already reversed
            }

            $returned = (float) (ReturnInvoiceExtra::query()
                ->where('invoice_line_id', $line->getKey())
                ->value('qty_returned') ?? 0);
            $reverseQty = (float) $line->qty - $returned;
            if ($reverseQty <= 0) {
                continue; // already fully returned via credit note(s) — nothing left to reverse
            }

            $this->record($product, $reverseQty, StockMovement::REASON_CANCEL, source: $line, note: 'Αναστροφή ακύρωσης');
        }
    }

    /**
     * Reverse the sale-OUT of a cancelled Πώληση δελτίο (goods come back) — the
     * delivery-note twin of reverseSaleForInvoice(). Per line of a track_stock
     * product: reverse ONLY a line that THIS note actually moved as a sale (+qty,
     * REASON_CANCEL); idempotent (won't reverse twice). If the LINKED invoice moved
     * the product instead (whichever-first), this note's line has no sale movement
     * → not reversed here, and cancelling the invoice reverses it symmetrically.
     *
     * No `qty_returned` remainder logic (unlike the invoice path): a δελτίο has no
     * credit notes returning against its lines, so the whole moved quantity is the
     * reversible quantity. Keyed off the DeliveryNoteLine's own REASON_SALE
     * movement, so a move_purpose≠1 note (which never recorded a sale-out) is a
     * natural no-op. STOCK-001.
     *
     * Called from DeliveryLifecycleService::persistCancellation (local + provider
     * cancel) and — being idempotent — reused by a reconciliation-driven remote
     * cancellation (MYD-019).
     */
    public function reverseSaleForDeliveryNote(DeliveryNote $note): void
    {
        foreach ($note->lines()->get() as $line) {
            $product = $line->product;
            if (! $product || ! $product->track_stock) {
                continue;
            }
            if (! $this->lineHasMovement($product->company_id, DeliveryNoteLine::class, $line->getKey(), StockMovement::REASON_SALE)) {
                continue; // this note never moved it (e.g. the linked invoice did) — nothing to reverse
            }
            if ($this->lineHasMovement($product->company_id, DeliveryNoteLine::class, $line->getKey(), StockMovement::REASON_CANCEL)) {
                continue; // already reversed
            }

            $this->record($product, (float) $line->qty, StockMovement::REASON_CANCEL, source: $line, note: 'Αναστροφή ακύρωσης δελτίου');
        }
    }

    /**
     * Reverse the return-IN of a cancelled credit note (the returned goods did not
     * actually come back) — the mirror of recordReturnForCreditNote(). Per line
     * that produced a return movement → −qty back out (REASON_CANCEL); idempotent
     * (won't reverse twice).
     *
     * This MUST move together with the `qty_returned` bookkeeping. When a credit
     * note is cancelled, RecomputeReturnedQuantities (MON-1) frees the returned
     * quantity (LIVE-scoped Σ, run from the observer's recomputeOriginal), so a
     * LATER cancel of the ORIGINAL invoice reverses the FULL sale again. WITHOUT
     * reversing the return-IN here, that combination silently inflates stock (sell
     * 3, credit 3, cancel the credit note, cancel the invoice → +3 counted twice =
     * 13 instead of 10). Reversing the return-IN keeps the ledger consistent with
     * the freed `qty_returned`. STOCK-001.
     */
    public function reverseReturnForCreditNote(Invoice $creditNote): void
    {
        if ($creditNote->credited_invoice_id === null) {
            return; // not a credit note — it never produced a return-IN
        }

        // If the ORIGINAL invoice is itself cancelled, its sale-reversal already
        // owns the net: reverseSaleForInvoice reversed `qty − qty_returned`, i.e. it
        // deliberately did NOT reverse the part this credit note returned, counting
        // on the return to stand. Undoing the return here on top would strand that
        // deferred sale portion and UNDERSTATE stock — and it made the two cancel
        // orders disagree (cancel-invoice-then-credit vs credit-then-invoice). Skip
        // when the original is cancelled; when it is still live we DO reverse (the
        // return is simply undone, the sale stands). Keyed on local_status='cancelled'
        // — the exact predicate that gated reverseSaleForInvoice — so an AADE-only
        // cancel of the ORIGINAL (mydata_state CANCELLED, local_status still active,
        // sale NOT reversed) still reverses here, correctly.
        //
        // Two P2 edges of this premise are parked in docs/BACKLOG.md (STOCK-001):
        // (a) if a linked δελτίο (not the invoice) owned the sale-out, the
        // invoice-cancel reversed nothing, so skipping here can overstate; and the
        // return-reversal fires on the credit note's local_status transition, which
        // is narrower than the qty_returned-free predicate (InvoiceScope::live).
        // Neither is reachable through today's in-app cancel paths (both choke-points
        // sync local_status + mydata_state together, and the δελτίο-first-with-credit
        // combination is an already-inconsistent business state).
        $original = Invoice::find($creditNote->credited_invoice_id);
        if ($original !== null && $original->local_status === 'cancelled') {
            return;
        }

        foreach ($creditNote->lines()->get() as $line) {
            $product = $line->product;
            if (! $product || ! $product->track_stock) {
                continue;
            }
            if (! $this->lineHasMovement($product->company_id, InvoiceLine::class, $line->getKey(), StockMovement::REASON_RETURN)) {
                continue; // this line never returned (untracked at the time, etc.) — nothing to reverse
            }
            if ($this->lineHasMovement($product->company_id, InvoiceLine::class, $line->getKey(), StockMovement::REASON_CANCEL)) {
                continue; // already reversed
            }

            $this->record($product, -(float) $line->qty, StockMovement::REASON_CANCEL, source: $line, note: 'Αναστροφή ακύρωσης πιστωτικού');
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
    private function lineAlreadyMoved(int|string $companyId, string $sourceType, int|string $sourceId): bool
    {
        return $this->lineHasMovement($companyId, $sourceType, $sourceId, StockMovement::REASON_SALE);
    }

    /** Has THIS exact source line already produced a movement of the given reason? */
    private function lineHasMovement(int|string $companyId, string $sourceType, int|string $sourceId, string $reason): bool
    {
        return StockMovement::query()
            // Explicit tenant filter: these helpers run from the InvoiceObserver
            // (queued/sync, NO ambient CompanyContext), so the global scope is a
            // no-op here. source_id is a globally-unique surrogate PK so this was
            // never a leak — but scope it anyway (defense-in-depth + survives any
            // future strict tenant mode). The caller always has the product's
            // company_id (the same value record() stamps on the movement).
            ->where('company_id', $companyId)
            ->where('reason', $reason)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->exists();
    }

    /** Has the linked counterpart (sale group) already moved this product? (whichever-first) */
    private function groupMovedProduct(int|string $companyId, string $sourceType, array $sourceLineIds, int $productId): bool
    {
        if ($sourceLineIds === []) {
            return false;
        }

        return StockMovement::query()
            ->where('company_id', $companyId)
            ->where('reason', StockMovement::REASON_SALE)
            ->where('product_id', $productId)
            ->where('source_type', $sourceType)
            ->whereIn('source_id', $sourceLineIds)
            ->exists();
    }
}
