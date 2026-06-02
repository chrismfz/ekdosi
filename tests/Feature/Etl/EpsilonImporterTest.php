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
        $seeder->seedInvoiceTypes($this->tenant);
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

    public function test_unknown_vat_class_is_skipped_and_warned_not_billed_at_24(): void
    {
        $importer = new EpsilonImporter($this->tenant);
        $r = $importer->importProducts([[
            'Name' => 'Παράξενο Είδος',
            'VtclName' => 'ΚάτιΆγνωστο',
            'MsntName' => 'Τεμάχιο',
            'AccCategoryName' => 'Εμπόρευμα',
            'WhosalePrice' => 10,
        ]], []);

        $this->assertSame(0, $r['created']);
        $this->assertSame(1, $r['skipped']);
        $this->assertNull(Product::where('company_id', $this->tenant->id)->where('description_short', 'Παράξενο Είδος')->first());
        $this->assertNotEmpty($importer->warnings());
    }

    public function test_rerun_does_not_blank_operator_entered_customer_fields(): void
    {
        $importer = new EpsilonImporter($this->tenant);
        // First import sets ΑΦΜ + name; the source has no email here.
        $importer->importCustomers([['Name' => 'ΑΕ Δοκιμή', 'TIN' => '123456789']]);
        $c = Customer::where('company_id', $this->tenant->id)->where('afm', '123456789')->first();
        // Operator later fills the email in ekdosi.
        $c->forceFill(['email' => 'ops@example.gr'])->save();

        // Re-import (source still lacks email) must NOT blank it.
        (new EpsilonImporter($this->tenant))->importCustomers([['Name' => 'ΑΕ Δοκιμή', 'TIN' => '123456789']]);
        $this->assertSame('ops@example.gr', $c->fresh()->email);
    }

    public function test_imports_sales_as_filed_invoices_with_mark(): void
    {
        $importer = new EpsilonImporter($this->tenant);
        $importer->importCustomers($this->load('Customers'));
        $importer->importProducts($this->load('Items'), $this->load('Services'));

        $r = $importer->importSales($this->load('Sales'));
        $this->assertSame(15, $r['created']);

        $inv = \App\Models\Invoice::where('company_id', $this->tenant->id)->where('invcode', 'ΤΙΜ385')
            ->with(['lines', 'customer'])->first();
        $this->assertNotNull($inv);
        $this->assertSame(385, (int) $inv->code);
        $this->assertSame('active', $inv->local_status);
        $this->assertSame('VALID', $inv->mydata_state);
        $this->assertSame('400013744877362', $inv->mydata_mark, 'leading apostrophe stripped');
        $this->assertEquals(134.20, (float) $inv->net_total);
        $this->assertEquals(166.41, (float) $inv->gross_total);
        // Counterpart matched by ΑΦΜ (TraderTIN 999218818 is in Customers).
        $this->assertSame('999218818', $inv->customer->afm);
        $this->assertGreaterThanOrEqual(1, $inv->lines->count());

        // A myDATA audit mark row was recorded with action INSERT (NOT 'SEND' —
        // cancel / credit-note correlation queries mydata_action='INSERT').
        $this->assertSame(1, \App\Models\MyDataMark::where('invoice_id', $inv->id)
            ->where('mark', '400013744877362')->where('mydata_action', 'INSERT')->count());

        // The ΤΙΜ counter advanced past the imported numbers (next ΑΑ = 386).
        $this->assertSame(386, (int) \App\Models\InvoiceType::where('company_id', $this->tenant->id)->where('code', 'ΤΙΜ')->value('invcount'));
    }

    public function test_sales_store_filed_line_values_verbatim_not_recomputed(): void
    {
        // The 68.40 @24% line: AADE has 84.81; the InvoiceLine recompute hook
        // would store 84.82. importSales must bypass the hook and keep 84.81.
        $importer = new EpsilonImporter($this->tenant);
        $importer->importCustomers($this->load('Customers'));
        $importer->importSales($this->load('Sales'));

        $line = \App\Models\InvoiceLine::where('company_id', $this->tenant->id)
            ->where('price_per_item', 68.40)->where('vat_percent', 24.00)->first();
        $this->assertNotNull($line, 'the 68.40 @24% line exists');
        $this->assertEquals(84.81, (float) $line->gross_price, 'filed gross kept verbatim (not recomputed to 84.82)');

        // And the invoice header gross equals the sum of its own lines.
        $inv = \App\Models\Invoice::find($line->invoice_id);
        $sumGross = round((float) $inv->lines()->sum('gross_price'), 2);
        $this->assertEquals((float) $inv->gross_total, $sumGross, 'header gross == Σ line gross');
    }

    public function test_credit_term_sales_are_settled_not_phantom_receivables(): void
    {
        $importer = new EpsilonImporter($this->tenant);
        $importer->importCustomers($this->load('Customers'));
        $importer->importSales($this->load('Sales'));

        // Every imported invoice carries a non-null payment_status (cache filled)
        // and zero outstanding balance — none is a phantom open receivable.
        $balance = app(\App\Services\InvoiceBalance::class);
        foreach (\App\Models\Invoice::where('company_id', $this->tenant->id)->get() as $inv) {
            $this->assertNotNull($inv->payment_status, "payment_status cached for {$inv->invcode}");
            $this->assertEqualsWithDelta(0.0, $balance->for($inv)->balance, 0.001, "{$inv->invcode} settled");
        }
    }

    public function test_sales_rerun_replaces_lines_not_duplicates(): void
    {
        $importer = new EpsilonImporter($this->tenant);
        $importer->importCustomers($this->load('Customers'));
        $importer->importProducts($this->load('Items'), $this->load('Services'));
        $importer->importSales($this->load('Sales'));

        $invBefore = \App\Models\Invoice::where('company_id', $this->tenant->id)->count();
        $linesBefore = \App\Models\InvoiceLine::where('company_id', $this->tenant->id)->count();

        $r2 = (new EpsilonImporter($this->tenant))->importSales($this->load('Sales'));
        $this->assertSame(0, $r2['created']);
        $this->assertSame(15, $r2['updated']);
        $this->assertSame($invBefore, \App\Models\Invoice::where('company_id', $this->tenant->id)->count());
        $this->assertSame($linesBefore, \App\Models\InvoiceLine::where('company_id', $this->tenant->id)->count(), 'lines replaced, not duplicated');
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
