<?php

namespace App\Filament\Support;

use Carbon\Carbon;

/**
 * Period presets for the «Άντληση από myDATA» expenses picker (Έξοδα list).
 *
 * The picker defaults to the current quarter, but when that's empty the operator
 * needs to widen the window without leaving the modal — «δεν βρήκα κάτι, φέρε μου
 * όλη τη χρονιά». Discrete CALENDAR presets (no free-form dates, so a live
 * re-fetch fires exactly once per pick, never on every keystroke). Arbitrary
 * ranges still live on the full Κονσόλα myDATA — Έξοδα.
 */
class ExpensePickerWindow
{
    /** @return array<string, string> preset key => Greek label */
    public static function options(): array
    {
        return [
            'quarter' => 'Τρέχον τρίμηνο',
            'prev_quarter' => 'Προηγούμενο τρίμηνο',
            'half' => 'Τρέχον εξάμηνο',
            'prev_half' => 'Προηγούμενο εξάμηνο',
            'year' => 'Τρέχον έτος',
            'prev_year' => 'Προηγούμενο έτος',
        ];
    }

    /**
     * Resolve a preset into a concrete [from, to] window. Current periods run up
     * to today; past periods are the full calendar span. Built from startOfYear +
     * addMonths (never a month() setter) so a 31-day «now» can't overflow a
     * shorter target month.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function resolve(string $period): array
    {
        $now = Carbon::now();
        $firstHalf = $now->month <= 6;

        return match ($period) {
            'prev_quarter' => [
                $now->copy()->subQuarter()->startOfQuarter(),
                $now->copy()->subQuarter()->endOfQuarter(),
            ],
            'half' => $firstHalf
                ? [$now->copy()->startOfYear(), $now->copy()]                       // H1: 1 Ιαν → σήμερα
                : [$now->copy()->startOfYear()->addMonths(6), $now->copy()],        // H2: 1 Ιουλ → σήμερα
            'prev_half' => $firstHalf
                ? [                                                                 // προηγ. = Β' πέρσι
                    $now->copy()->subYear()->startOfYear()->addMonths(6),
                    $now->copy()->subYear()->endOfYear(),
                ]
                : [                                                                 // προηγ. = Α' φέτος
                    $now->copy()->startOfYear(),
                    $now->copy()->startOfYear()->addMonths(5)->endOfMonth(),
                ],
            'year' => [$now->copy()->startOfYear(), $now->copy()],
            'prev_year' => [
                $now->copy()->subYear()->startOfYear(),
                $now->copy()->subYear()->endOfYear(),
            ],
            default => [$now->copy()->startOfQuarter(), $now->copy()], // quarter
        };
    }
}
