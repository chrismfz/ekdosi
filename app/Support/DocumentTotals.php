<?php

namespace App\Support;

/**
 * Header totals of a document (invoice / quote) from its line totals — the ONE
 * header-discount roll-up shared by the persisted recompute (RecomputeInvoiceTotals,
 * QuoteTotals) and the form's live «Σύνολα» preview, so what the operator sees while
 * typing is what gets saved.
 *
 * Legacy semantics (FAddInvoice.cpp:269): line totals are 2dp (LineMoney); sum them,
 * apply the header discount at the aggregate, round ONCE — never between sub-sums.
 * Per-VAT-rate rounding is a different roll-up (InvoiceVatBreakdown), not this one.
 */
final class DocumentTotals
{
    /**
     * @return array{net: float, gross: float, vat: float}
     */
    public static function applyHeaderDiscount(float $rawNet, float $rawGross, float $headerDiscountPercent): array
    {
        $factor = 1 - $headerDiscountPercent / 100;
        $net = round($rawNet * $factor, 2);
        $gross = round($rawGross * $factor, 2);

        return ['net' => $net, 'gross' => $gross, 'vat' => round($gross - $net, 2)];
    }

    /**
     * Live preview from the lines repeater's (unsaved, client-typed) state. Each line
     * is priced exactly like the model's `saving` hook (a `gross_unit_price` anchor →
     * fromGross, else fromNet); a line without a positive qty is skipped (it can't be
     * saved anyway). Discounts are reported on the NET, like the net columns.
     *
     * @param  iterable<mixed>|null  $lines
     * @return array{lines: int, list_net: float, line_discount: float, header_discount: float, net: float, gross: float, vat: float}
     */
    public static function fromFormLines(?iterable $lines, mixed $headerDiscountPercent): array
    {
        $count = 0;
        $listNet = $rawNet = $rawGross = 0.0;

        foreach ($lines ?? [] as $line) {
            if (! is_array($line)) {
                continue;
            }
            $qty = self::num($line['qty'] ?? null);
            if ($qty <= 0) {
                continue;
            }
            $vat = self::num($line['vat_percent'] ?? null);
            $discount = min(100.0, max(0.0, self::num($line['discount'] ?? null)));
            $anchor = $line['gross_unit_price'] ?? null;

            if (is_numeric($anchor)) {
                $full = LineMoney::fromGross($qty, (float) $anchor, 0, $vat);
                $money = LineMoney::fromGross($qty, (float) $anchor, $discount, $vat);
            } else {
                $unit = self::num($line['price_per_item'] ?? null);
                $full = LineMoney::fromNet($qty, $unit, 0, $vat);
                $money = LineMoney::fromNet($qty, $unit, $discount, $vat);
            }

            $count++;
            $listNet += $full['net'];
            $rawNet += $money['net'];
            $rawGross += $money['gross'];
        }

        // Same bounds the header field enforces (0 ≤ x < 100).
        $headerDiscount = min(99.99, max(0.0, self::num($headerDiscountPercent)));
        $totals = self::applyHeaderDiscount($rawNet, $rawGross, $headerDiscount);

        return [
            'lines' => $count,
            'list_net' => round($listNet, 2),
            'line_discount' => round($listNet - $rawNet, 2),
            'header_discount' => round($rawNet - $totals['net'], 2),
        ] + $totals;
    }

    private static function num(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
