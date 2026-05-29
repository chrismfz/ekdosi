<?php

namespace App\Filament\Widgets;

use App\Models\Company;
use App\Services\Dashboard\DashboardMetrics;
use App\Support\Dashboard\PeriodFilter;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Year-over-year cumulative net income: an anchor year vs the year
 * before it, as two cumulative lines over the 12 months. Lets the
 * operator eyeball "are we ahead of where we were a year ago". The
 * anchor year follows the dashboard period filter (its END year) so the
 * operator can compare any past year against its predecessor; default
 * is the current year, whose line flattens after the current month (no
 * future invoices yet) for a like-for-like read up to today.
 */
class YearComparisonChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 8;

    protected ?string $heading = 'Σύγκριση ετών (σωρευτικά καθαρά έσοδα)';

    protected function getData(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            return ['datasets' => [], 'labels' => []];
        }

        $metrics = new DashboardMetrics($tenant);
        $thisYear = PeriodFilter::fromState($this->pageFilters)->anchorYear();
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
