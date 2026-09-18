<?php

namespace App\Services\Accounting;

use App\Models\Company;
use App\Models\ProductCategory;
use App\Support\Accounting\ItemLabelNormalizer;
use App\Support\InvoiceScope;
use Illuminate\Support\Facades\DB;

/**
 * «Ισοζύγιο Ειδών/Υπηρεσιών» (#4) — net/VAT/gross turnover for a year grouped by
 * the ITEM each invoice line sold, with the prior year for a YoY delta and each
 * item's % of turnover. The item-grain sibling of {@see RevenueByCategory} (#2):
 * SAME scope + header-discount + credit-note math, so Σ(items) reconciles with
 * Σ(categories) and with the yearly turnover — only the grouping axis differs.
 *
 * Item identity per line:
 *   1. `invoice_lines.product_id` — a catalogue-linked line → key «p:{id}», label
 *      from the product (description_short → description).
 *   2. else the free-text `product_descr`, NORMALISED — key «d:{lower(clean)}».
 *      {@see ItemLabelNormalizer} strips the trailing billing-period date-range so
 *      every renewal of the same package folds into one row (WHMCS lines have no
 *      product_id and embed the period in the text). Display-only — the παραστατικό
 *      keeps its exact description.
 *   3. else (no product, blank description) — key «x:», label «(χωρίς περιγραφή)».
 *
 * Each row also carries the item's dominant ekdosi ProductCategory (by net) so the
 * report visibly rolls up to #2, plus the (sign-netted) quantity + its unit.
 *
 * Scope mirrors the other money surfaces: only LIVE (not cancelled locally or at
 * AADE) and issued (non-draft) invoices; credit notes net NEGATIVE (same isCreditNote
 * rule as InvoiceScope) — so quantity and value both reflect returns. Turnover (net)
 * is the document value (header discount applied per line).
 *
 * The per-line query + sign + header-discount math is intentionally kept identical to
 * {@see RevenueByCategory::aggregate()} (the two differ only in the grouping axis).
 * That duplication is guarded by RevenueByItemTest::test_totals_reconcile_with_revenue_by_category
 * — if either service's money/scope rule ever drifts, that test goes red. Folding the
 * shared per-line computation into one helper is a tracked cleanup (docs/BACKLOG.md #4).
 */
