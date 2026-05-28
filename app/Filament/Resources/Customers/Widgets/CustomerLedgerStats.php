<?php

namespace App\Filament\Resources\Customers\Widgets;

use App\Filament\Widgets\Concerns\FormatsDashboardValues;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Top-of-Καρτέλα KPI band. Receives the (filter-independent) stats +
 * yearly blocks from the CustomerLedger page via @livewire props, so
 * it never re-queries — the page already computed everything once on
 * mount. Pure presentation: the math lives in CustomerLedgerBuilder.
 *
 * Rendered as a Filament StatsOverviewWidget (not hand-rolled Tailwind)
 * so it is styled by Filament's compiled CSS without a custom theme
 * build — the whole point of the polish pass.
 */
class CustomerLedgerStats extends StatsOverviewWidget
{
    use FormatsDashboardValues;

    /** @var array<string, mixed> */
    public array $ledgerStats = [];

    /** @var array<int, array<string, mixed>> */
    public array $ledgerYearly = [];

    protected ?string $heading = 'Σύνοψη';

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $s = $this->ledgerStats;
        $fmt = fn ($v): string => $this->eur((float) ($v ?? 0));
        $year = now()->year;

        // Sparkline of year-end balances, oldest → newest (yearly is
        // stored newest-first for the table, so reverse for the trend).
        $spark = array_map(
            fn ($r): float => (float) ($r['year_end_balance'] ?? 0),
            array_reverse($this->ledgerYearly),
        );

        $balance = (float) ($s['balance'] ?? 0);
        $balanceStat = Stat::make('Υπόλοιπο', $fmt($balance))
            ->color($balance > 0 ? 'danger' : 'success')
            ->descriptionIcon($balance > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
            ->description(
                ! empty($s['oldest_unpaid_days'])
                    ? 'Παλαιότερο ανεξόφλητο: '.$s['oldest_unpaid_days'].' ημ.'
                    : 'Χωρίς ανεξόφλητο υπόλοιπο',
            );

        if (count($spark) > 1) {
            $balanceStat->chart($spark);
        }

        $lastActivity = ! empty($s['last_activity_at'])
            ? Carbon::parse($s['last_activity_at'])->format('d/m/Y')
            : '—';

        // Year-over-year: compare this year's figures against last year's
        // for THIS customer. computeYearly already carries per-year
        // net/gross/paid; reuse the dashboard trend helpers for the
        // wording/icon/colour. trendText returns a percentage when there
        // is a baseline (else "νέα έσοδα"/"—"), so only suffix the year
        // when it's an actual percentage.
        $byYear = collect($this->ledgerYearly)->keyBy('year');
        $prevYear = $year - 1;
        $yearVal = fn (int $y, string $key): float => (float) ($byYear[$y][$key] ?? 0);
        $yoyDesc = function (string $key) use ($yearVal, $year, $prevYear): string {
            $text = $this->trendText($yearVal($year, $key), $yearVal($prevYear, $key));

            return str_contains($text, '%') ? $text.' vs '.$prevYear : $text;
        };
        $yoyIcon = fn (string $key): string => $this->trendIcon($yearVal($year, $key), $yearVal($prevYear, $key));
        $yoyColor = fn (string $key): string => $this->trendColor($yearVal($year, $key), $yearVal($prevYear, $key));

        return [
            Stat::make('Καθαρή αξία ('.$year.')', $fmt($s['ytd_net'] ?? 0))
                ->description($yoyDesc('net'))
                ->descriptionIcon($yoyIcon('net'))
                ->color($yoyColor('net')),
            Stat::make('Αξία με ΦΠΑ ('.$year.')', $fmt($s['ytd_gross'] ?? 0))
                ->description($yoyDesc('gross'))
                ->descriptionIcon($yoyIcon('gross'))
                ->color($yoyColor('gross')),
            Stat::make('Πληρωμές ('.$year.')', $fmt($s['ytd_paid'] ?? 0))
                ->description($yoyDesc('paid'))
                ->descriptionIcon($yoyIcon('paid'))
                ->color($yoyColor('paid')),
            Stat::make('Σύνολο τιμολογίων', (string) ($s['total_invoices_lifetime'] ?? 0))
                ->description('Συνολικά')
                ->descriptionIcon('heroicon-m-document-text')
                ->color('gray'),
            Stat::make('Τελευταία κίνηση', $lastActivity)
                ->descriptionIcon('heroicon-m-clock')
                ->color('gray'),
            $balanceStat,
        ];
    }
}
