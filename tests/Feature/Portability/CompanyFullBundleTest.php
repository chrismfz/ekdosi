<?php

namespace Tests\Feature\Portability;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ServiceContract;
use App\Models\VatCategory;
use App\Services\Portability\BundleArchive;
use App\Services\Portability\CompanyExporter;
use App\Services\Portability\CompanyImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Full (--full) bundle: a complete per-company snapshot round-trips into a fresh
 * company with every transactional FK rewired to the NEW ids (incl. the credit-
 * note self-reference).
 */
class CompanyFullBundleTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_bundle_round_trips_with_fk_rewiring(): void
    {
        $src = Company::create([
            'name' => 'Full OE', 'slug' => 'full', 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'afm' => '800561849',
        ]);
        $vat = VatCategory::create(['company_id' => $src->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
        $cat = ProductCategory::create(['company_id' => $src->id, 'description_short' => 'ΥΠ', 'description' => 'Υπηρεσίες']);
        $pm = PaymentMethod::create(['company_id' => $src->id, 'description' => 'Μετρητά', 'due_days' => 0]);
        $type = InvoiceType::create(['company_id' => $src->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 9, 'mydata_type' => '2.1']);

        $cust = Customer::create(['company_id' => $src->id, 'name' => 'NEXON OE', 'afm' => '801280908', 'payment_method_id' => $pm->id]);
        $prod = Product::create(['company_id' => $src->id, 'description_short' => 'Hosting', 'description' => 'Hosting', 'product_category_id' => $cat->id, 'vat_category_id' => $vat->id]);

        $inv = Invoice::create([
            'company_id' => $src->id, 'invcode' => 'TPY1', 'code' => 1,
            'invoice_type_id' => $type->id, 'customer_id' => $cust->id, 'payment_method_id' => $pm->id,
            'issued_at' => now(), 'header_discount_percent' => 0,
        ]);
        // A FK to a DEFERRED table (service_contracts) — must be nulled on import,
        // not crash. And a setup→transactional FK (default_customer_id) to restore.
        $sc = ServiceContract::create(['company_id' => $src->id, 'customer_id' => $cust->id, 'billing_cycle' => 'monthly']);
        $inv->forceFill(['service_contract_id' => $sc->id])->save();
        $type->forceFill(['default_customer_id' => $cust->id])->save();

        $line = InvoiceLine::create([
            'company_id' => $src->id, 'invoice_id' => $inv->id, 'product_id' => $prod->id,
            'qty' => 1, 'price_per_item' => 100, 'vat_percent' => 24, 'product_descr' => 'Hosting',
        ]);
        $mark = MyDataMark::create([
            'company_id' => $src->id, 'invoice_id' => $inv->id, 'mark' => '400000000000001',
            'mydata_action' => 'INSERT', 'mark_date' => now()->toDateString(), 'mark_time' => now()->toTimeString(),
        ]);
        $payment = Payment::create([
            'company_id' => $src->id, 'customer_id' => $cust->id, 'invoice_id' => $inv->id,
            'payment_method_id' => $pm->id, 'amount' => 124, 'paid_at' => now(),
        ]);
        // A credit note pointing back at the original (self-reference).
        $credit = Invoice::create([
            'company_id' => $src->id, 'invcode' => 'PIS1', 'code' => 1,
            'invoice_type_id' => $type->id, 'customer_id' => $cust->id,
            'issued_at' => now(), 'header_discount_percent' => 0,
        ]);
        $credit->forceFill(['credited_invoice_id' => $inv->id])->save();

        $bundle = app(CompanyExporter::class)->build($src, 'passphrase', 'p@ss', true);
        $this->assertSame('company-full', $bundle['manifest']['kind']);

        // Simulate another VM: free the slug/afm so --new creates a fresh company
        // (renaming avoids cascade-delete FK ordering noise under sqlite).
        Company::where('slug', 'full')->update(['slug' => 'full-src', 'afm' => '000000000']);

        app(CompanyImporter::class)->run($bundle, ['new' => true, 'execute' => true, 'passphrase' => 'p@ss']);

        $company = Company::where('slug', 'full')->firstOrFail();
        $newCust = Customer::where('company_id', $company->id)->where('afm', '801280908')->firstOrFail();
        $newProd = Product::where('company_id', $company->id)->where('description_short', 'Hosting')->firstOrFail();
        $newPm = PaymentMethod::where('company_id', $company->id)->firstOrFail();
        $newInv = Invoice::where('company_id', $company->id)->where('invcode', 'TPY1')->firstOrFail();
        $newCredit = Invoice::where('company_id', $company->id)->where('invcode', 'PIS1')->firstOrFail();

        // Customer FK on the customer itself + on the invoice rewired to NEW ids.
        $this->assertSame($newPm->id, $newCust->payment_method_id);
        $this->assertSame($newCust->id, $newInv->customer_id);
        $this->assertNotSame($cust->id, $newInv->customer_id); // genuinely re-mapped

        // Line / mark / payment children point at the NEW invoice + product.
        $newLine = InvoiceLine::where('invoice_id', $newInv->id)->firstOrFail();
        $this->assertSame($newProd->id, $newLine->product_id);
        $this->assertSame(1, MyDataMark::where('invoice_id', $newInv->id)->count());
        $newPay = Payment::where('invoice_id', $newInv->id)->firstOrFail();
        $this->assertSame($newCust->id, $newPay->customer_id);

        // The credit note's self-reference resolves to the NEW original invoice id.
        $this->assertSame($newInv->id, $newCredit->credited_invoice_id);

        // FK to a DEFERRED table is nulled (not a dangling/violating source id).
        $this->assertNull($newInv->service_contract_id);

        // invoice_types.default_customer_id (setup→transactional) restored post-pass.
        $newType = InvoiceType::where('company_id', $company->id)->where('code', 'TPY')->firstOrFail();
        $this->assertSame($newCust->id, $newType->default_customer_id);
    }

    /**
     * Regression: the full bundle's transactional data MUST survive the .zip
     * write→read (BundleArchive once serialized only `setup/`, so a "full" backup
     * silently shipped zero invoices/payments). The other test exercises the
     * in-memory array; this one goes through the archive — the real backup path.
     */
    public function test_full_bundle_transactional_data_survives_the_zip_roundtrip(): void
    {
        $src = Company::create(['name' => 'Zip OE', 'slug' => 'zip', 'country_code' => 'GR', 'afm' => '800561849']);
        $type = InvoiceType::create(['company_id' => $src->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1, 'mydata_type' => '2.1']);
        $cust = Customer::create(['company_id' => $src->id, 'name' => 'NEXON OE', 'afm' => '801280908']);
        Invoice::create([
            'company_id' => $src->id, 'invcode' => 'TPY1', 'code' => 1,
            'invoice_type_id' => $type->id, 'customer_id' => $cust->id, 'issued_at' => now(), 'header_discount_percent' => 0,
        ]);

        $bundle = app(CompanyExporter::class)->build($src, 'raw', null, true);
        $this->assertNotEmpty($bundle['data']['invoices'] ?? [], 'precondition: build produced transactional data');

        $path = storage_path('app/tmp/zip-roundtrip-'.uniqid().'.zip');
        app(BundleArchive::class)->write($path, $bundle);
        $read = app(BundleArchive::class)->read($path);
        @unlink($path);

        // THE regression assertion: data/ round-trips through the zip.
        $this->assertArrayHasKey('data', $read);
        $this->assertCount(count($bundle['data']['invoices']), $read['data']['invoices'] ?? []);

        // …and it imports from the READ-BACK bundle into a fresh company.
        Company::where('slug', 'zip')->update(['slug' => 'zip-src', 'afm' => '000000000']);
        app(CompanyImporter::class)->run($read, ['new' => true, 'execute' => true, 'passphrase' => null]);

        $company = Company::where('slug', 'zip')->firstOrFail();
        $this->assertSame(1, Invoice::where('company_id', $company->id)->where('invcode', 'TPY1')->count());
    }
}
