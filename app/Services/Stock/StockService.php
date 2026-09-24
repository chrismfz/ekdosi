<?php

namespace App\Services\Stock;

use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\ReturnInvoiceExtra;
use App\Models\Scopes\CompanyScope;
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
                // Already sold-out once. If a later cancel compensated it and the
                // invoice is now REVIVED, re-apply the sale (undo the compensation);
                // otherwise this is a plain idempotent re-fire → nothing to do.
                $this->reapplyOnRevive($product, InvoiceLine::class, $line);

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
                // Already returned-in once. If a later cancel compensated it and the
                // credit note is now REVIVED, re-apply the return (undo the
                // compensation); otherwise a plain idempotent re-fire → no-op.
                $this->reapplyOnRevive($product, InvoiceLine::class, $line);

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
    /** @return array<int, float> what THIS call reversed, per product id (+qty back in) */
    public function reverseSaleForInvoice(Invoice $invoice): array
    {
        $reversed = [];
        if ($invoice->credited_invoice_id !== null) {
            return $reversed; // credit notes never produced a sale-out
        }

        foreach ($invoice->lines()->get() as $line) {
            $product = $line->product;
            if (! $product || ! $product->track_stock) {
                continue;
            }
            if (! $this->lineHasMovement($product->company_id, InvoiceLine::class, $line->getKey(), StockMovement::REASON_SALE)) {
                continue; // this line never moved (e.g. the linked δελτίο did) — nothing to reverse
            }
            if ($this->isCompensated($product->company_id, InvoiceLine::class, $line->getKey())) {
                continue; // already reversed (net) — a revive would zero this before a re-cancel
            }

            $returned = (float) (ReturnInvoiceExtra::query()
                ->where('invoice_line_id', $line->getKey())
                ->value('qty_returned') ?? 0);
            $reverseQty = (float) $line->qty - $returned;
            if ($reverseQty <= 0) {
                continue; // already fully returned via credit note(s) — nothing left to reverse
            }

            $this->record($product, $reverseQty, StockMovement::REASON_CANCEL, source: $line, note: 'Αναστροφή ακύρωσης');
            $reversed[(int) $product->getKey()] = ($reversed[(int) $product->getKey()] ?? 0.0) + $reverseQty;
        }

        return $reversed;
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
            if ($this->isCompensated($product->company_id, DeliveryNoteLine::class, $line->getKey())) {
                continue; // already reversed (net)
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
            if ($this->isCompensated($product->company_id, InvoiceLine::class, $line->getKey())) {
                continue; // already reversed (net) — a revive would zero this before a re-cancel
            }

            $this->record($product, -(float) $line->qty, StockMovement::REASON_CANCEL, source: $line, note: 'Αναστροφή ακύρωσης πιστωτικού');
        }
    }

    /**
     * «Μετατροπή σε φορολογικό», the fiscal document is ISSUED: it takes over the
     * stock-out its informal source still holds. Recorded as explicit
     * REASON_CONVERSION movements (+qty) sourced on THIS fiscal, so the informal's
     * sale is offset and the fiscal then moves its own full quantity through the
     * normal recordSaleForInvoice() — one delivery, one stock-out; a changed
     * quantity, a δελτίο linked to the fiscal, a credit note, cancel / revive all
     * run on the fiscal's own lines like any invoice.
     *
     * Idempotent (a fiscal that already holds a transfer takes nothing more), and
     * only what is still AVAILABLE: the informal's standing sale minus what other
     * (non-deleted) conversions of it hold — a reissue of a credited conversion
     * therefore takes nothing and simply sells again, as a reissue does.
     */
    public function transferSaleFromConvertedSource(Invoice $fiscal): void
    {
        $source = $this->convertedSource($fiscal);
        if ($source === null || $source->local_status !== 'active') {
            return;
        }
        $companyId = $fiscal->company_id;
        if ($this->conversionHeld($companyId, [(int) $fiscal->getKey()]) !== []) {
            return; // already took over (re-fire)
        }

        $standing = [];
        foreach ($source->lines()->get() as $line) {
            $product = $line->product;
            if (! $product || ! $product->track_stock
                || $this->isCompensated($companyId, InvoiceLine::class, $line->getKey())) {
                continue;
            }
            $moved = -(float) StockMovement::query()
                ->where('company_id', $companyId)
                ->where('reason', StockMovement::REASON_SALE)
                ->where('source_type', InvoiceLine::class)
                ->where('source_id', $line->getKey())
                ->sum('qty_change');
            if ($moved > 0) {
                $standing[$product->getKey()] = ['product' => $product, 'qty' => ($standing[$product->getKey()]['qty'] ?? 0.0) + $moved];
            }
        }
        if ($standing === []) {
            return;
        }

        $siblings = Invoice::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('converted_from_invoice_id', $source->getKey())
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $held = $this->conversionHeld($companyId, $siblings);

        foreach ($standing as $productId => $row) {
            $available = $row['qty'] - ($held[$productId] ?? 0.0);
            if ($available > 0.0005) {
                $this->record($row['product'], $available, StockMovement::REASON_CONVERSION, source: $fiscal,
                    note: 'Μετατροπή από '.$source->invcode, occurredAt: $fiscal->issued_at);
            }
        }
    }

    /**
     * The conversion is CANCELLED: the goods its cancel actually gave back
     * ($reversed — what reverseSaleForInvoice() just returned) are still delivered
     * by the informal source, so that much of what the fiscal took over is handed
     * back — never more. A draft that took nothing over, or a fiscal whose goods
     * already came back through a credit note (or were moved by a linked δελτίο
     * that still stands), hands back nothing.
     *
     * @param  array<int, float>  $reversed
     */
    public function restoreSaleToConvertedSource(Invoice $fiscal, array $reversed): void
    {
        if ($fiscal->converted_from_invoice_id === null) {
            return;
        }
        foreach ($this->conversionHeld($fiscal->company_id, [(int) $fiscal->getKey()]) as $productId => $held) {
            $qty = min($held, $reversed[$productId] ?? 0.0);
            $product = $qty > 0.0005 ? Product::query()->withoutGlobalScope(CompanyScope::class)->find($productId) : null;
            if ($product !== null) {
                $this->record($product, -$qty, StockMovement::REASON_CONVERSION, source: $fiscal, note: 'Ακύρωση μετατροπής');
            }
        }
    }

    /**
     * Net REASON_CONVERSION held per product by these fiscal invoices (non-zero only).
     *
     * @param  list<int>  $fiscalIds
     * @return array<int, float>
     */
    private function conversionHeld(int|string $companyId, array $fiscalIds): array
    {
        if ($fiscalIds === []) {
            return [];
        }

        return StockMovement::query()
            ->where('company_id', $companyId)
            ->where('reason', StockMovement::REASON_CONVERSION)
            ->where('source_type', (new Invoice)->getMorphClass())
            ->whereIn('source_id', $fiscalIds)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(qty_change) as held')
            ->pluck('held', 'product_id')
            ->map(fn ($v) => (float) $v)
            ->filter(fn (float $v) => abs($v) > 0.0005)
            ->all();
    }

    private function convertedSource(Invoice $fiscal): ?Invoice
    {
        return $fiscal->converted_from_invoice_id === null ? null : Invoice::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->withTrashed()
            ->where('company_id', $fiscal->company_id)
            ->find($fiscal->converted_from_invoice_id);
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

    /**
     * The outstanding cancel-compensation for a source line: Σ of its REASON_CANCEL
     * (recorded on a document cancel) and REASON_REVIVE (recorded on a revive)
     * movements. Zero ⇒ the line's sale/return currently stands; non-zero ⇒ it is
     * compensated (the document is cancelled). Net-based (not existence-keyed) so a
     * cancel → revive → re-cancel cycle stays idempotent — each revive brings the
     * balance back to zero, freeing the next cancel to compensate afresh.
     */
    private function compensationBalance(int|string $companyId, string $sourceType, int|string $sourceId): float
    {
        return (float) StockMovement::query()
            ->where('company_id', $companyId)
            ->whereIn('reason', [StockMovement::REASON_CANCEL, StockMovement::REASON_REVIVE])
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->sum('qty_change');
    }

    /** Is this source line's sale/return currently compensated by a cancel? (net, not existence) */
    private function isCompensated(int|string $companyId, string $sourceType, int|string $sourceId): bool
    {
        return abs($this->compensationBalance($companyId, $sourceType, $sourceId)) > 0.00001;
    }

    /**
     * Re-apply a line's sale/return when its document is REVIVED (Επαναφορά). If a
     * prior cancel compensated the line (balance ≠ 0), record the exact opposite as
     * a REASON_REVIVE so the net returns to the original sale/return magnitude and a
     * later re-cancel starts from a zero balance. A no-op when nothing is
     * compensated (a plain draft→active re-fire never touched the compensation
     * ledger). The compensation is undone in full — the cancel amount itself
     * (`qty − qty_returned` for a sale, `qty` for a return) is preserved by
     * mirroring whatever was recorded, so the qty_returned/remainder logic on the
     * cancel side is never re-derived here.
     *
     * Stamped at now() (not the document's issue date): a revive is a present-time
     * event undoing the equally-present-time cancel, so a date-ordered / as-of-date
     * view of the ledger reads SALE@issue → CANCEL@cancel → REVIVE@revive instead of
     * a phantom doubled sale back at the issue date.
     */
    private function reapplyOnRevive(Product $product, string $sourceType, Model $line): void
    {
        $balance = $this->compensationBalance($product->company_id, $sourceType, $line->getKey());
        if (abs($balance) < 0.00001) {
            return;
        }
        $this->record($product, -$balance, StockMovement::REASON_REVIVE, source: $line, note: 'Επαναφορά');
    }
}