class RevenueByItem
{
    /**
     * @return array{
     *     year: int,
     *     rows: list<array{key: string, label: string, product_id: ?int, sku: ?string, category_id: ?int, category: string, qty: ?float, unit: ?string, net: float, vat: float, gross: float, lines: int, pct: float, prior_net: float, delta: float}>,
     *     total_net: float, total_vat: float, total_gross: float, prior_total_net: float
     * }
     */
    public function build(Company $company, int $year): array
    {
        $current = $this->aggregate($company, $year);
        $prior = $this->aggregate($company, $year - 1);

        $totalNet = array_sum(array_map(fn (array $b): float => $b['net'], $current));
        $priorTotalNet = array_sum(array_map(fn (array $b): float => $b['net'], $prior));

        // Category names for the dominant category each present item rolls up to —
        // from BOTH years, so a prior-only row resolves its category name too.
        $catIds = [];
        foreach ([$current, $prior] as $set) {
            foreach ($set as $b) {
                if ($b['category_id'] !== null) {
                    $catIds[$b['category_id']] = true;
                }
            }
        }
        $names = ProductCategory::query()
            ->where('company_id', $company->getKey())
            ->whereIn('id', array_keys($catIds))
            ->pluck('description_short', 'id')
            ->all();

        // Union of item keys present in EITHER year, so an item sold only last year
        // still shows a row and the «Πέρσι»/delta columns reconcile to the footer.
        $keys = array_values(array_unique(array_merge(array_keys($current), array_keys($prior))));

        $rows = [];
        foreach ($keys as $key) {
            $agg = $current[$key] ?? null;
            $priorNet = (float) ($prior[$key]['net'] ?? 0.0);

            // No current-year activity AND a zero prior-year net = a bucket carrying
            // no information (e.g. a prior item fully credited, nothing since) → skip
            // rather than emit a confusing all-zero row. A current-year net of 0 that
            // HAS lines (an item washed by a same-year credit) still shows.
            if ($agg === null && round($priorNet, 2) === 0.0) {
                continue;
            }

            // Prior-only item (no current bucket): borrow its identity AND its
            // resolved category from last year, else the row wrongly reads
            // «Αταξινόμητα» though its lines had a real category. A CURRENT item that
            // is genuinely unclassified this year keeps null — it must NOT inherit
            // last year's category (that would mislabel this year's data).
            $src = $agg ?? $prior[$key];
            $catId = $agg !== null ? $agg['category_id'] : ($prior[$key]['category_id'] ?? null);

            // Quantity + unit are shown together, and only for a CURRENT item whose
            // unit is unambiguous: exactly one unit on every line (→ show that unit),
            // or no unit anywhere (→ a pure count, no unit label). Anything else — 2+
            // units, a unit-ed line mixed with a unit-less one, or a prior-only row
            // (no current activity) — shows «—» with no stray unit.
            $units = $src['units'] ?? [];
            $hasUnitless = $src['has_unitless'] ?? false;
            $distinctUnits = count($units);
            $showQty = $agg !== null && ! ($distinctUnits > 1 || ($distinctUnits === 1 && $hasUnitless));
            $qty = $showQty ? round((float) $agg['qty'], 3) : null;
            $unit = ($showQty && $distinctUnits === 1) ? (string) array_key_first($units) : null;

            $rows[] = [
                'key' => $key,
                'label' => (string) $src['label'],
                'product_id' => $src['product_id'],
                'sku' => $src['sku'],
                'category_id' => $catId,
                'category' => $catId !== null ? (string) (($names[$catId] ?? null) ?: ('#'.$catId)) : 'Αταξινόμητα',
                'qty' => $qty,
                'unit' => $unit,
                'net' => round((float) ($agg['net'] ?? 0.0), 2),
                'vat' => round((float) ($agg['vat'] ?? 0.0), 2),
                'gross' => round((float) ($agg['gross'] ?? 0.0), 2),
                'lines' => (int) ($agg['lines'] ?? 0),
                'pct' => $totalNet != 0.0 ? round((float) ($agg['net'] ?? 0.0) / $totalNet * 100, 1) : 0.0,
                'prior_net' => round($priorNet, 2),
                'delta' => round((float) ($agg['net'] ?? 0.0) - $priorNet, 2),
            ];
        }

        // Highest revenue first; the «(χωρίς περιγραφή)» bucket always sinks last.
        usort($rows, function (array $a, array $b): int {
            $aNoId = str_starts_with($a['key'], 'x:');
            $bNoId = str_starts_with($b['key'], 'x:');
            if ($aNoId !== $bNoId) {
                return $aNoId ? 1 : -1;
            }

            return [$b['net'], $a['label']] <=> [$a['net'], $b['label']];
        });

        return [
            'year' => $year,
            'rows' => $rows,
            'total_net' => round($totalNet, 2),
            'total_vat' => round(array_sum(array_map(fn (array $b): float => $b['vat'], $current)), 2),
            'total_gross' => round(array_sum(array_map(fn (array $b): float => $b['gross'], $current)), 2),
            'prior_total_net' => round($priorTotalNet, 2),
        ];
    }

