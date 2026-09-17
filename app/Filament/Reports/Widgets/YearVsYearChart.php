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
 * Cumulative net income, the focus year vs ANY chosen comparison year
 * (both Reports-page selectors) — "are we ahead of where we were". The
 * current year's line flattens after the current month (no future
 * invoices) for a like-for-like read up to today.
 */
class YearVsYearChart extends ChartWidget
{
    use FormatsReportChart;
    use InteractsWithPageFilters;

    protected static ?int $sort = 4;

    private const MONTHS = ['Ιαν', 'Φεβ', 'Μάρ', 'Απρ', 'Μάι', 'Ιούν', 'Ιούλ', 'Αύγ', 'Σεπ', 'Οκτ', 'Νοέ', 'Δεκ'];

    public function getHeading(): ?string
    {
        return ReportFilters::year($this->pageFilters).' vs '
            .ReportFilters::compareYear($this->pageFilters).' (σωρευτικά καθαρά)';
    }

    protected function getData(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            return ['datasets' => [], 'labels' => []];
        }

        $metrics = DashboardMetricsCache::for($tenant);
        $year = ReportFilters::year($this->pageFilters);
        $compare = ReportFilters::compareYear($this->pageFilters);

        return [
            'datasets' => [
                [
                    'label' => (string) $year,
                    'data' => $metrics->cumulativeNetByMonth($year),
                    'borderColor' => ReportPalette::PRIMARY,
                    'fill' => false,
                ],
                [
                    'label' => (string) $compare,
                    'data' => $metrics->cumulativeNetByMonth($compare),
                    'borderColor' => ReportPalette::MUTED,
                    'fill' => false,
                ],
            ],
            'labels' => self::MONTHS,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
