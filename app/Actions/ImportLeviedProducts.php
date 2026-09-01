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
 * fee (Fees §8.7 + category + €/unit).
 *
 * Idempotent: a template already present (matched by name + tax type + category
 * for the tenant) is skipped via firstOrCreate, so re-running never duplicates.
 * Tenant-scoped via explicit company_id (CLAUDE.md CLI/action rule).
 *
 * `products.vat_category_id` is NOT NULL, so a tenant with NO VAT category at all
 * (a from-zero setup that hasn't seeded VAT) can't get a valid product — the
 * import bails with `error='no_vat'` and the caller tells the operator to make a
 * VAT category first, instead of hitting a constraint violation.
 */
class ImportLeviedProducts
{
    /**
     * @param  list<string>  $keys  template keys from LeviedProductTemplates::all()
     * @return array{created: list<string>, skipped: list<string>, error: ?string}
     */
    public function __invoke(Company $tenant, array $keys): array
    {
        $templates = LeviedProductTemplates::all();

        // The levy product is priced 0 (the levy IS the charge) so the VAT
        // category is cosmetic — but the column is NOT NULL. Prefer the tenant
        // default, else ANY VAT category; if the tenant has none, bail gracefully.
        $vatId = VatCategory::query()
            ->where('company_id', $tenant->getKey())
            ->orderByDesc('is_default')
            ->value('id');

        if ($vatId === null) {
            return ['created' => [], 'skipped' => [], 'error' => 'no_vat'];
        }

        // Group every imported levy under a dedicated «Τέλη & φόροι» category
        // (created once per tenant; reused if the operator already made one).
        $categoryId = ProductCategory::firstOrCreate([
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

            // firstOrCreate on name+type+category = idempotency AND the race guard
            // in one (matches the dup-check the exists()+create() pair did).
            $product = Product::firstOrCreate(
                [
                    'company_id' => $tenant->getKey(),
                    'description_short' => $t['name'],
                    'mydata_tax_type' => LeviedProductTemplates::TAX_TYPE_FEES,
                    'mydata_tax_category' => $t['tax_category'],
                ],
                [
                    'product_category_id' => $categoryId,
                    'sell_price' => 0,
                    'price_wvat' => 0,
                    'vat_category_id' => $vatId,
                    'mydata_tax_per_unit' => $t['per_unit'],
                    'is_active' => true,
                ],
            );

            if ($product->wasRecentlyCreated) {
                $created[] = $t['name'];
            } else {
                $skipped[] = $t['name'];
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'error' => null];
    }
}
