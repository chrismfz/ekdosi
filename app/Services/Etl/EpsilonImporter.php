<?php

namespace App\Services\Etl;

use App\Models\Company;
use App\Models\Customer;
use App\Models\MetricUnit;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VatCategory;
use Illuminate\Support\Facades\DB;

/**
 * Import Epsilon Smart (Τιμολόγηση) JSON exports into ekdosi.
 *
 * Phase 1: Customers + Items/Services (→ products). Sales (→ invoices) is a
 * separate phase. Re-runnable / upsert by NATURAL KEY (Epsilon has no stable
 * surrogate id in these exports): customers by ΑΦΜ (TIN), products by Name.
 * A re-run updates the legacy-sourced columns and never duplicates.
 *
 * Lookups (VAT category, metric unit, product category, payment method) resolve
 * against the tenant's existing rows — these map cleanly onto the standard AADE
 * lookups the fresh-install seeder installs (MyDataLookupSeeder). Missing metric
 * units / product categories are created on the fly; VAT falls back to the
 * tenant default. All queries are explicitly company-scoped (works in a job /
 * CLI with no ambient tenant), and bypass the global scope so the explicit
 * company_id is authoritative.
 */
class EpsilonImporter
{
    private int $companyId;

    /** @var array<string,int> VtclName → ekdosi VAT rate */
    private const VAT_CLASS_RATES = [
        'Κανονικός' => 24,
        'Μειωμένος' => 13,
        'Υπερμειωμένος' => 6,
        'Απαλλασσόμενο' => 0,
    ];

    /** @var array<string,string> Epsilon unit name → ekdosi metric-unit name */
    private const UNIT_MAP = [
        'Τεμάχιο' => 'ΤΕΜ',
        'Ώρα' => 'ΩΡΑ',
        'Μέτρο' => 'ΜΕΤΡΟ',
        'Κιλό' => 'ΚΙΛΟ',
        'Λίτρο' => 'ΛΙΤΡΟ',
    ];

    /** @var array<string,string> Epsilon accounting category → ekdosi product category */
    private const CATEGORY_MAP = [
        'Εμπόρευμα' => 'Εμπορεύματα',
        'Υπηρεσία' => 'Υπηρεσίες',
        'Προϊόν' => 'Προϊόντα',
    ];

    /** @var array<int,int> rate → vat_category_id (memoised) */
    private array $vatCache = [];

    /** @var array<string,int> metric-unit name → id (memoised) */
    private array $unitCache = [];

    /** @var array<string,int> product-category description_short → id (memoised) */
    private array $categoryCache = [];

    /** @var array<string,?int> payment-method description → id (memoised) */
    private array $paymentCache = [];

    private ?int $defaultVatId = null;

    public function __construct(Company $tenant)
    {
        $this->companyId = $tenant->getKey();
    }

    /**
     * Import customers + products in one pass.
     *
     * @param  array{customers?: array, items?: array, services?: array}  $payload
     * @return array<string, array{created:int, updated:int, skipped:int}>
     */
    public function import(array $payload): array
    {
        $out = [];
        if (isset($payload['customers'])) {
            $out['customers'] = $this->importCustomers($payload['customers']);
        }
        if (isset($payload['items']) || isset($payload['services'])) {
            $out['products'] = $this->importProducts(
                $payload['items'] ?? [],
                $payload['services'] ?? [],
            );
        }

        return $out;
    }

