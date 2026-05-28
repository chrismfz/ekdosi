<?php

namespace App\Services;

use App\Models\Invoice;

/**
 * Recompute invoice.net_total / gross_total from its persisted lines,
 * applying the header discount at the aggregate (post-sum) level —
 * matching legacy CALCULATE_INVOICE_VALUES + the header-discount math
 * in FAddInvoice.cpp:269.
 *
 * Extracted in PR #27 from CreateInvoice + EditInvoice where the same
 * formula was copy-pasted (PR #26 review surfaced the duplication).
 * Single source of truth: a regression in either page now touches only
 * this class.
 *
 * NOTE on rounding: line totals are ALREADY rounded to 2dp at line-
 * save time by InvoiceLine::saving. Summing 2dp values and applying
 * the discount factor then rounding ONCE is the legacy semantics —
 * NOT rounding intermediate per-rate buckets. Per-VAT-rate breakdown
 * (which DOES round per bucket) lives in InvoiceVatBreakdown, not
 * here. These are two different roll-ups with two different rounding
 * shapes; don't conflate them.
 */
class RecomputeInvoiceTotals
{
    public function __invoke(Invoice $invoice): Invoice
    {
        $fresh = $invoice->fresh(['lines']);

        // Defensive: if the invoice was deleted between caller and now
        // (e.g. a parallel DeleteAction), return the stale instance
        // unchanged rather than throwing. afterCreate/afterSave hooks
        // shouldn't blow up because of a delete race.
        if ($fresh === null) {
            return $invoice;
        }

        $rawNet = $fresh->lines->sum(fn ($l) => (float) $l->net_price);
        $rawGross = $fresh->lines->sum(fn ($l) => (float) $l->gross_price);
        $discountFactor = 1 - ((float) $fresh->header_discount_percent / 100);

        $fresh->net_total = round($rawNet * $discountFactor, 2);
        $fresh->gross_total = round($rawGross * $discountFactor, 2);
        $fresh->save();

        // Gross just changed → the money-status cache (owed/balance/
        // payment_status) is stale. Refresh it here so a header-discount
        // or line edit can't leave a wrong badge until the next payment
        // event. recompute() loads paymentMethod to avoid an N+1.
        app(InvoiceBalance::class)->recompute($fresh->loadMissing('paymentMethod'));

        return $fresh;
    }
}
