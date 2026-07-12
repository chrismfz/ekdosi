<?php

namespace App\Filament\Reports\Widgets;

use App\Models\Company;
use App\Services\Dashboard\DashboardMetrics;
use App\Support\Dashboard\ReportFilters;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Total net turnover per year over the trailing 5 years (ending on the
 * selected focus year) — the long-run growth view. The focus year's bar
 * is highlighted so it reads against its neighbours at a glance.
 */
class RevenueByYearChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 5;

    protected ?string $heading = 'Συνολικός τζίρος ανά έτος (καθαρά)';

    protected function getData(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            return ['datasets' => [], 'labels' => []];
        }

        $year = ReportFilters::year($this->pageFilters);
        $rows = (new DashboardMetrics($tenant))->yearlyTotals(5, $year);

        // Highlight the focus year (amber) vs the rest (blue).
        $colors = array_map(
            fn (array $r): string => $r['year'] === $year ? '#f59e0b' : '#3b82f6',
            $rows,
        );

        return [
            'datasets' => [
                [
                    'label' => 'Καθαρά',
                    'data' => array_column($rows, 'net'),
                    'backgroundColor' => $colors,
                ],
            ],
            'labels' => array_map(fn (array $r): string => (string) $r['year'], $rows),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
