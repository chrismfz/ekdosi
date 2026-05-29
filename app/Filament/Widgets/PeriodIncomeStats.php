<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\FormatsDashboardValues;
use App\Models\Company;
use App\Services\Dashboard\DashboardMetrics;
use App\Support\Dashboard\PeriodFilter;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Net / output-VAT / count for the period chosen in the dashboard's
 * period filter (PeriodFilter). Unlike the fixed headline cards, these
 * retune live as the operator switches month / quarter / year / custom
 * range — the "how much did we bill in <any period>" lever. Sits just
 * above the charts, which react to the same filter.
 */
class PeriodIncomeStats extends StatsOverviewWidget
{
    use FormatsDashboardValues;
    use InteractsWithPageFilters;

    protected static ?int $sort = 6;

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            return [];
        }

        $period = PeriodFilter::fromState($this->pageFilters);
        $fig = (new DashboardMetrics($tenant))->income($period->start, $period->end);

        return [
            Stat::make('Έσοδα περιόδου (καθαρά)', $this->eur($fig->net))
                ->description($period->label)
                ->descriptionIcon('heroicon-m-funnel')
                ->color('info'),

            Stat::make('ΦΠΑ περιόδου (εκροών)', $this->eur($fig->vat))
                ->description('Μικτά: '.$this->eur($fig->gross).' • '.$period->label)
                ->descriptionIcon('heroicon-m-receipt-percent')
                ->color('warning'),

            Stat::make('Παραστατικά περιόδου', (string) $fig->count)
                ->description($period->label)
                ->descriptionIcon('heroicon-m-document-text')
                ->color('primary'),
        ];
    }
}
