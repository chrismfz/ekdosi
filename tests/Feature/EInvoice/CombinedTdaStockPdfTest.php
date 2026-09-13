<?php

namespace Tests\Feature\EInvoice;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryNoteLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use App\Models\VatCategory;
use App\Services\InvoicePdfRenderer;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Combined ΤΔΑ — Slice 3e: stock moves exactly ONCE + the invoice PDF carries the
 * movement info.
 *
 * A ΤΔΑ is an `Invoice`, so its goods move through the INVOICE stock path
 * (`recordSaleForInvoice`/`reverseSaleForInvoice` on the local_status transition) and
 * NEVER also the DeliveryNote path — there is no separate linked δελτίο. So a ΤΔΑ
 * produces a single sale-out on issue and a single reversal on cancel.
 */
class CombinedTdaStockPdfTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Product $tracked;

    private InvoiceType $type;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'ΤΔΑ 3e', 'slug' => 'tda3e-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'afm' => '800561849',
        ]);
        $cat = ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'HW', 'markup' => 0]);
        $vat = VatCategory::create(['company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
        $this->tracked = Product::create([
            'company_id' => $this->tenant->id, 'description_short' => 'SSD',
            'product_category_id' => $cat->id, 'vat_category_id' => $vat->id, 'track_stock' => true,
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΤΔΑ', 'name' => 'Τιμολόγιο–Δελτίο Αποστολής',
            'invcount' => 1, 'mydata_type' => '1.1', 'is_delivery_note' => true,
        ]);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Πελάτης', 'afm' => '997073525']);

        app(StockService::class)->record($this->tracked, 10, StockMovement::REASON_INITIAL);
    }

    private function tdaInvoice(array $overrides = []): Invoice
    {
        $invoice = Invoice::create(array_merge([
            'company_id' => $this->tenant->id, 'invcode' => 'ΤΔΑ'.uniqid(), 'code' => random_int(1, 99999),
            'invoice_type_id' => $this->type->id, 'customer_id' => $this->customer->id,
            'issued_at' => now(), 'local_status' => 'draft',
            'company_name' => 'Πελάτης', 'vat_no' => '997073525',
            'is_delivery_note' => true, 'move_purpose' => 1, 'vehicle_number' => 'ΙΑΒ1234', 'transport_type' => 2,
            'loading_street' => 'Φόρτωσης', 'loading_number' => '10', 'loading_postcode' => '11111', 'loading_city' => 'Αθήνα',
            'delivery_street' => 'Παράδοσης', 'delivery_number' => '20', 'delivery_postcode' => '22222', 'delivery_city' => 'Θεσσαλονίκη',
        ], $overrides));
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $invoice->id, 'product_id' => $this->tracked->id,
            'qty' => 3, 'price_per_item' => 10, 'vat_percent' => 24,
        ]);

        return $invoice->fresh('lines');
    }

    public function test_tda_moves_stock_exactly_once_via_the_invoice_path(): void
    {
        $invoice = $this->tdaInvoice();

        $invoice->update(['local_status' => 'active']); // fires InvoiceObserver → recordSaleForInvoice

        // Exactly ONE sale-out, sourced from the InvoiceLine — never the DeliveryNote path.
        $this->assertSame(7.0, app(StockService::class)->currentStock($this->tracked->fresh()));
        $this->assertSame(1, StockMovement::query()
            ->where('product_id', $this->tracked->id)
            ->where('reason', StockMovement::REASON_SALE)
            ->where('source_type', InvoiceLine::class)
            ->count());
        $this->assertSame(0, StockMovement::query()
            ->where('product_id', $this->tracked->id)
            ->where('source_type', DeliveryNoteLine::class)
            ->count(), 'a ΤΔΑ must never move stock through the DeliveryNote path');

        // Cancel reverses it ONCE — back to the opening 10.
        $invoice->update(['local_status' => 'cancelled']); // fires reverseSaleForInvoice
        $this->assertSame(10.0, app(StockService::class)->currentStock($this->tracked->fresh()));
    }

    public function test_invoice_pdf_shows_the_movement_block_for_a_tda(): void
    {
        $invoice = $this->tdaInvoice(['local_status' => 'active']);

        $html = app(InvoicePdfRenderer::class)->renderHtml($invoice);

        // @gup uppercases the heading (Greek-aware); assert the rendered form.
        $this->assertStringContainsString('ΣΤΟΙΧΕΙΑ ΔΙΑΚΙΝΗΣΗΣ', $html);
        $this->assertStringContainsString('Αθήνα', $html);          // loading city
        $this->assertStringContainsString('Θεσσαλονίκη', $html);    // delivery city
        $this->assertStringContainsString('ΙΑΒ1234', $html);        // vehicle
    }

    public function test_invoice_pdf_omits_the_movement_block_for_a_plain_invoice(): void
    {
        $plainType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΤΙΜ', 'name' => 'Τιμολόγιο',
            'invcount' => 1, 'mydata_type' => '1.1', 'is_delivery_note' => false,
        ]);
        $invoice = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΤΙΜ1', 'code' => 1,
            'invoice_type_id' => $plainType->id, 'customer_id' => $this->customer->id,
            'issued_at' => now(), 'local_status' => 'active',
            'company_name' => 'Πελάτης', 'vat_no' => '997073525',
        ]);
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $invoice->id, 'product_id' => $this->tracked->id,
            'qty' => 1, 'price_per_item' => 10, 'vat_percent' => 24,
        ]);

        $html = app(InvoicePdfRenderer::class)->renderHtml($invoice->fresh('lines'));

        $this->assertStringNotContainsString('ΣΤΟΙΧΕΙΑ ΔΙΑΚΙΝΗΣΗΣ', $html);
    }
}
