<?php

namespace App\Filament\Reports\Widgets;

use App\Filament\Reports\Widgets\Concerns\FormatsReportChart;
use App\Models\Company;
use App\Support\Dashboard\DashboardMetricsCache;
use App\Support\Dashboard\ReportFilters;
use App\Support\Dashboard\ReportPalette;
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
    use FormatsReportChart;
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
        $rows = DashboardMetricsCache::for($tenant)->yearlyTotals(5, $year);

        // Highlight the focus year (amber) vs the rest (blue).
        $colors = array_map(
            fn (array $r): string => $r['year'] === $year ? ReportPalette::WARNING : ReportPalette::PRIMARY,
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
