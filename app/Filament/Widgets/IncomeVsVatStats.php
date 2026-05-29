<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\FormatsDashboardValues;
use App\Models\Company;
use App\Services\Dashboard\DashboardMetrics;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * "What we billed vs what we owe" for the two periods an operator
 * reconciles against: the previous full month and the current quarter
 * to date. VAT shown is OUTPUT VAT only (we don't track expense/input
 * VAT) — labelled so it isn't mistaken for the net ΦΠΑ liability.
 */
class IncomeVsVatStats extends StatsOverviewWidget
{
    use FormatsDashboardValues;

    protected static ?int $sort = 2;

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            return [];
        }

        $metrics = new DashboardMetrics($tenant);
        $now = now();

        $prevMonth = $metrics->income(
            $now->copy()->subMonthNoOverflow()->startOfMonth(),
            $now->copy()->subMonthNoOverflow()->endOfMonth(),
        );

        $quarter = $metrics->income($now->copy()->startOfQuarter(), $now->copy()->endOfQuarter());

        return [
            Stat::make('Προηγ. μήνας — Έσοδα', $this->eur($prevMonth->net))
                ->description('ΦΠΑ εκροών: '.$this->eur($prevMonth->vat))
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('gray'),

            Stat::make('Τρίμηνο — Έσοδα', $this->eur($quarter->net))
                ->description('ΦΠΑ εκροών: '.$this->eur($quarter->vat))
                ->descriptionIcon('heroicon-m-calendar')
                ->color('gray'),
        ];
    }
}
