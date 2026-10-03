<?php

namespace App\Filament\PosStats\Widgets;

use App\Filament\PosStats\Widgets\Concerns\ReadsPosFilters;
use App\Services\Pos\PosStats;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The till scorecard: today vs the same weekday last week; the chosen month vs the
 * same month of the comparison year; the year so far vs the SAME span of the
 * comparison year (like-for-like); receipts + average basket; returns.
 */
class PosStatsKpis extends StatsOverviewWidget
{
    use ReadsPosFilters;

    /** No auto-refresh (Filament's default is every 5s — each poll re-reads up to two years of receipts). */
    protected ?string $pollingInterval = null;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $stats = $this->stats();
        if ($stats === null) {
            return [];
        }
        $year = $this->year();
        $compare = $this->compareYear();
        $month = $this->month();
        $today = CarbonImmutable::today();

        $todayNow = $stats->totals($today, $today);
        $todayBefore = $stats->totals($today->subWeek(), $today->subWeek());

        $monthStart = CarbonImmutable::create($year, $month, 1);
        $monthNow = $stats->totals($monthStart, $monthStart->endOfMonth());
        $monthBefore = $stats->totals($monthStart->setYear($compare), $monthStart->setYear($compare)->endOfMonth());

        // Year to date, LIKE FOR LIKE: if either year is the running one, both are cut at
        // today's date (in their own year — 29/2 → 28/2 in a common year); else whole years.
        $span = in_array($today->year, [$year, $compare], true) ? $today : CarbonImmutable::create($today->year, 12, 31);
        $ytdEnd = self::sameDay($year, $span);
        $yearNow = $stats->totals(CarbonImmutable::create($year, 1, 1), $ytdEnd);
        $yearBefore = $stats->totals(CarbonImmutable::create($compare, 1, 1), self::sameDay($compare, $span));

        $monthName = $monthStart->locale('el')->translatedFormat('F');

        return [
            $this->stat('Σήμερα', $todayNow['net'], $todayBefore['net'], 'vs '.$today->subWeek()->locale('el')->translatedFormat('l d/m'), $todayNow['receipts'].' αποδείξεις'),
            $this->stat($monthName.' '.$year, $monthNow['net'], $monthBefore['net'], 'vs '.$monthName.' '.$compare, $monthNow['receipts'].' αποδείξεις'),
            $this->stat($span->isSameDay($today) ? 'Έτος '.$year.' ως '.$ytdEnd->format('d/m') : 'Έτος '.$year, $yearNow['net'], $yearBefore['net'], 'vs ίδιο διάστημα '.$compare, $yearNow['receipts'].' αποδείξεις'),
            Stat::make('Μέσο καλάθι ('.$monthName.')', Money::eur($monthNow['avg_basket']))
                ->description(self::deltaText(PosStats::delta($monthNow['avg_basket'], $monthBefore['avg_basket']), 'vs '.Money::eur($monthBefore['avg_basket'])))
                ->color('gray'),
            Stat::make('Επιστροφές ('.$monthName.')', Money::eur($monthNow['refunds']))
                ->description($monthNow['returns'].' πιστωτικά · '.($monthNow['sales'] > 0 ? number_format(100 * $monthNow['refunds'] / $monthNow['sales'], 1, ',', '.') : '0').'% των πωλήσεων')
                ->color($monthNow['sales'] > 0 && $monthNow['refunds'] / $monthNow['sales'] > 0.1 ? 'warning' : 'gray'),
        ];
    }

    private function stat(string $label, float $now, float $before, string $versus, string $extra): Stat
    {
        $delta = PosStats::delta($now, $before);

        return Stat::make($label, Money::eur($now))
            ->description(self::deltaText($delta, $versus.' ('.Money::eur($before).')').' · '.$extra)
            ->descriptionIcon($delta === null ? 'heroicon-m-minus' : ($delta >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down'))
            ->color($delta === null ? 'gray' : ($delta >= 0 ? 'success' : 'danger'));
    }

    /** The same month/day in another year, clamped (29/2 → 28/2) — never spilling into the next month. */
    private static function sameDay(int $year, CarbonImmutable $date): CarbonImmutable
    {
        return CarbonImmutable::create($year, $date->month, min($date->day, CarbonImmutable::create($year, $date->month, 1)->daysInMonth));
    }

    private static function deltaText(?float $delta, string $versus): string
    {
        return ($delta === null ? '—' : ($delta >= 0 ? '+' : '−').number_format(abs($delta), 1, ',', '.').'%').' '.$versus;
    }
}
