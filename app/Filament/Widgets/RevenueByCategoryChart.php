<?php

namespace App\Filament\Widgets;

use App\Filament\Reports\Widgets\Concerns\FormatsReportChart;
use App\Models\Company;
use App\Services\Accounting\RevenueByCategory;
use App\Support\Dashboard\ReportPalette;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * «Έσοδα ανά κατηγορία» για το τρέχον έτος — καθαρά έσοδα ανά ekdosi
 * ProductCategory, top-N με τη μεγαλύτερη πρώτη (#8/#2 dashboard widget).
 * Read-only, tenant-scoped: reuses {@see RevenueByCategory} (η ίδια πηγή με την
 * αναφορά #2, live παραστατικά, ισοσκελίζει με τον τζίρο).
 *
 * RevenueByCategory::build υλοποιεί τις γραμμές δύο ετών σε PHP (current + prior,
 * για το YoY), οπότε το αποτέλεσμα cache-άρεται per tenant+year για 30' — όπως το
 * αδελφό TopProductsChart — για να μη ξανατρέχει σε κάθε φόρτωση του dashboard.
 */
class RevenueByCategoryChart extends ChartWidget
{
    use FormatsReportChart;

    protected static ?int $sort = 9;

    /** How many category bars to show (top-N by net). */
    private const TOP_N = 8;

    public function getHeading(): ?string
    {
        return 'Έσοδα ανά κατηγορία ('.now()->year.')';
    }

    /** Public so the shaping (filter + top-N-by-net) is unit-testable. */
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
            'labels' => array_map(fn (array $r): string => Str::limit((string) $r['name'], 24), $rows),
        ];
    }

    /**
     * The year's categories with POSITIVE net, top-N by revenue. build()'s row set
     * deliberately keeps zero/negative + prior-only buckets (to reconcile the
     * report's YoY columns); a bar chart of THIS year's revenue drops those and
     * ranks purely by net, so it stays a clean revenue picture. Cached per
     * tenant+year (the materialisation is the cost).
     *
     * @return list<array{name: string, net: float}>
     */
    private function topByNet(Company $tenant): array
    {
        $year = now()->year;

        return Cache::remember(
            "dash:rev-by-cat:{$tenant->getKey()}:{$year}",
            now()->addMinutes(30),
            function () use ($tenant, $year): array {
                $rows = array_filter(
                    app(RevenueByCategory::class)->build($tenant, $year)['rows'],
                    fn (array $r): bool => (float) $r['net'] > 0,
                );
                usort($rows, fn (array $a, array $b): int => $b['net'] <=> $a['net']);

                return array_map(
                    fn (array $r): array => ['name' => (string) $r['name'], 'net' => (float) $r['net']],
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
