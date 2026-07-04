<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\ReturnInvoiceExtra;
use App\Support\InvoiceScope;

/**
 * MON-1 (AUDIT): recomputes `return_invoice_extras.qty_returned` for an
 * original invoice's lines from its LIVE credit notes — the same
 * "cache = Σ of live contributions" discipline InvoiceBalance uses for
 * credited_total, applied to returned quantities.
 *
 * A line's qty_returned becomes Σ(qty of credit-note lines linked via
 * `original_line_id` whose credit note is LIVE — not cancelled locally or at
 * AADE). So cancelling a credit note (local OR AADE) frees the quantity it had
 * returned, and the original can be re-credited — the exact flow that was
 * bricked before (cancel a wrong credit note → re-issue).
 *
 * ETL-SAFE: only lines that carry at least one NATIVE credit-note line
 * (original_line_id set) are rewritten. Legacy-imported returns (tracked
 * per-line by the old app, with no per-credit-line link) never get an
 * original_line_id, so their qty_returned is left exactly as imported.
 */
class RecomputeReturnedQuantities
{
    public function __invoke(Invoice $original): void
    {
        $original->loadMissing('lines');
        $lineIds = $original->lines->pluck('id');
        if ($lineIds->isEmpty()) {
            return;
        }

        // Original lines that have ANY native credit-note line (live or
        // cancelled) — the only ones we own and may rewrite.
        $ownedLineIds = InvoiceLine::query()
            ->whereIn('original_line_id', $lineIds)
            ->distinct()
            ->pluck('original_line_id');

        if ($ownedLineIds->isEmpty()) {
            return;
        }

        // Σ(qty) of LIVE credit-note lines, grouped by the original line.
        $liveSums = InvoiceLine::query()
            ->whereIn('original_line_id', $ownedLineIds)
            ->whereHas('invoice', fn ($q) => InvoiceScope::live(
                $q->whereNotNull('credited_invoice_id')
            ))
            ->groupBy('original_line_id')
            ->selectRaw('original_line_id, SUM(qty) as total')
            ->pluck('total', 'original_line_id');

        foreach ($ownedLineIds as $lineId) {
            $total = round((float) ($liveSums[$lineId] ?? 0), 3);

            $extra = ReturnInvoiceExtra::query()->firstOrNew(['invoice_line_id' => $lineId]);
            $extra->company_id = $original->company_id;
            $extra->qty_returned = $total;
            $extra->save();
        }
    }
}
