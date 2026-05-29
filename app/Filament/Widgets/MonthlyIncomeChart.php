<?php

namespace App\Filament\Widgets;

use App\Models\Company;
use App\Services\Dashboard\DashboardMetrics;
use App\Support\Dashboard\PeriodFilter;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Net income + output VAT per month over the trailing 12 months, as a
 * grouped bar chart. The dense series (zero-filled empty months) keeps
 * the x-axis continuous. Reacts to the dashboard period filter: the
 * 12-month window is anchored on the selected period's END month
 * (default = up to now).
 */
class MonthlyIncomeChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 7;

    protected ?string $heading = 'Έσοδα ανά μήνα (12 μήνες)';

    protected function getData(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            return ['datasets' => [], 'labels' => []];
        }

        $period = PeriodFilter::fromState($this->pageFilters);
        $rows = (new DashboardMetrics($tenant))->monthlyIncome(12, $period->end);

        return [
            'datasets' => [
                [
                    'label'           => 'Καθαρά',
                    'data'            => array_column($rows, 'net'),
                    'backgroundColor' => '#3b82f6',
                ],
                [
                    'label'           => 'ΦΠΑ εκροών',
                    'data'            => array_column($rows, 'vat'),
                    'backgroundColor' => '#f59e0b',
                ],
            ],
            'labels' => array_column($rows, 'label'),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
