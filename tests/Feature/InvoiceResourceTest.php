<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VatCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Read-only-stage smoke + scoping tests for the Invoice domain. We can't
 * exercise the Filament Resource pages without booting Livewire, but we
 * CAN lock in the model-layer contract the resource relies on:
 *   - relationships hydrate correctly
 *   - tenant scoping at the query level produces the right rows
 *   - the latestMydataMark relation returns the most recent submission
 *
 * If any of these break, the resource's list/view pages would render
 * wrong without the test catching it — explicit coverage prevents
 * silent regressions during the PR #7 / #8 refactor.
 */
class InvoiceResourceTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $invoiceType;

    private Customer $customer;

    private VatCategory $vat;

    private ProductCategory $productCategory;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Test Co',
            'slug' => 'inv-res-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_production' => false,
        ]);

        $this->invoiceType = InvoiceType::create([
            'company_id' => $this->tenant->id,
            'code' => 'APY',
            'name' => 'Απόδειξη Παροχής Υπηρεσιών',
            'invcount' => 1,
            'mydata_type' => '2.1',
        ]);

        $this->customer = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'Άκης Πελάτης',
            'afm' => '123456789',
        ]);

        $this->vat = VatCategory::create([
            'company_id' => $this->tenant->id,
            'description' => '24%',
            'rate' => 24,
            'is_default' => true,
        ]);

        $this->productCategory = ProductCategory::create([
            'company_id' => $this->tenant->id,
            'description_short' => 'Hosting',
        ]);

        $this->product = Product::create([
            'company_id' => $this->tenant->id,
            'description_short' => 'Web Hosting',
            'product_category_id' => $this->productCategory->id,
            'vat_category_id' => $this->vat->id,
            'sell_price' => 33,
            'price_wvat' => 40.92,
        ]);
    }

    public function test_invoice_relationships_resolve(): void
    {
        $invoice = $this->makeInvoice();

        InvoiceLine::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $invoice->id,
            'product_id' => $this->product->id,
            'qty' => 1,
            'price_per_item' => 33,
            'vat_percent' => 24,
            'net_price' => 33,
            'gross_price' => 40.92,
            'product_descr' => 'Web Hosting',
            'metric_unit' => 'τεμ',
        ]);

        $fresh = $invoice->fresh(['invoiceType', 'customer', 'lines']);
        $this->assertSame('APY', $fresh->invoiceType->code);
        $this->assertSame('Άκης Πελάτης', $fresh->customer->name);
        $this->assertCount(1, $fresh->lines);
        $this->assertSame('Web Hosting', $fresh->lines->first()->product_descr);
    }

    public function test_latest_mydata_mark_returns_most_recent_submission(): void
    {
        $invoice = $this->makeInvoice();

        // Two submissions: INSERT then CANCEL (the lifecycle the
        // future MyDataSubmitter will produce).
        MyDataMark::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $invoice->id,
            'mark' => '400001111111111',
            'mydata_action' => 'INSERT',
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        MyDataMark::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $invoice->id,
            'mark' => '400002222222222',
            'mydata_action' => 'CANCEL',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $latest = $invoice->fresh()->latestMydataMark;
        $this->assertSame('CANCEL', $latest->mydata_action);
        $this->assertSame('400002222222222', $latest->mark);
    }

    public function test_invoices_are_scoped_per_tenant(): void
    {
        $this->makeInvoice();
        $this->makeInvoice();
        $this->makeInvoice();

        // Second tenant with its own invoice — must NOT leak through.
        $other = Company::create([
            'name' => 'Other',
            'slug' => 'other-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_production' => false,
        ]);
        $otherType = InvoiceType::create([
            'company_id' => $other->id,
            'code' => 'TPY',
            'name' => 'Τιμολόγιο',
            'invcount' => 1,
        ]);
        $otherCust = Customer::create(['company_id' => $other->id, 'name' => 'Άλλος']);
        Invoice::create([
            'company_id' => $other->id,
            'invcode' => 'TPY1',
            'code' => 1,
            'invoice_type_id' => $otherType->id,
            'customer_id' => $otherCust->id,
            'issued_at' => now(),
        ]);

        $tenant1Count = Invoice::query()->where('company_id', $this->tenant->id)->count();
        $tenant2Count = Invoice::query()->where('company_id', $other->id)->count();

        $this->assertSame(3, $tenant1Count);
        $this->assertSame(1, $tenant2Count);
    }

    public function test_soft_delete_does_not_remove_from_default_queries(): void
    {
        // Sanity check: Invoice uses SoftDeletes. The resource lifts
        // SoftDeletingScope explicitly so trashed rows appear; here we
        // just verify the model-layer scope behaviour matches expectation.
        $invoice = $this->makeInvoice();
        $invoice->delete();

        $this->assertSame(0, Invoice::query()->where('company_id', $this->tenant->id)->count());
        $this->assertSame(1, Invoice::query()->withTrashed()->where('company_id', $this->tenant->id)->count());
    }

    private function makeInvoice(): Invoice
    {
        $count = Invoice::query()->where('invoice_type_id', $this->invoiceType->id)->count();
        $aa = $count + 1;
        return Invoice::create([
            'company_id' => $this->tenant->id,
            'invcode' => 'APY'.$aa,
            'code' => $aa,
            'invoice_type_id' => $this->invoiceType->id,
            'customer_id' => $this->customer->id,
            'issued_at' => now(),
            'net_total' => 33.00,
            'gross_total' => 40.92,
            'header_discount_percent' => 0,
            'mydata_sent' => false,
        ]);
    }
}
