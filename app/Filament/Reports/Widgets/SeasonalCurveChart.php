<?php

namespace App\Filament\Reports\Widgets;

use App\Models\Company;
use App\Services\Dashboard\DashboardMetrics;
use App\Support\Dashboard\ReportFilters;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Εποχικότητα: the average monthly turnover shape over the three years
 * before the focus year, with the focus year overlaid — "πότε ανεβαίνει
 * και πότε πέφτει ο τζίρος, και πώς πάμε φέτος σε σχέση με το συνηθισμένο".
 */
class SeasonalCurveChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 7;

    private const MONTHS = ['Ιαν', 'Φεβ', 'Μάρ', 'Απρ', 'Μάι', 'Ιούν', 'Ιούλ', 'Αύγ', 'Σεπ', 'Οκτ', 'Νοέ', 'Δεκ'];

    public function getHeading(): ?string
    {
        $year = ReportFilters::year($this->pageFilters);

        return "Εποχικότητα — μέση καμπύλη ({$year}−3…{$year}−1) vs {$year}";
    }

    protected function getData(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            return ['datasets' => [], 'labels' => []];
        }

        $metrics = new DashboardMetrics($tenant);
        $year = ReportFilters::year($this->pageFilters);

        // Average shape from the 3 completed years before the focus year.
        $profile = $metrics->seasonalProfile(3, $year - 1);
        $avg = array_values($profile['monthlyAvg']);   // [1..12] → 0-indexed

        $current = array_column($metrics->monthlyForYear($year), 'net');

        return [
            'datasets' => [
                [
                    'label' => 'Μέση εποχική (καθαρά)',
                    'data' => $avg,
                    'borderColor' => '#9ca3af',
                    'borderDash' => [6, 4],
                    'fill' => false,
                ],
                [
                    'label' => (string) $year,
                    'data' => $current,
                    'borderColor' => '#3b82f6',
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