    /**
     * One year's turnover folded into an item-key => bucket map. Each bucket also
     * tracks per-category net so build() can pick the item's dominant category.
     *
     * @return array<string, array{label: string, product_id: ?int, sku: ?string, units: array<string, bool>, has_unitless: bool, category_id: ?int, qty: float, net: float, vat: float, gross: float, lines: int, cats: array<int, float>}>
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
                'invoice_lines.product_id',
                'invoice_lines.product_descr',
                'invoice_lines.metric_unit',
                'invoice_lines.product_category_id as line_cat',
                'invoice_lines.qty',
                'invoice_lines.net_price',
                'invoice_lines.gross_price',
                'products.product_category_id as product_cat',
                'products.sku',
                'products.description_short as p_short',
                'products.description as p_desc',
                'invoices.credited_invoice_id',
                // The invoice-level discount lives on the invoice TOTALS, not the
                // stored line net/gross — carried so we apply it per line (as #2 does).
                'invoices.header_discount_percent',
            )
            // A standalone legacy credit-type document (is_credit) also nets negative.
            ->selectRaw('EXISTS (SELECT 1 FROM invoice_types it WHERE it.id = invoices.invoice_type_id AND it.is_credit = 1) as is_credit_type')
            ->get();

        $agg = [];
        foreach ($lines as $l) {
            $isCreditNote = $l->credited_invoice_id !== null || (int) $l->is_credit_type === 1;
            $sign = $isCreditNote ? -1.0 : 1.0;

            [$key, $label] = $this->identify($l);
            $catId = $l->line_cat !== null ? (int) $l->line_cat : ($l->product_cat !== null ? (int) $l->product_cat : null);

            // Header discount is uniform per invoice, applied to the TOTALS, so
            // Σ(line × factor) == invoice net_total pre-rounding — keeps the report's
            // «document value» promise (see CLAUDE.md «VAT / discount / rounding»).
            $factor = 1 - ((float) $l->header_discount_percent) / 100;
            $net = (float) $l->net_price * $factor;
            $gross = (float) $l->gross_price * $factor;

            if (! isset($agg[$key])) {
                $agg[$key] = [
                    'label' => $label,
                    'product_id' => $l->product_id !== null ? (int) $l->product_id : null,
                    'sku' => $l->sku,
                    'units' => [],
                    'has_unitless' => false,
                    'category_id' => null,
                    'qty' => 0.0,
                    'net' => 0.0,
                    'vat' => 0.0,
                    'gross' => 0.0,
                    'lines' => 0,
                    'cats' => [],
                ];
            }

            // Track the DISTINCT units this item was billed in (as a set) and whether
            // any line had NO unit. build() shows a summed quantity only when the item
            // is unambiguous — either exactly one unit on every line, OR no unit at
            // all (a pure count). A mix (ΤΕΜ + ΩΡΑ, or a unit-ed line alongside a
            // unit-less one) can't carry a meaningful total, so it reports «—».
            if ($l->metric_unit !== null && $l->metric_unit !== '') {
                $agg[$key]['units'][(string) $l->metric_unit] = true;
            } else {
                $agg[$key]['has_unitless'] = true;
            }

            $agg[$key]['qty'] += $sign * (float) $l->qty;
            $agg[$key]['net'] += $sign * $net;
            $agg[$key]['vat'] += $sign * ($gross - $net);
            $agg[$key]['gross'] += $sign * $gross;
            $agg[$key]['lines']++;

            // Dominant category = the one carrying the most (signed) net for this item.
            $bucket = $catId ?? 0;
            $agg[$key]['cats'][$bucket] = ($agg[$key]['cats'][$bucket] ?? 0.0) + $sign * $net;
        }

        // Resolve each bucket's dominant category = the one carrying the largest
        // ABSOLUTE net for this item (0 = «Αταξινόμητα» → null). Magnitude, not
        // signed value, so an item whose net is negative in every category (credited
        // more than sold) still resolves to its real category, not the least-negative
        // bucket.
        foreach ($agg as $key => $b) {
            if ($b['cats'] === []) {
                continue;
            }
            $ids = array_keys($b['cats']);
            // abs net desc; on a tie prefer a real category over «Αταξινόμητα» (0),
            // then the lower id — fully deterministic regardless of DB row order.
            usort($ids, function (int $a, int $z) use ($b): int {
                $c = abs($b['cats'][$z]) <=> abs($b['cats'][$a]);
                if ($c !== 0) {
                    return $c;
                }
                $ra = $a > 0 ? 0 : 1;
                $rz = $z > 0 ? 0 : 1;

                return $ra !== $rz ? $ra <=> $rz : $a <=> $z;
            });
            $top = (int) $ids[0];
            $agg[$key]['category_id'] = $top > 0 ? $top : null;
        }

        return $agg;
    }

    /**
     * The (grouping key, display label) for a line: product identity first, else the
     * normalised free-text description, else a no-identity bucket.
     *
     * @return array{0: string, 1: string}
     */
    private function identify(object $l): array
    {
        if ($l->product_id !== null) {
            // Emptiness (not truthiness) check — a product literally named «0» is a
            // valid label and must not fall through to the long description.
            $name = trim((string) $l->p_short);
            if ($name === '') {
                $name = trim((string) $l->p_desc);
            }
            if ($name === '') {
                // Product row with no name (shouldn't happen) — fall back to the line text.
                $name = ItemLabelNormalizer::clean((string) $l->product_descr) ?: ('#'.$l->product_id);
            }

            return ['p:'.$l->product_id, $name];
        }

        $clean = ItemLabelNormalizer::clean((string) $l->product_descr);
        if ($clean !== '') {
            // Label = the cleaned text; key = its case-fold via the SAME helper, so
            // the grouping rule lives in one place (no inline re-implementation).
            return ['d:'.ItemLabelNormalizer::key((string) $l->product_descr), $clean];
        }

        return ['x:', '(χωρίς περιγραφή)'];
    }
}
