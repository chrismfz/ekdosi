<?php

namespace App\Services\Accounting;

use App\Models\Company;
use App\Models\ProductCategory;
use App\Support\InvoiceScope;
use Illuminate\Support\Facades\DB;

/**
 * «Έσοδα ανά κατηγορία» (#2): net/VAT/gross revenue for a year, grouped by the
 * ekdosi business ProductCategory each invoice LINE resolves to, with the prior
 * year for a YoY delta and each category's % of turnover.
 *
 * Category resolution per line (matches the report's promise):
 *   1. `invoice_lines.product_category_id` — stamped at WHMCS ingestion from the
 *      income map (WHMCS lines have no product_id).
 *   2. else `products.product_category_id` — a manual/product-linked line.
 *   3. else «Αταξινόμητα» (bucket key 0) — nothing to group it by yet.
 *
 * Scope mirrors the other money surfaces: only LIVE (not cancelled locally or at
 * AADE) and issued (non-draft) invoices count; credit notes net NEGATIVE (they
 * reduce their category's revenue), by the same isCreditNote rule as InvoiceScope.
 * Turnover (net) is the document value, consistent with the yearly/turnover stats.
 */
class RevenueByCategory
{
    /**
     * @return array{
     *     year: int,
     *     rows: list<array{category_id: ?int, name: string, net: float, vat: float, gross: float, lines: int, pct: float, prior_net: float, delta: float}>,
     *     total_net: float, total_vat: float, total_gross: float, prior_total_net: float
     * }
     */
    public function build(Company $company, int $year): array
    {
        $current = $this->aggregate($company, $year);
        $prior = $this->aggregate($company, $year - 1);

        $totalNet = array_sum(array_column($current, 'net'));
        $priorTotalNet = array_sum(array_column($prior, 'net'));

        // Category names for the buckets present in either year (0 = «Αταξινόμητα»).
        $ids = array_values(array_filter(array_unique(array_merge(
            array_keys($current),
            array_keys($prior),
        )), fn ($id) => $id > 0));
        $names = ProductCategory::query()
            ->where('company_id', $company->getKey())
            ->whereIn('id', $ids)
            ->pluck('description_short', 'id')
            ->all();

        $rows = [];
        foreach ($current as $catId => $agg) {
            $priorNet = (float) ($prior[$catId]['net'] ?? 0.0);
            $rows[] = [
                'category_id' => $catId > 0 ? $catId : null,
                'name' => $catId > 0 ? (string) ($names[$catId] ?: ('#'.$catId)) : 'Αταξινόμητα',
                'net' => round($agg['net'], 2),
                'vat' => round($agg['vat'], 2),
                'gross' => round($agg['gross'], 2),
                'lines' => $agg['lines'],
                'pct' => $totalNet != 0.0 ? round($agg['net'] / $totalNet * 100, 1) : 0.0,
                'prior_net' => round($priorNet, 2),
                'delta' => round($agg['net'] - $priorNet, 2),
            ];
        }

        // Highest revenue first; «Αταξινόμητα» always sinks to the bottom.
        usort($rows, function (array $a, array $b): int {
            if (($a['category_id'] === null) !== ($b['category_id'] === null)) {
                return $a['category_id'] === null ? 1 : -1;
            }

            return $b['net'] <=> $a['net'];
        });

        return [
            'year' => $year,
            'rows' => $rows,
            'total_net' => round($totalNet, 2),
            'total_vat' => round(array_sum(array_column($current, 'vat')), 2),
            'total_gross' => round(array_sum(array_column($current, 'gross')), 2),
            'prior_total_net' => round($priorTotalNet, 2),
        ];
    }

    /**
     * One year's revenue folded into a category-id => {net, vat, gross, lines} map.
     * Bucket key 0 = «Αταξινόμητα» (no resolvable category).
     *
     * @return array<int, array{net: float, vat: float, gross: float, lines: int}>
     */
    private function aggregate(Company $company, int $year): array
    {
        $lines = DB::table('invoice_lines')
            ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
            ->leftJoin('products', 'products.id', '=', 'invoice_lines.product_id')
            ->where('invoices.company_id', $company->getKey())
            ->whereNull('invoices.deleted_at')
            ->whereYear('invoices.issued_at', $year)
            ->when(true, fn ($q) => InvoiceScope::live($q, 'invoices.'))
            ->when(true, fn ($q) => InvoiceScope::excludeUnissuedDrafts($q))
            ->select(
                'invoice_lines.product_category_id as line_cat',
                'products.product_category_id as product_cat',
                'invoice_lines.net_price',
                'invoice_lines.gross_price',
                'invoices.credited_invoice_id',
            )
            // A standalone legacy credit-type document (is_credit) also nets negative.
            ->selectRaw('EXISTS (SELECT 1 FROM invoice_types it WHERE it.id = invoices.invoice_type_id AND it.is_credit = 1) as is_credit_type')
            ->get();

        $agg = [];
        foreach ($lines as $l) {
            $isCreditNote = $l->credited_invoice_id !== null || (int) $l->is_credit_type === 1;
            $sign = $isCreditNote ? -1.0 : 1.0;
            $catId = (int) ($l->line_cat ?? $l->product_cat ?? 0);

            $net = (float) $l->net_price;
            $gross = (float) $l->gross_price;
            $agg[$catId] ??= ['net' => 0.0, 'vat' => 0.0, 'gross' => 0.0, 'lines' => 0];
            $agg[$catId]['net'] += $sign * $net;
            $agg[$catId]['vat'] += $sign * ($gross - $net);
            $agg[$catId]['gross'] += $sign * $gross;
            $agg[$catId]['lines']++;
        }

        return $agg;
    }
}