    /**
     * @param  array<int, array<string,mixed>>  $rows
     * @return array{created:int, updated:int, skipped:int}
     */
    public function importCustomers(array $rows): array
    {
        $created = $updated = $skipped = 0;

        DB::transaction(function () use ($rows, &$created, &$updated, &$skipped) {
            foreach ($rows as $row) {
                $afm = $this->clean($row['TIN'] ?? null);
                // Skip Epsilon's built-in generic retail placeholder (no real ΑΦΜ).
                if ($afm === null || $afm === '000000000') {
                    $skipped++;

                    continue;
                }

                $values = [
                    'name' => $this->clean($row['Name'] ?? '') ?? $afm,
                    'tax_office' => $this->clean($row['TaxOffice'] ?? null),
                    'occupation' => $this->clean($row['Profession'] ?? null),
                    'address1' => $this->join([$row['Street'] ?? null, $row['StreetNo'] ?? null]),
                    'city' => $this->clean($row['City'] ?? null),
                    'postcode' => $this->clean($row['PostalCode'] ?? null),
                    'country' => $this->clean($row['CountryISO2'] ?? null) ?: 'GR',
                    'email' => $this->clean($row['Email'] ?? null),
                    'phone1' => $this->clean($row['Phone1'] ?? null),
                    'phone2' => $this->clean($row['Phone2'] ?? null),
                    'discount' => (float) ($row['Discount'] ?? 0),
                    'details' => $this->clean($row['Remarks'] ?? null),
                    'payment_method_id' => $this->resolvePaymentMethod($this->clean($row['PaymentMethod'] ?? null)),
                ];

                $existing = Customer::query()
                    ->withoutGlobalScopes()
                    ->where('company_id', $this->companyId)
                    ->where('afm', $afm)
                    ->first();

                if ($existing !== null) {
                    // Update legacy-sourced fields; leave operator-managed flags
                    // (is_active, tags, …) untouched.
                    $existing->forceFill($values)->save();
                    $updated++;
                } else {
                    Customer::create($values + [
                        'company_id' => $this->companyId,
                        'afm' => $afm,
                        'is_active' => true,
                    ]);
                    $created++;
                }
            }
        });

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * Items + Services → products. Both shapes are identical (Name / VtclName /
     * MsntName / AccCategoryName / WhosalePrice / RetailPrice); services just
     * carry the «Υπηρεσία» accounting category.
     *
     * @param  array<int, array<string,mixed>>  $items
     * @param  array<int, array<string,mixed>>  $services
     * @return array{created:int, updated:int, skipped:int}
     */
    public function importProducts(array $items, array $services): array
    {
        $created = $updated = $skipped = 0;
        $rows = array_merge($items, $services);

        DB::transaction(function () use ($rows, &$created, &$updated, &$skipped) {
            foreach ($rows as $row) {
                $name = $this->clean($row['Name'] ?? null);
                if ($name === null) {
                    $skipped++;

                    continue;
                }

                $rate = self::VAT_CLASS_RATES[$this->clean($row['VtclName'] ?? '') ?? ''] ?? 24;
                // WhosalePrice is the net wholesale price → ekdosi sell_price (net).
                $sell = round((float) ($row['WhosalePrice'] ?? 0), 2);

                $values = [
                    'product_category_id' => $this->resolveProductCategory($this->clean($row['AccCategoryName'] ?? null)),
                    'vat_category_id' => $this->resolveVatCategory((int) $rate),
                    'metric_unit_id' => $this->resolveMetricUnit($this->clean($row['MsntName'] ?? null)),
                    'sell_price' => $sell,
                    'price_wvat' => round($sell * (1 + $rate / 100), 2),
                ];

                $existing = Product::query()
                    ->withoutGlobalScopes()
                    ->where('company_id', $this->companyId)
                    ->where('description_short', $name)
                    ->first();

                if ($existing !== null) {
                    $existing->forceFill($values)->save();
                    $updated++;
                } else {
                    Product::create($values + [
                        'company_id' => $this->companyId,
                        'description_short' => $name,
                        'is_active' => true,
                    ]);
                    $created++;
                }
            }
        });

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }

    /* ===================== resolution ===================== */

    private function resolveVatCategory(int $rate): int
    {
        if (isset($this->vatCache[$rate])) {
            return $this->vatCache[$rate];
        }
        $id = VatCategory::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where('rate', $rate)
            ->value('id');

        $id ??= $this->defaultVatCategory();

        return $this->vatCache[$rate] = (int) $id;
    }

    private function defaultVatCategory(): int
    {
        if ($this->defaultVatId !== null) {
            return $this->defaultVatId;
        }
        $id = VatCategory::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->orderByDesc('is_default')
            ->orderByDesc('rate')
            ->value('id');

        if ($id === null) {
            throw new \RuntimeException('No VAT categories exist for this tenant — seed them first (Setup → Vat Categories → «Εισαγωγή τυπικών»).');
        }

        return $this->defaultVatId = (int) $id;
    }

    private function resolveMetricUnit(?string $epsilonName): ?int
    {
        if ($epsilonName === null) {
            return null;
        }
        $name = self::UNIT_MAP[$epsilonName] ?? $epsilonName;
        if (isset($this->unitCache[$name])) {
            return $this->unitCache[$name];
        }
        $id = MetricUnit::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where('name', $name)
            ->value('id');
        if ($id === null) {
            $id = MetricUnit::create(['company_id' => $this->companyId, 'name' => $name])->getKey();
        }

        return $this->unitCache[$name] = (int) $id;
    }

    private function resolveProductCategory(?string $epsilonName): int
    {
        $name = $epsilonName !== null ? (self::CATEGORY_MAP[$epsilonName] ?? $epsilonName) : 'Λοιπά';
        if (isset($this->categoryCache[$name])) {
            return $this->categoryCache[$name];
        }
        $id = ProductCategory::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where('description_short', $name)
            ->value('id');
        if ($id === null) {
            $id = ProductCategory::create([
                'company_id' => $this->companyId,
                'description_short' => $name,
                'markup' => 0,
            ])->getKey();
        }

        return $this->categoryCache[$name] = (int) $id;
    }

    private function resolvePaymentMethod(?string $description): ?int
    {
        if ($description === null) {
            return null;
        }
        if (array_key_exists($description, $this->paymentCache)) {
            return $this->paymentCache[$description];
        }
        $id = PaymentMethod::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where('description', $description)
            ->value('id');

        return $this->paymentCache[$description] = $id !== null ? (int) $id : null;
    }

    /* ===================== helpers ===================== */

    private function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim((string) $value);

        return $v === '' ? null : $v;
    }

    /** @param array<int, mixed> $parts */
    private function join(array $parts): ?string
    {
        $clean = array_filter(array_map(fn ($p) => $this->clean($p), $parts), fn ($p) => $p !== null);

        return $clean === [] ? null : implode(' ', $clean);
    }
}
