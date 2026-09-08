<?php

namespace App\Services;

use App\Models\Quote;
use Illuminate\Support\Collection;

/**
 * Recomputes a quote's header totals (net / vat / gross) from its lines plus
 * the header-level discount — the quote twin of RecomputeInvoiceTotals, but
 * with NO InvoiceBalance / myDATA coupling (a quote carries no money state).
 *
 * Rounding follows the same discipline as the invoice path: line totals are
 * already 2dp (QuoteLine::saving); we sum them, apply the header discount,
 * and round ONCE at the aggregate — never between sub-sums.
 */
class QuoteTotals
{
    public function __invoke(Quote $quote): Quote
    {
        $fresh = $quote->fresh(['lines']);

        if ($fresh === null) {
            return $quote;
        }

        $rawNet = $fresh->lines->sum(fn ($l) => (float) $l->net_price);
        $rawGross = $fresh->lines->sum(fn ($l) => (float) $l->gross_price);
        $discountFactor = 1 - ((float) $fresh->header_discount_percent / 100);

        $net = round($rawNet * $discountFactor, 2);
        $gross = round($rawGross * $discountFactor, 2);

        $fresh->net_total = $net;
        $fresh->gross_total = $gross;
        $fresh->vat_total = round($gross - $net, 2);
        $fresh->save();

        return $fresh;
    }

    /**
     * Per-VAT-rate breakdown for display / a future PDF. Same grouping +
     * round-once-per-rate-after-discount logic as InvoiceVatBreakdown.
     *
     * @return list<array{rate: float, net: float, vat: float}>
     */
    public static function vatRows(Quote $quote): array
    {
        $quote->loadMissing('lines');
        $discountFactor = 1 - ((float) $quote->header_discount_percent / 100);

        return $quote->lines
            ->groupBy(fn ($line) => (string) $line->vat_percent)
            ->map(function (Collection $lines, $rateKey) use ($discountFactor) {
                $rawNet = $lines->sum(fn ($l) => (float) $l->net_price);
                $rawVat = $lines->sum(fn ($l) => (float) ($l->gross_price - $l->net_price));

                return [
                    'rate' => (float) $rateKey,
                    'net' => round($rawNet * $discountFactor, 2),
                    'vat' => round($rawVat * $discountFactor, 2),
                ];
            })
            ->sortBy('rate')
            ->values()
            ->all();
    }
}
