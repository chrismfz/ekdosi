<?php

namespace App\Support\Dashboard;

use Illuminate\Support\Carbon;

/**
 * Resolves the Reports page filter state (two year Selects: the focus
 * year + the year to compare against) into concrete ints. Shared by the
 * page and every report widget so the "what year are we looking at"
 * semantics live in ONE place.
 *
 * Defaults: focus = current year, compare = the year before it. A blank
 * / unknown state falls back to those so a widget never renders against a
 * year(0) window.
 */
class ReportFilters
{
    /**
     * @param  array<string, mixed>|null  $filters
     */
    public static function year(?array $filters): int
    {
        $y = (int) ($filters['year'] ?? 0);

        return $y > 0 ? $y : (int) Carbon::now()->year;
    }

    /**
     * @param  array<string, mixed>|null  $filters
     */
    public static function compareYear(?array $filters): int
    {
        $c = (int) ($filters['compare_year'] ?? 0);

        return $c > 0 ? $c : self::year($filters) - 1;
    }
}
