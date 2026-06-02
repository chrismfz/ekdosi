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
     * an empty classification), we don't just skip it — we FILL each missing
     * myDATA field (`mydata_type` → §8.1 code + `mydata_requires_quantity` for
     * goods; `mydata_income_class` → §8.5 E3 type; `mydata_income_class_category`
     * → §8.6 bucket). Fill-empty per field: a value the operator already set is
     * never overwritten. So «Εισαγωγή τυπικών» also completes the AADE
     * classification chain on pre-existing rows.
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
                    // Back-fill missing myDATA classification, fill-empty only —
                    // a value the operator already set is NEVER overwritten.
                    $touched = false;

                    // (1) doc type + goods-quantity flag, when the row has no type.
                    if (blank($existing->mydata_type)) {
                        $existing->mydata_type = $row['mydata_type'];
                        if (($row['goods'] ?? false) && ! $existing->mydata_requires_quantity) {
                            $existing->mydata_requires_quantity = true;
                        }
                        $touched = true;
                    }

                    // (2) income classification chain — ONLY when the row's type
                    // matches THIS seed row's type (just set above, or already
                    // equal). If the operator reclassified the series to a
                    // different §8.1 type, the seed's E3/category belong to a
                    // different document kind, so we must not impose them.
                    if ($existing->mydata_type === $row['mydata_type']) {
                        if (blank($existing->mydata_income_class) && ! empty($row['income_class'])) {
                            $existing->mydata_income_class = $row['income_class'];
                            $touched = true;
                        }
                        if (blank($existing->mydata_income_class_category) && ! empty($row['income_class_category'])) {
                            $existing->mydata_income_class_category = $row['income_class_category'];
                            $touched = true;
                        }
                    }

                    if ($touched) {
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
                    'mydata_income_class' => $row['income_class'] ?? null,
                    'mydata_income_class_category' => $row['income_class_category'] ?? null,
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
     * Starter invoice types, pre-classified "by the book" so a fresh tenant can
     * file the everyday cases with NO operator setup. Each row carries the full
     * myDATA chain: `mydata_type` (§8.1 doc type), `income_class` (§8.5 E3 line
     * type) and `income_class_category` (§8.6 per-rate bucket) — the three the
     * submitter needs to emit a complete income classification.
     *
     * `code` is the human series prefix (operator-editable). `goods` =>
     * mydata_requires_quantity (AADE wants per-line quantity on goods — G5).
     *
     * Classification logic (AADE §8.5/§8.6, validated shapes in
     * mydata-sandbox-validation-2026-05-28.md):
     *   - B2B invoices (1.1 / 2.1)      → E3_561_001 (Χονδρικές - Επιτηδευματιών)
     *   - intra-community (1.2)         → E3_561_005 (Εξωτερικού Ενδοκοινοτικές)
     *   - retail (11.1 / 11.2)          → E3_561_003 (Λιανικές - Ιδιωτική Πελατεία)
     *   - goods                         → category1_1 (Πώληση Εμπορευμάτων)
     *   - services                      → category1_3 (Παροχή Υπηρεσιών)
     *   - delivery note (9.3)           → NONE (a Δελτίο Αποστολής carries no revenue)
     *
     * TWO operator/accountant judgement calls (defaults below, flip per business
     * in Setup → Invoice Types):
     *   1. Goods category: category1_1 «Εμπορευμάτων» (resale — the common SMB
     *      case) vs category1_2 «Προϊόντων» (own-manufactured). Default = 1_1.
     *   2. The credit note (ΠΙΣ) classification mirrors what it reduces; we
     *      default it to the services chain (this is a services-first tenant),
     *      adjust if you mostly credit goods invoices.
     *
     * @var list<array{code: string, name: string, mydata_type: string, income_class?: string, income_class_category?: string, is_credit?: bool, goods?: bool}>
     */
    private const INVOICE_TYPE_SEED = [
        // Goods — the missing "κόψε εμπόρευμα" case.
        ['code' => 'ΤΙΜ', 'name' => 'Τιμολόγιο Πώλησης', 'mydata_type' => '1.1', 'income_class' => 'E3_561_001', 'income_class_category' => 'category1_1', 'goods' => true],
        ['code' => 'ΤΔΑ', 'name' => 'Τιμολόγιο Πώλησης / Δελτίο Αποστολής', 'mydata_type' => '1.1', 'income_class' => 'E3_561_001', 'income_class_category' => 'category1_1', 'goods' => true],
        ['code' => 'ΕΝΔ', 'name' => 'Τιμολόγιο Πώλησης / Ενδοκοινοτικές Παραδόσεις', 'mydata_type' => '1.2', 'income_class' => 'E3_561_005', 'income_class_category' => 'category1_1', 'goods' => true],
        // Services.
        ['code' => 'ΤΠΥ', 'name' => 'Τιμολόγιο Παροχής Υπηρεσιών', 'mydata_type' => '2.1', 'income_class' => 'E3_561_001', 'income_class_category' => 'category1_3'],
        // Retail.
        ['code' => 'ΑΛΠ', 'name' => 'Απόδειξη Λιανικής Πώλησης', 'mydata_type' => '11.1', 'income_class' => 'E3_561_003', 'income_class_category' => 'category1_1', 'goods' => true],
        ['code' => 'ΑΠΥ', 'name' => 'Απόδειξη Παροχής Υπηρεσιών', 'mydata_type' => '11.2', 'income_class' => 'E3_561_003', 'income_class_category' => 'category1_3'],
        // Credit (mirrors the reduced revenue — services default, see docblock).
        ['code' => 'ΠΙΣ', 'name' => 'Πιστωτικό Τιμολόγιο / Συσχετιζόμενο', 'mydata_type' => '5.1', 'income_class' => 'E3_561_001', 'income_class_category' => 'category1_3', 'is_credit' => true],
        // Delivery note — NO income classification (no revenue).
        ['code' => 'ΔΑΠ', 'name' => 'Δελτίο Αποστολής', 'mydata_type' => '9.3', 'goods' => true],
    ];
}
