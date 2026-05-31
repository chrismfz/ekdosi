<?php

namespace App\Services\MyData;

use App\Models\Company;
use App\Models\InvoiceType;
use App\Models\VatCategory;
use App\Support\MyData\Codes;
use Illuminate\Support\Facades\DB;

/**
 * Seeds a tenant's lookup tables (VAT categories, invoice types) with the
 * standard Greek AADE values.
 *
 * There is NO myDATA "fetch the code tables" API — §8.1 (invoice types) and
 * §8.2 (VAT categories) are STATIC enums published in the spec. So the source
 * here is App\Support\MyData\Codes (which is the committed copy of those spec
 * tables), not an HTTP call.
 *
 * Idempotent: matches existing rows by their natural key (VatCategory by rate,
 * InvoiceType by code) and SKIPS them — never overwrites operator edits, never
 * duplicates. Returns a per-seed count of created/skipped so the UI can report.
 */
class MyDataLookupSeeder
{
    /**
     * Seed the standard §8.2 VAT categories (1–7; skips code 8 = no rate and
     * code 10 = duplicate 4%). Existing categories at the same rate are kept.
     *
     * @return array{created: int, skipped: int}
     */
    public function seedVatCategories(Company $tenant): array
    {
        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($tenant, &$created, &$skipped) {
            $hasAnyDefault = VatCategory::query()
                ->where('company_id', $tenant->getKey())
                ->where('is_default', true)
                ->exists();

            foreach (Codes::vatCategorySeedRows() as $row) {
                $exists = VatCategory::query()
                    ->where('company_id', $tenant->getKey())
                    ->where('rate', $row['rate'])
                    ->exists();

                if ($exists) {
                    $skipped++;

                    continue;
                }

                // First-ever seed with no default yet → make 24% the default.
                $isDefault = ! $hasAnyDefault && abs($row['rate'] - 24.0) < 0.01;

                VatCategory::create([
                    'company_id' => $tenant->getKey(),
                    'description' => $row['description'],
                    'rate' => $row['rate'],
                    'is_default' => $isDefault,
                ]);

                if ($isDefault) {
                    $hasAnyDefault = true;
                }
                $created++;
            }
        });

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Seed a STARTER set of common invoice types (§8.1). Deliberately a small,
     * opinionated set — not all 50+ AADE types — covering the everyday cases an
     * operator needs day one: goods sales, services, retail, credit notes,
     * delivery note. The operator adds the rest (or edits the series codes) as
     * needed; re-running never touches what they changed.
     *
     * Matched by `code` (the human series prefix), which is the tenant's unique
     * key for an invoice type. `invcount` starts at 1; `show_on_menu` true.
     *
     * For a type that ALREADY exists by code (e.g. ΤΙΜ imported from legacy with
     * an empty mydata_type), we don't just skip it — we FILL the myDATA
     * classification when it's missing (`mydata_type` empty → set the §8.1 code,
     * and turn on `mydata_requires_quantity` for goods). Fill-empty only: a
     * mydata_type the operator already set is never overwritten. So «Εισαγωγή
     * τυπικών» also back-fills the AADE category onto pre-existing rows.
     *
     * @return array{created: int, skipped: int, filled: int}
     */
    public function seedInvoiceTypes(Company $tenant): array
    {
        $created = 0;
        $skipped = 0;
        $filled = 0;

        DB::transaction(function () use ($tenant, &$created, &$skipped, &$filled) {
            foreach (self::INVOICE_TYPE_SEED as $row) {
                $existing = InvoiceType::query()
                    ->where('company_id', $tenant->getKey())
                    ->where('code', $row['code'])
                    ->first();

                if ($existing !== null) {
                    // Back-fill the myDATA classification if it's missing, but
                    // never touch a value the operator already set.
                    if (blank($existing->mydata_type)) {
                        $existing->mydata_type = $row['mydata_type'];
                        if (($row['goods'] ?? false) && ! $existing->mydata_requires_quantity) {
                            $existing->mydata_requires_quantity = true;
                        }
                        $existing->save();
                        $filled++;
                    } else {
                        $skipped++;
                    }

                    continue;
                }

                InvoiceType::create([
                    'company_id' => $tenant->getKey(),
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'mydata_type' => $row['mydata_type'],
                    'invcount' => 1,
                    'show_on_menu' => true,
                    'is_credit' => $row['is_credit'] ?? false,
                    'mydata_requires_quantity' => $row['goods'] ?? false,
                ]);
                $created++;
            }
        });

        return ['created' => $created, 'skipped' => $skipped, 'filled' => $filled];
    }

    /**
     * Starter invoice types. `code` is the human series prefix (operator-
     * editable); `mydata_type` is the AADE §8.1 classification (the legal one).
     * `goods` => mydata_requires_quantity (AADE wants per-line quantity for
     * goods types — see G5). Labels verbatim from §8.1.
     *
     * @var list<array{code: string, name: string, mydata_type: string, is_credit?: bool, goods?: bool}>
     */
    private const INVOICE_TYPE_SEED = [
        // Goods — the missing "κόψε εμπόρευμα" case.
        ['code' => 'ΤΙΜ', 'name' => 'Τιμολόγιο Πώλησης', 'mydata_type' => '1.1', 'goods' => true],
        ['code' => 'ΤΔΑ', 'name' => 'Τιμολόγιο Πώλησης / Δελτίο Αποστολής', 'mydata_type' => '1.1', 'goods' => true],
        ['code' => 'ΕΝΔ', 'name' => 'Τιμολόγιο Πώλησης / Ενδοκοινοτικές Παραδόσεις', 'mydata_type' => '1.2', 'goods' => true],
        // Services.
        ['code' => 'ΤΠΥ', 'name' => 'Τιμολόγιο Παροχής Υπηρεσιών', 'mydata_type' => '2.1'],
        // Retail.
        ['code' => 'ΑΛΠ', 'name' => 'Απόδειξη Λιανικής Πώλησης', 'mydata_type' => '11.1', 'goods' => true],
        ['code' => 'ΑΠΥ', 'name' => 'Απόδειξη Παροχής Υπηρεσιών', 'mydata_type' => '11.2'],
        // Credit.
        ['code' => 'ΠΙΣ', 'name' => 'Πιστωτικό Τιμολόγιο / Συσχετιζόμενο', 'mydata_type' => '5.1', 'is_credit' => true],
        // Delivery note.
        ['code' => 'ΔΑΠ', 'name' => 'Δελτίο Αποστολής', 'mydata_type' => '9.3', 'goods' => true],
    ];
}
