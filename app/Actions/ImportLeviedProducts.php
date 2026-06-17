<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VatCategory;
use App\Support\Products\LeviedProductTemplates;

/**
 * Imports the selected «θεσμικά τέλη» product templates (LeviedProductTemplates)
 * into a tenant as ready-to-use products carrying the right myDATA product-linked
 * fee (Fees §8.5 + category + €/unit).
 *
 * Idempotent: a template already present (matched by name + tax category for the
 * tenant) is skipped, so re-running never duplicates. Tenant-scoped via explicit
 * company_id (CLAUDE.md CLI/action rule).
 */
class ImportLeviedProducts
{
    /**
     * @param  list<string>  $keys  template keys from LeviedProductTemplates::all()
     * @return array{created: list<string>, skipped: list<string>}
     */
    public function __invoke(Company $tenant, array $keys): array
    {
        $templates = LeviedProductTemplates::all();

        // The bag/levy product itself is priced 0 (the levy IS the charge), so the
        // VAT category is cosmetic — use the tenant default if one exists.
        $defaultVatId = VatCategory::query()
            ->where('company_id', $tenant->getKey())
            ->where('is_default', true)
            ->value('id');

        // products.product_category_id is NOT NULL → group every imported levy
        // under a dedicated «Τέλη & φόροι» category (created once per tenant).
        $categoryId = ProductCategory::query()
            ->where('company_id', $tenant->getKey())
            ->where('description_short', 'Τέλη & φόροι')
            ->value('id')
            ?? ProductCategory::create([
                'company_id' => $tenant->getKey(),
                'description_short' => 'Τέλη & φόροι',
            ])->getKey();

        $created = [];
        $skipped = [];

        foreach ($keys as $key) {
            $t = $templates[$key] ?? null;
            if ($t === null) {
                continue;
            }

            $exists = Product::query()
                ->where('company_id', $tenant->getKey())
                ->where('description_short', $t['name'])
                ->where('mydata_tax_type', LeviedProductTemplates::TAX_TYPE_FEES)
                ->where('mydata_tax_category', $t['tax_category'])
                ->exists();

            if ($exists) {
                $skipped[] = $t['name'];

                continue;
            }

            Product::create([
                'company_id' => $tenant->getKey(),
                'description_short' => $t['name'],
                'product_category_id' => $categoryId,
                'sell_price' => 0,
                'price_wvat' => 0,
                'vat_category_id' => $defaultVatId,
                'mydata_tax_type' => LeviedProductTemplates::TAX_TYPE_FEES,
                'mydata_tax_category' => $t['tax_category'],
                'mydata_tax_per_unit' => $t['per_unit'],
                'is_active' => true,
            ]);

            $created[] = $t['name'];
        }

        return ['created' => $created, 'skipped' => $skipped];
    }
}
