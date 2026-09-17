<?php

namespace App\Filament\Reports\Widgets;

use App\Models\Company;
use App\Support\Dashboard\DashboardMetricsCache;
use App\Support\Dashboard\ReportFilters;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * ΦΠΑ εκροών ανά συντελεστή × τρίμηνο για το επιλεγμένο έτος — the helper an
 * operator (or their accountant) reads to fill the periodic ΦΠΑ return:
 * φορολογητέα βάση (καθαρά) + ΦΠΑ per rate, per quarter, net of credit notes.
 * A plain HTML table (not a JS chart) — it's a figures grid, not a trend.
 * Βοηθητικό, όχι επίσημη δήλωση (see DashboardMetrics::vatByRateByQuarter).
 */
class VatByRateQuarterTable extends Widget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.reports.vat-by-rate-quarter';

    /**
     * @return array{
     *   year: int,
     *   rates: list<float>,
     *   quarters: array<int, array{rates: array<string, array{net: float, vat: float}>, net: float, vat: float}>,
     *   totals: array{rates: array<string, array{net: float, vat: float}>, net: float, vat: float},
     *   rateKey: callable
     * }
     */
    public function getVatTable(): array
    {
        $tenant = Filament::getTenant();
        $year = ReportFilters::year($this->pageFilters);

        $data = $tenant instanceof Company
            ? DashboardMetricsCache::for($tenant)->vatByRateByQuarter($year)
            : ['year' => $year, 'rates' => [], 'quarters' => [], 'totals' => ['rates' => [], 'net' => 0.0, 'vat' => 0.0]];

        // Same 2dp key the metric buckets rates by, so the view can look up cells.
        $data['rateKey'] = fn (float $r): string => number_format($r, 2, '.', '');

        return $data;
    }
}
