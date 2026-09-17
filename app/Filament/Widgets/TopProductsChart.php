<?php

namespace App\Filament\Widgets;

use App\Filament\Reports\Widgets\Concerns\FormatsReportChart;
use App\Filament\Widgets\Concerns\HasChartEmptyNote;
use App\Models\Company;
use App\Services\CustomerLedger\CustomerTopProducts;
use App\Support\Dashboard\ReportPalette;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * «Κορυφαία είδη/υπηρεσίες» για το τρέχον έτος κατά καθαρή αξία (#8 dashboard
 * widget). Reuses {@see CustomerTopProducts::forCompany()} (live sales only,
 * credit notes + unissued drafts excluded), tenant-scoped.
 *
 * forCompany() materialises the year's lines in PHP (WHMCS lines have no
 * product_id → grouped by normalised description), so the result is cached per
 * tenant+year for 30' — a «top είδη» overview tolerates that staleness, and it
 * keeps the dashboard render cheap.
 */
class TopProductsChart extends ChartWidget
{
    use FormatsReportChart;
    use HasChartEmptyNote;

    protected static ?int $sort = 10;

    /** Shown (with the blank canvas hidden) when there's nothing to plot. */
    protected ?string $emptyStateHeading = 'Καμία πώληση προς εμφάνιση φέτος.';

    /** How many bars to show (top-N by net). */
    private const TOP_N = 8;

    public function getHeading(): ?string
    {
        return 'Κορυφαία είδη/υπηρεσίες — έσοδα ('.now()->year.')';
    }

    /** Public so the shaping (sort-by-net + top-N) is unit-testable. */
    public function getData(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            return ['datasets' => [], 'labels' => []];
        }

        $rows = $this->topByNet($tenant);

        return [
            'datasets' => [[
                'label' => 'Καθαρά έσοδα',
                'data' => array_map(fn (array $r): float => (float) $r['net'], $rows),
                'backgroundColor' => ReportPalette::PRIMARY,
            ]],
            'labels' => array_map(fn (array $r): string => Str::limit((string) $r['label'], 28), $rows),
        ];
    }

    /**
     * The year's products re-ranked by net revenue, top-N. Cached per tenant+year
     * (the materialisation is the expensive part). forCompany's own ordering is by
     * frequency; a money chart wants highest-revenue first, so we re-sort here.
     *
     * @return list<array{label: string, net: float}>
     */
    private function topByNet(Company $tenant): array
    {
        $year = now()->year;

        return Cache::remember(
            "dash:top-products:{$tenant->getKey()}:{$year}",
            now()->addMinutes(30),
            function () use ($tenant): array {
                // Fetch ALL buckets (not a frequency-ranked slice) so a high-value,
                // low-frequency item — a one-off big project billed once — can't be
                // dropped before we re-rank by net. forCompany materialises the whole
                // period regardless of limit, so PHP_INT_MAX only skips its final
                // frequency-slice; we then sort by net and keep the top-N. Full
                // calendar year (endOfYear) to match RevenueByCategory's whereYear.
                $rows = (new CustomerTopProducts)->forCompany(
                    $tenant,
                    now()->startOfYear(),
                    now()->endOfYear(),
                    PHP_INT_MAX,
                );
                usort($rows, fn (array $a, array $b): int => $b['net'] <=> $a['net']);

                return array_map(
                    fn (array $r): array => ['label' => (string) $r['label'], 'net' => (float) $r['net']],
                    array_slice($rows, 0, self::TOP_N),
                );
            },
        );
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
