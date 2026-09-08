<?php

namespace App\Filament\Reports\Widgets;

use App\Models\Company;
use App\Services\Dashboard\DashboardMetrics;
use App\Support\Dashboard\ReportFilters;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * Θερμικός χάρτης μήνα×έτους (net turnover) — the seasonality at a glance:
 * which months are consistently strong/weak across the last 4 years. A
 * colour-scaled HTML table (not a JS chart) so it renders reliably without
 * a Chart.js matrix plugin. Anchored on the focus year (its last 4 years).
 */
class SeasonalityHeatmap extends Widget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 9;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.reports.seasonality-heatmap';

    private const MONTHS = ['Ιαν', 'Φεβ', 'Μάρ', 'Απρ', 'Μάι', 'Ιούν', 'Ιούλ', 'Αύγ', 'Σεπ', 'Οκτ', 'Νοέ', 'Δεκ'];

    /**
     * @return array{years: list<int>, matrix: array<int, array<int, float>>, max: float, months: list<string>}
     */
    public function getHeatmap(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            return ['years' => [], 'matrix' => [], 'max' => 0.0, 'months' => self::MONTHS];
        }

        $year = ReportFilters::year($this->pageFilters);
        $data = (new DashboardMetrics($tenant))->netByMonthMatrix(4, $year);
        $data['months'] = self::MONTHS;

        return $data;
    }
}
