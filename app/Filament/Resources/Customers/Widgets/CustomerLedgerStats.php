<?php

namespace App\Filament\Resources\Customers\Widgets;

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
        $fmt = fn ($v): string => number_format((float) ($v ?? 0), 2, ',', '.').' €';
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

        return [
            Stat::make('Καθαρή αξία ('.$year.')', $fmt($s['ytd_net'] ?? 0))
                ->description('Τρέχον έτος')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('gray'),
            Stat::make('Αξία με ΦΠΑ ('.$year.')', $fmt($s['ytd_gross'] ?? 0))
                ->description('Τρέχον έτος')
                ->descriptionIcon('heroicon-m-receipt-percent')
                ->color('gray'),
            Stat::make('Πληρωμές ('.$year.')', $fmt($s['ytd_paid'] ?? 0))
                ->description('Τρέχον έτος')
                ->descriptionIcon('heroicon-m-arrow-down-circle')
                ->color('success'),
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
