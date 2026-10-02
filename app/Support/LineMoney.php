<?php

namespace App\Support;

/**
 * THE line-money formulas — one place for every surface that prices a line
 * (InvoiceLine::saving, QuoteLine, the invoice form, the «Ταμείο»).
 *
 * Two anchors (POS-2, docs/BACKLOG.md):
 *  - NET-anchored (the default — every B2B/service line, the legacy
 *    FAddInvoice.cpp:269 math): the stored 2dp net unit price is the source;
 *    gross = round(net × (1+vat)). Some shelf prices are then unreachable
 *    (10,00 @24% → net 8,06 → 9,99).
 *  - GROSS-anchored (retail shelf prices): the VAT-inclusive unit price is the
 *    source; VAT is extracted from the line gross like a cash register does —
 *    vat = round(gross × vat/(100+vat)), net = gross − vat. The gross is exact
 *    (10,00 → 8,06 + 1,94). AADE validates only the SUMS (net/vat/gross totals),
 *    never vatAmount vs netValue × rate — sandbox-proven 2026-10-02 (AADE direct +
 *    InvoSign), incl. multi-line summaries whose VAT differs from Σnet × rate.
 *
 * Rounding: per line, 2dp, on the result only (never between sub-sums).
 */
final class LineMoney
{
    /**
     * @return array{net: float, gross: float}
     */
    public static function fromNet(float $qty, float $unitNet, float $discountPercent, float $vatPercent): array
    {
        $net = round($qty * $unitNet * (1 - $discountPercent / 100), 2);

        return ['net' => $net, 'gross' => round($net * (1 + $vatPercent / 100), 2)];
    }

    /**
     * @return array{net: float, gross: float}
     */
    public static function fromGross(float $qty, float $unitGross, float $discountPercent, float $vatPercent): array
    {
        $gross = round($qty * $unitGross * (1 - $discountPercent / 100), 2);
        $vat = round($gross * $vatPercent / (100 + $vatPercent), 2);

        return ['net' => round($gross - $vat, 2), 'gross' => $gross];
    }

    /** A unit price's VAT-inclusive mirror (display / the gross-edit field). */
    public static function grossFromNet(?float $net, ?float $vatPercent): ?float
    {
        return $net === null ? null : round($net * (1 + (float) $vatPercent / 100), 2);
    }

    /** A VAT-inclusive unit price's 2dp net (what a net-anchored line would store). */
    public static function netFromGross(?float $gross, ?float $vatPercent): ?float
    {
        return $gross === null ? null : round($gross / (1 + (float) $vatPercent / 100), 2);
    }
}
