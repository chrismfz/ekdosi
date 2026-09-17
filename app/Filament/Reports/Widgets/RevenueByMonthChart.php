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
 * Net income + output VAT per month for the year selected on the Reports
 * page — the "πώς πήγε κάθε μήνας φέτος" bar chart. Non-cumulative
 * (DashboardMetrics::monthlyForYear), dense 12-month series.
 */
class RevenueByMonthChart extends ChartWidget
{
    use FormatsReportChart;
    use InteractsWithPageFilters;

    protected static ?int $sort = 2;

    private const MONTHS = ['Ιαν', 'Φεβ', 'Μάρ', 'Απρ', 'Μάι', 'Ιούν', 'Ιούλ', 'Αύγ', 'Σεπ', 'Οκτ', 'Νοέ', 'Δεκ'];

    public function getHeading(): ?string
    {
        return 'Τζίρος ανά μήνα — '.ReportFilters::year($this->pageFilters);
    }

    protected function getData(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            return ['datasets' => [], 'labels' => []];
        }

        $year = ReportFilters::year($this->pageFilters);
        $rows = DashboardMetricsCache::for($tenant)->monthlyForYear($year);

        return [
            'datasets' => [
                [
                    'label' => 'Καθαρά',
                    'data' => array_column($rows, 'net'),
                    'backgroundColor' => ReportPalette::PRIMARY,
                ],
                [
                    'label' => 'ΦΠΑ εκροών',
                    'data' => array_column($rows, 'vat'),
                    'backgroundColor' => ReportPalette::WARNING,
                ],
            ],
            'labels' => self::MONTHS,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
