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
 */
class Money
{
    public static function eur(int|float|string|null $value): string
    {
        return number_format((float) ($value ?? 0), 2, ',', '.').' €';
    }
}
