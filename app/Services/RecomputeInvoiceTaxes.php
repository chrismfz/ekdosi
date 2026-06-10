<?php

namespace App\Services;

use App\Models\Invoice;
use RuntimeException;

/**
 * Server-side recompute of the myDATA taxesTotals amounts on every invoice save —
 * the «δέσιμο» + the «productionise», unified. The recompute fully OWNS the tax
 * columns (like RecomputeInvoiceTotals owns net_total/gross_total): each taxType's
 * amount is derived deterministically, so removing the driver clears it — no stale
 * «phantom» fee can be filed.
 *
 *  1. PRODUCT-LINKED (auto): a product carries a default per-unit levy
 *     (`mydata_tax_type` + `_category` + `_per_unit`, e.g. πλαστική σακούλα
 *     €0,07/τεμ). amount = Σ qty × per_unit over the lines whose product carries
 *     that taxType (no header discount — a per-unit levy isn't discounted).
 *  2. RATE-DRIVEN (manual-but-correct): when an invoice carries a `*_rate`
 *     (χαρτόσημο 3,6%, παρακράτηση 20%, set by the «Τυπικά τέλη/φόροι» preset),
 *     amount = rate × base, where base = InvoiceVatBreakdown::totalNet() — the
 *     SAME net the submitter files as `underlyingValue`, so taxAmount reconciles.
 *  3. NEITHER → amount is CLEARED (0, category null). A flat one-off fee is not
 *     modelled in Phase-1 (use a rate/product); it belongs to the `invoice_taxes`
 *     table (Phase-2).
 *
 * One category per taxType per invoice (Phase-1) — products that disagree throw.
 * Runs from RecomputeInvoiceTotals AFTER net_total is saved.
 *
 * The COLLECTIBLE amount (fees up, withholding down, per AADE [208]) is persisted to
 * `invoices.payable_total` here (= gross_total + Invoice::additionalTaxAdjustment()).
 * `gross_total` STAYS net+VAT (revenue/turnover/VAT); `payable_total` is the basis the
 * owed/balance/receivables sites read via Invoice::payableTotal().
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

        // The rate base = the exact net the submitter files as underlyingValue
        // (per-VAT-rate rounded, header discount applied) — NOT net_total (whole-sum
        // rounded), so taxAmount = rate × underlyingValue reconciles at AADE.
        $base = InvoiceVatBreakdown::for($fresh)->totalNet();

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
                        "Το παραστατικό {$fresh->invcode} έχει προϊόντα με ΔΙΑΦΟΡΕΤΙΚΕΣ κατηγορίες "
                        ."myDATA (".$categories->implode(', ').") για τον ίδιο τύπο τέλους/φόρου. "
                        .'Ένα παραστατικό κρατά μία κατηγορία ανά τύπο — χώρισε το παραστατικό ή '
                        .'ευθυγράμμισε τις κατηγορίες των προϊόντων.'
                    );
                }

                // Product fee wins over any invoice-level rate of the same taxType.
                $changes[$amountCol] = round(
                    $productLines->sum(fn ($l) => (float) $l->qty * (float) $l->product->mydata_tax_per_unit),
                    2,
                );
                $changes[$categoryCol] = $categories->first();

                continue;
            }

            // 2) Rate-driven (invoice-level %) — amount = rate × base; category stays.
            $rate = $fresh->{$rateCol};
            if ($rate !== null && (float) $rate != 0.0) {
                $changes[$amountCol] = round($base * (float) $rate / 100, 2);

                continue;
            }

            // 3) No driver → CLEAR (kills stale «phantom» fees on driver removal).
            if ((float) ($fresh->{$amountCol} ?? 0) != 0.0 || $fresh->{$categoryCol} !== null) {
                $changes[$amountCol] = 0;
                $changes[$categoryCol] = null;
            }
        }

        if ($changes !== []) {
            $fresh->forceFill($changes);
        }

        // The COLLECTIBLE total: gross (net+VAT) + the [208] additional-tax
        // adjustment (fees/stamp/other up, deductions/withholding down). Owns
        // `payable_total` like net_total/gross_total — always written so the
        // owed/receivables sites read a fresh value (gross_total is saved by
        // RecomputeInvoiceTotals just before this runs).
        $fresh->payable_total = round((float) ($fresh->gross_total ?? 0) + $fresh->additionalTaxAdjustment(), 2);
        $fresh->save();

        return $fresh;
    }
}
