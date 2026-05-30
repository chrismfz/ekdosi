<?php

namespace App\Filament\Reports\Widgets;

use App\Models\Company;
use App\Services\Dashboard\DashboardMetrics;
use App\Support\Dashboard\ReportFilters;
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
        $rows = (new DashboardMetrics($tenant))->monthlyForYear($year);

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
            'labels' => self::MONTHS,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
