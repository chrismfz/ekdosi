<?php

namespace App\Support;

/**
 * Single source of truth for Greek-style money rendering
 * (1.234,56 €) — comma decimal, dot thousands.
 *
 * Used by the dashboard stat widgets (via FormatsDashboardValues),
 * the Καρτέλα page + widgets, and the statement PDF. CSV export is
 * deliberately NOT a consumer: it needs a delimiter-safe numeric form
 * without the currency symbol.
 *
 * Also the one place money is COMPARED (differsByCent): a float
 * `abs(\$a - \$b) > 0.01` is magnitude-dependent (124.00 vs 123.99 lands just above
 * the threshold, 1240.00 vs 1239.99 just below), so an exact one-cent difference
 * would be a conflict at some totals and equal at others. Shared by the myDATA
 * reconciliation comparator and the per-invoice «Σύγκριση με ΑΑΔΕ» so the two
 * surfaces never disagree about the same pair of amounts.
 */
class Money
{
    /** Amounts more than this many cents apart are treated as different. */
    public const TOLERANCE_CENTS = 1;

    public static function eur(int|float|string|null $value): string
    {
        return number_format((float) ($value ?? 0), 2, ',', '.').' €';
    }

    public static function differsByCent(float $a, float $b): bool
    {
        return abs((int) round($a * 100) - (int) round($b * 100)) > self::TOLERANCE_CENTS;
    }
}
