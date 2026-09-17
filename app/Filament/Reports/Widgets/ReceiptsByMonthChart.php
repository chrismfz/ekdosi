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
 * Εισπράξεις (money actually collected, net of refunds) per month, the
 * focus year vs the comparison year — grouped bars so the operator spots
 * the seasonally weak months (a slow summer) at a glance and knows roughly
 * what to expect this year. The CASH twin of RevenueByMonthChart's
 * turnover: keyed on pay_date, not issue date.
 */
class ReceiptsByMonthChart extends ChartWidget
{
    use FormatsReportChart;
    use InteractsWithPageFilters;

    protected static ?int $sort = 3;

    private const MONTHS = ['Ιαν', 'Φεβ', 'Μάρ', 'Απρ', 'Μάι', 'Ιούν', 'Ιούλ', 'Αύγ', 'Σεπ', 'Οκτ', 'Νοέ', 'Δεκ'];

    public function getHeading(): ?string
    {
        return 'Εισπράξεις ανά μήνα — '.ReportFilters::year($this->pageFilters)
            .' vs '.ReportFilters::compareYear($this->pageFilters);
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
                    'data' => $metrics->receiptsByMonth($year),
                    'backgroundColor' => ReportPalette::POSITIVE,
                ],
                [
                    'label' => (string) $compare,
                    'data' => $metrics->receiptsByMonth($compare),
                    'backgroundColor' => ReportPalette::MUTED,
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
