<?php

namespace App\Filament\Widgets;

use App\Models\Company;
use App\Services\Dashboard\DashboardMetrics;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

/**
 * Year-over-year cumulative net income: this year vs last year, as two
 * cumulative lines over the 12 months. Lets the operator eyeball "are
 * we ahead of where we were a year ago" — the closest honest thing to
 * an income projection without a forecasting model. The current year's
 * line flattens after the current month (no future invoices yet), so
 * the comparison is like-for-like up to today.
 */
class YearComparisonChart extends ChartWidget
{
    protected static ?int $sort = 7;

    protected ?string $heading = 'Σύγκριση ετών (σωρευτικά καθαρά έσοδα)';

    protected function getData(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            return ['datasets' => [], 'labels' => []];
        }

        $metrics = new DashboardMetrics($tenant);
        $thisYear = (int) now()->year;
        $lastYear = $thisYear - 1;

        return [
            'datasets' => [
                [
                    'label'       => (string) $thisYear,
                    'data'        => $metrics->cumulativeNetByMonth($thisYear),
                    'borderColor' => '#3b82f6',
                    'fill'        => false,
                ],
                [
                    'label'       => (string) $lastYear,
                    'data'        => $metrics->cumulativeNetByMonth($lastYear),
                    'borderColor' => '#9ca3af',
                    'fill'        => false,
                ],
            ],
            'labels' => ['Ιαν', 'Φεβ', 'Μάρ', 'Απρ', 'Μάι', 'Ιούν', 'Ιούλ', 'Αύγ', 'Σεπ', 'Οκτ', 'Νοέ', 'Δεκ'],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
