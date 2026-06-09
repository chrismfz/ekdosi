<?php

namespace App\Services;

use App\Models\Invoice;
use RuntimeException;

/**
 * Server-side recompute of the myDATA taxesTotals amounts on every invoice save —
 * the «δέσιμο» + the «productionise», unified:
 *
 *  1. PRODUCT-LINKED (auto): a product can carry a default fee/levy
 *     (`mydata_tax_type` + `mydata_tax_category` + `mydata_tax_per_unit`, e.g.
 *     πλαστική σακούλα €0,07/τεμ). The amount = Σ qty × per_unit over the lines
 *     whose product carries that taxType — recomputed from the real lines, so it's
 *     never stale and uses no header discount (a per-unit levy isn't discounted).
 *  2. RATE-DRIVEN (manual-but-correct): when an invoice carries a `*_rate`
 *     (e.g. χαρτόσημο 3,6%, παρακράτηση 20%), the amount = rate × net_total
 *     (the authoritative, header-discounted net) — recomputed on save, so editing a
 *     line can't leave a stale amount (closes the preview's #2/#3).
 *  3. MANUAL FLAT: a taxType with neither a product nor a rate keeps the operator's
 *     typed amount untouched.
 *
 * Per taxType the invoice holds ONE category (Phase-1) — products that disagree on
 * a taxType's category throw (split the invoice, or move to the `invoice_taxes`
 * table in Phase-2). Runs from RecomputeInvoiceTotals AFTER net_total is saved, so
 * the rate base is authoritative.
 */
class RecomputeInvoiceTaxes
{
    /** myDATA taxType => [amount column, category column, rate column] */
    private const MAP = [
        1 => ['withhold_amount', 'withhold_category', 'withhold_rate'],
        2 => ['fees_amount', 'fees_category', 'fees_rate'],
        3 => ['other_taxes_amount', 'other_taxes_category', 'other_taxes_rate'],
        4 => ['stamp_duty_amount', 'stamp_duty_category', 'stamp_duty_rate'],
        5 => ['deductions_amount', 'deductions_category', 'deductions_rate'],
    ];

    public function __invoke(Invoice $invoice): Invoice
    {
        $fresh = $invoice->fresh(['lines.product']);
        if ($fresh === null) {
            return $invoice;
        }

        $net = (float) $fresh->net_total;
        $changes = [];

        foreach (self::MAP as $taxType => [$amountCol, $categoryCol, $rateCol]) {
            // 1) Product-linked (auto) — Σ qty × per_unit.
            $productLines = $fresh->lines->filter(function ($l) use ($taxType) {
                $p = $l->product;

                return $p !== null
                    && (int) ($p->mydata_tax_type ?? 0) === $taxType
                    && $p->mydata_tax_per_unit !== null
                    && (float) $p->mydata_tax_per_unit > 0;
            });

            if ($productLines->isNotEmpty()) {
                $categories = $productLines
                    ->map(fn ($l) => (int) $l->product->mydata_tax_category)
                    ->unique()->values();

                if ($categories->count() > 1) {
                    throw new RuntimeException(
                        "Invoice {$fresh->invcode}: products map to multiple myDATA categories ("
                        .$categories->implode(', ').") for tax type {$taxType}. The invoice carries one "
                        .'category per tax type — split the invoice or align the products.'
                    );
                }

                $changes[$amountCol] = round(
                    $productLines->sum(fn ($l) => (float) $l->qty * (float) $l->product->mydata_tax_per_unit),
                    2,
                );
                $changes[$categoryCol] = $categories->first();

                continue;
            }

            // 2) Rate-driven (invoice-level %) — amount = rate × net; category unchanged.
            $rate = $fresh->{$rateCol};
            if ($rate !== null && (float) $rate != 0.0) {
                $changes[$amountCol] = round($net * (float) $rate / 100, 2);
            }
            // 3) else: manual flat amount — untouched.
        }

        if ($changes !== []) {
            $fresh->forceFill($changes)->save();
        }

        return $fresh;
    }
}
