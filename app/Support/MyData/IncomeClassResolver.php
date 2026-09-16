<?php

namespace App\Support\MyData;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Services\EInvoice\AadeInvoiceDocument;

/**
 * MYD-5 / MYD-006: the SINGLE source for a line's (E3 income class, §8.6 category).
 *
 * Consumed by BOTH the filing path ({@see AadeInvoiceDocument})
 * AND the read-only display surfaces (the «Έλεγχος ΜΑΡΚ» detail + the invoice-line
 * table), so what an operator SEES is exactly what gets FILED — a divergence would
 * be a silent legal-filing bug, which is why the resolution lives here once.
 *
 * Resolution (field by field, so a product CATEGORY can override just the goods/
 * services BUCKET while the E3 TYPE keeps coming from the channel-driven invoice
 * type):
 *   1. per-line snapshot (`invoice_lines.mydata_income_class[_category]`) — the
 *      WHMCS bridge / an explicit override; wins outright.
 *   2. product-CATEGORY override (`product_categories.mydata_income_class[_category]`).
 *   3. the base pair — the invoice type's default, or, for a CREDIT note, the
 *      ORIGINAL document's classification (a credit reverses the same income line).
 *   4. MYD-006 business policy: a goods line that would otherwise take the
 *      merchandise default (category1_1) with NO explicit source follows the
 *      tenant's activity policy (manufacturer → category1_2, reseller → category1_1).
 */
final class IncomeClassResolver
{
    /**
     * The base (E3 class, §8.6 category) an invoice's lines inherit BEFORE the
     * per-line product-category / business-policy resolution. A CREDIT note
     * inherits the ORIGINAL document's classification; a plain invoice uses its
     * own type's default.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function baseFor(Invoice $invoice): array
    {
        $typeClass = $invoice->invoiceType?->mydata_income_class;
        $typeCat = $invoice->invoiceType?->mydata_income_class_category;

        if ($invoice->credited_invoice_id === null) {
            return [$typeClass, $typeCat];
        }

        // Same-tenant lookup: credited_invoice_id is a global PK, but the
        // CompanyScope is a no-op off-request (queue/CLI submit), so scope
        // explicitly — a credit whose original belongs to another tenant must
        // never borrow its classification (mirrors originalInsertMark's MYD-008).
        $original = Invoice::query()
            ->where('company_id', $invoice->company_id)
            ->whereKey($invoice->credited_invoice_id)
            ->with('invoiceType:id,mydata_income_class,mydata_income_class_category')
            ->first();

        $origClass = $original?->invoiceType?->mydata_income_class;
        $origCat = $original?->invoiceType?->mydata_income_class_category;

        return [
            filled($origClass) ? $origClass : $typeClass,
            filled($origCat) ? $origCat : $typeCat,
        ];
    }

    /**
     * The resolved (E3 class, category) for ONE line, given the base pair and the
     * tenant's business-activity policy. A source that sets only the BUCKET keeps
     * the base E3 class.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function forLine(InvoiceLine $line, ?string $baseClass, ?string $baseCat, ?string $businessActivityType): array
    {
        $category = $line->product?->productCategory;

        $lineClass = filled($line->mydata_income_class) ? $line->mydata_income_class : null;
        $lineCat = filled($line->mydata_income_class_category) ? $line->mydata_income_class_category : null;

        $class = $lineClass ?? (filled($category?->mydata_income_class) ? $category->mydata_income_class : $baseClass);
        $cat = $lineCat ?? (filled($category?->mydata_income_class_category) ? $category->mydata_income_class_category : $baseCat);

        // MYD-006: when the bucket falls back to the GENERIC merchandise default
        // (category1_1) with NO explicit source — neither a per-line snapshot nor
        // a per-product-category override — the tenant's business policy decides
        // the goods bucket. Services / mixed / unset → null → no change.
        if ($cat === ClassificationGuidance::MERCHANDISE_DEFAULT
            && $lineCat === null
            && ! filled($category?->mydata_income_class_category)) {
            $policyCat = ClassificationGuidance::goodsCategoryFor($businessActivityType);
            if ($policyCat !== null) {
                $cat = $policyCat;
            }
        }

        return [$class, $cat];
    }

    /**
     * Convenience for the read-only display surfaces: resolve a line against its
     * invoice in one call. The filing path already holds the base pair + the
     * tenant, and passes them explicitly (so it never re-derives the base per line).
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function resolve(Invoice $invoice, InvoiceLine $line): array
    {
        [$baseClass, $baseCat] = $this->baseFor($invoice);

        return $this->forLine($line, $baseClass, $baseCat, $invoice->company?->business_activity_type);
    }
}
