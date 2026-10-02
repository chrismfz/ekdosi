<?php

namespace App\Support\Products;

/**
 * One rule for how an on-hand quantity LOOKS everywhere (products list, variants
 * tab, colour × size grid): the tone (red backorder / orange low-or-out / green)
 * and the trimmed number. A reorder threshold applies per sellable unit.
 */
class StockDisplay
{
    /** @return 'gray'|'danger'|'warning'|'success' */
    public static function tone(?float $onHand, ?float $reorderLevel = null): string
    {
        if ($onHand === null) {
            return 'gray';
        }
        if ($onHand < 0) {
            return 'danger';
        }
        $reorder = (float) ($reorderLevel ?? 0);

        return ($onHand <= 0 || ($reorder > 0 && $onHand <= $reorder)) ? 'warning' : 'success';
    }

    /** «12», «1.5», «−3» — up to 3 decimals, trailing zeros trimmed; «—» when untracked. */
    public static function format(?float $onHand): string
    {
        if ($onHand === null) {
            return '—';
        }

        return rtrim(rtrim(number_format($onHand, 3, '.', ''), '0'), '.');
    }
}
