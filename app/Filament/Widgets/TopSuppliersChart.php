<?php

namespace App\Filament\Widgets;

use App\Filament\Reports\Widgets\Concerns\FormatsReportChart;
use App\Models\Company;
use App\Models\Expense;
use App\Support\Dashboard\ReportPalette;
use App\Support\MyData\Codes;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * «Κορυφαίοι προμηθευτές» για το τρέχον έτος κατά καθαρή αξία εξόδων (#8 dashboard
 * widget). Read-only, tenant-scoped, gated στο δικαίωμα των Εξόδων.
 *
 * Ανά γραμμή ακολουθεί ΤΟ ΙΔΙΟ reportable-expense treatment με το
 * `LedgerBook::expenseRows` (ώστε το ποσό ανά προμηθευτή να συμφωνεί με το
 * Βιβλίο): εξαιρεί AADE-ακυρωμένα (`mydata_state='CANCELLED'`), αντιστρέφει το
 * πρόσημο για πιστωτικά (`Codes::CREDIT_NOTE_TYPES`), και ταυτοποιεί τον
 * προμηθευτή id-aware (`supplier?->name ?? supplier_name`). ΔΕΝ είναι reconciling
 * σύνολο: είναι top-N προμηθευτών με ΘΕΤΙΚΟ καθαρό (μηδενικοί/αρνητικοί =
 * refund-only παραλείπονται ως μπάρες, όπως και το `RevenueByCategoryChart`), και
 * έξοδα χωρίς ταυτότητα προμηθευτή δεν προσμετρώνται. Η άθροιση με πρόσημο ανά
 * γραμμή γίνεται σε PHP, οπότε cache-άρεται 30' ανά tenant+year.
 */
class TopSuppliersChart extends ChartWidget
{
    use FormatsReportChart;

    protected static ?int $sort = 11;

    /** How many bars to show (top-N by net spend). */
    private const TOP_N = 8;

    public static function canView(): bool
    {
        return Filament::getTenant() instanceof Company
            && (bool) auth()->user()?->can('ViewAny:Expense');
    }

    public function getHeading(): ?string
    {
        return 'Κορυφαίοι προμηθευτές — έξοδα ('.now()->year.')';
    }

    /** Public so the shaping is unit-testable without mounting the chart JS. */
    public function getData(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            return ['datasets' => [], 'labels' => []];
        }

        $rows = $this->topByNet($tenant);

        return [
            'datasets' => [[
                'label' => 'Καθαρά έξοδα',
                'data' => array_map(fn (array $r): float => $r['net'], $rows),
                'backgroundColor' => ReportPalette::WARNING,
            ]],
            'labels' => array_map(fn (array $r): string => Str::limit($r['label'], 28), $rows),
        ];
    }

    /**
     * Per-supplier signed-net spend for the year, top-N by net. Mirrors
     * LedgerBook::expenseRows (cancelled excluded, credit-note sign, id-aware
     * supplier). Cached 30' per tenant+year (PHP materialisation).
     *
     * @return list<array{label: string, net: float}>
     */
    private function topByNet(Company $tenant): array
    {
        $year = now()->year;

        return Cache::remember(
            "dash:top-suppliers:{$tenant->getKey()}:{$year}",
            now()->addMinutes(30),
            function () use ($tenant, $year): array {
                $creditTypes = Codes::CREDIT_NOTE_TYPES;
                $buckets = [];

                Expense::query()
                    ->where('company_id', $tenant->getKey())
                    ->whereNotNull('issue_date')
                    ->whereYear('issue_date', $year)
                    ->where(fn ($q) => $q->whereNull('mydata_state')->orWhere('mydata_state', '!=', 'CANCELLED'))
                    ->with('supplier:id,name')
                    ->get(['id', 'supplier_id', 'supplier_name', 'invoice_type', 'net_total', 'mydata_state'])
                    ->each(function (Expense $exp) use (&$buckets, $creditTypes): void {
                        // No supplier identity at all (no link + blank name) → not
                        // attributable to a supplier, so it doesn't belong in a
                        // «top suppliers» ranking (avoids a merged phantom «—» bar).
                        $name = trim((string) ($exp->supplier?->name ?: $exp->supplier_name));
                        if ($exp->supplier_id === null && $name === '') {
                            return;
                        }
                        $key = $exp->supplier_id !== null
                            ? 'id:'.$exp->supplier_id
                            : 'name:'.mb_strtolower($name);
                        $label = $name !== '' ? $name : '—';
                        $sign = in_array($exp->invoice_type, $creditTypes, true) ? -1 : 1;

                        $buckets[$key] ??= ['label' => $label, 'net' => 0.0];
                        $buckets[$key]['net'] += $sign * (float) $exp->net_total;
                    });

                $rows = array_values(array_filter(
                    array_map(fn (array $b): array => ['label' => $b['label'], 'net' => round($b['net'], 2)], $buckets),
                    fn (array $r): bool => $r['net'] > 0,
                ));
                usort($rows, fn (array $a, array $b): int => $b['net'] <=> $a['net']);

                return array_slice($rows, 0, self::TOP_N);
            },
        );
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
