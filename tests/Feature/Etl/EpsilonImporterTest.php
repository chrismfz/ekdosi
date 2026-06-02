<?php

namespace Tests\Feature\Etl;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\VatCategory;
use App\Services\Etl\EpsilonImporter;
use App\Services\MyData\MyDataLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Drives the Epsilon importer against the REAL sample exports committed at
 * docs/smart-epsilon-export/, with the standard AADE lookups seeded (the
 * fresh-install path the import is designed to land on).
 */
class EpsilonImporterTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'Epsilon Co', 'slug' => 'eps-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        // The lookups the importer resolves against.
        $seeder = app(MyDataLookupSeeder::class);
        $seeder->seedVatCategories($this->tenant);
        $seeder->seedPaymentMethods($this->tenant);
        $seeder->seedMetricUnits($this->tenant);
        $seeder->seedProductCategories($this->tenant);
    }

    private function load(string $name): array
    {
        return json_decode((string) file_get_contents(base_path("docs/smart-epsilon-export/DataExport-{$name}.json")), true);
    }

    public function test_imports_customers_and_maps_fields(): void
    {
        $r = (new EpsilonImporter($this->tenant))->importCustomers($this->load('Customers'));

        // 91 rows, minus the «000000000» retail placeholder(s).
        $this->assertGreaterThan(80, $r['created']);
        $this->assertGreaterThanOrEqual(1, $r['skipped']);

        // No customer carries the placeholder ΑΦΜ.
        $this->assertSame(0, Customer::where('company_id', $this->tenant->id)->where('afm', '000000000')->count());

        // A known real customer is mapped + payment method resolved to a seeded row.
        $myip = Customer::where('company_id', $this->tenant->id)->where('afm', '800561849')->first();
        $this->assertNotNull($myip);
        $this->assertSame('ΞΑΝΘΗΣ', $myip->tax_office);
        $this->assertSame('ΞΑΝΘΗ', $myip->city);
        $this->assertSame('GR', $myip->country);
        $this->assertNotNull($myip->payment_method_id, 'PaymentMethod «Μετρητά» resolved to a seeded row');
    }

    public function test_imports_products_with_vat_unit_category_mapping(): void
    {
        $r = (new EpsilonImporter($this->tenant))->importProducts($this->load('Items'), $this->load('Services'));

        $this->assertGreaterThan(400, $r['created']);

        // A «Κανονικός» (24%) εμπόρευμα → vat 24, category Εμπορεύματα, unit ΤΕΜ.
        $p = Product::where('company_id', $this->tenant->id)
            ->where('description_short', 'Είδος Κανονικό Φ.Π.Α.')
            ->with(['vatCategory', 'productCategory', 'metricUnit'])
            ->first();
        $this->assertNotNull($p);
        $this->assertEquals(24.0, (float) $p->vatCategory->rate);
        $this->assertSame('Εμπορεύματα', $p->productCategory->description_short);
        $this->assertSame('ΤΕΜ', $p->metricUnit?->name);

        // A service lands under «Υπηρεσίες».
        $svc = Product::where('company_id', $this->tenant->id)
            ->where('description_short', 'Υπηρεσία Κανονικό Φ.Π.Α.')
            ->with('productCategory')->first();
        $this->assertNotNull($svc);
        $this->assertSame('Υπηρεσίες', $svc->productCategory->description_short);
    }

    public function test_exempt_class_maps_to_zero_vat(): void
    {
        (new EpsilonImporter($this->tenant))->importProducts($this->load('Items'), $this->load('Services'));

        // At least one «Απαλλασσόμενο» item exists in the export → 0% VAT.
        $zeroRate = VatCategory::where('company_id', $this->tenant->id)->where('rate', 0)->value('id');
        $this->assertSame(
            1,
            Product::where('company_id', $this->tenant->id)->where('vat_category_id', $zeroRate)->where('is_active', true)->limit(1)->count() > 0 ? 1 : 0,
            'an exempt-class item mapped to the 0% VAT category',
        );
    }

    public function test_rerun_is_idempotent(): void
    {
        $importer = new EpsilonImporter($this->tenant);
        $importer->importCustomers($this->load('Customers'));
        $importer->importProducts($this->load('Items'), $this->load('Services'));

        $custBefore = Customer::where('company_id', $this->tenant->id)->count();
        $prodBefore = Product::where('company_id', $this->tenant->id)->count();

        $r2c = (new EpsilonImporter($this->tenant))->importCustomers($this->load('Customers'));
        $r2p = (new EpsilonImporter($this->tenant))->importProducts($this->load('Items'), $this->load('Services'));

        $this->assertSame(0, $r2c['created'], 'second run creates no new customers');
        $this->assertSame(0, $r2p['created'], 'second run creates no new products');
        $this->assertSame($custBefore, Customer::where('company_id', $this->tenant->id)->count());
        $this->assertSame($prodBefore, Product::where('company_id', $this->tenant->id)->count());
    }
}
