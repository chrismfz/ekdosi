<?php

namespace App\Support\Dashboard;

use Illuminate\Support\Carbon;

/**
 * Resolves the dashboard's period-filter state (the Select + optional
 * custom date range on the Dashboard page) into a concrete [start, end]
 * window + a Greek label.
 *
 * Shared by every filter-DRIVEN widget (PeriodIncomeStats, the two
 * charts) so the period semantics live in ONE place. The FIXED headline
 * cards (IncomeStatsOverview / IncomeVsVatStats) deliberately do NOT use
 * this — they encode their own comparison windows.
 */
class PeriodFilter
{
    public function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly string $label,
    ) {}

    /**
     * @param  array<string, mixed>|null  $filters  The page's `filters`
     *         state. Null / unknown → current month (the safe default).
     */
    public static function fromState(?array $filters): self
    {
        $now = Carbon::now();
        $period = $filters['period'] ?? 'this_month';

        return match ($period) {
            'last_month' => new self(
                $now->copy()->subMonthNoOverflow()->startOfMonth(),
                $now->copy()->subMonthNoOverflow()->endOfMonth(),
                'Προηγ. μήνας',
            ),
            'quarter' => new self(
                $now->copy()->startOfQuarter(),
                $now->copy()->endOfQuarter(),
                'Τρέχον τρίμηνο',
            ),
            'year' => new self(
                $now->copy()->startOfYear(),
                $now->copy()->endOfYear(),
                (string) $now->year,
            ),
            'custom' => self::custom($filters, $now),
            default => new self(
                $now->copy()->startOfMonth(),
                $now->copy()->endOfMonth(),
                'Τρέχων μήνας',
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private static function custom(array $filters, Carbon $now): self
    {
        $from = ! empty($filters['from'])
            ? Carbon::parse($filters['from'])->startOfDay()
            : $now->copy()->startOfMonth();
        $to = ! empty($filters['to'])
            ? Carbon::parse($filters['to'])->endOfDay()
            : $now->copy()->endOfMonth();

        // Operator entered the dates backwards — swap rather than return
        // an empty window.
        if ($to->lt($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return new self($from, $to, $from->format('d/m/Y').' – '.$to->format('d/m/Y'));
    }

    /**
     * The year a year-anchored widget (the YoY chart) should treat as
     * "current" for the selected period — the period's END year.
     */
    public function anchorYear(): int
    {
        return (int) $this->end->year;
    }
}
