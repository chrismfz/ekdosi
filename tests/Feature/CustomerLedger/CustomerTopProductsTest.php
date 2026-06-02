<?php

namespace Tests\Feature\CustomerLedger;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Services\CustomerLedger\CustomerTopProducts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerTopProductsTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;
    private InvoiceType $invType;
    private int $catId;
    private int $vatId;
    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'T', 'slug' => 'tp-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $pm = PaymentMethod::create(['company_id' => $this->tenant->id, 'name' => 'Cash', 'due_days' => 0, 'is_active' => true]);
        $this->invType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'name' => 'TPY', 'code' => 'TPY',
            'invcount' => 0, 'payment_method_id' => $pm->id,
        ]);
        $this->catId = \App\Models\ProductCategory::create([
            'company_id' => $this->tenant->id, 'description_short' => 'Γενικά',
        ])->id;
        $this->vatId = \App\Models\VatCategory::create([
            'company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24,
        ])->id;
    }

    private function invoice(Customer $c, string $issuedAt, array $overrides = []): Invoice
    {
        self::$seq++;
        return Invoice::create(array_merge([
            'company_id' => $this->tenant->id,
            'customer_id' => $c->id,
            'invoice_type_id' => $this->invType->id,
            'invcode' => 'TP'.self::$seq,
            'code' => self::$seq,
            'issued_at' => $issuedAt,
            'gross_total' => 124,
            'net_total' => 100,
            'local_status' => 'active',
        ], $overrides));
    }

    private function line(Invoice $inv, ?Product $p, float $qty, float $price, string $descr = 'X'): void
    {
        InvoiceLine::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $inv->id,
            'product_id' => $p?->id,
            'qty' => $qty,
            'price_per_item' => $price,
            'vat_percent' => 24,
            'product_descr' => $descr,
            'metric_unit' => 'ΤΕΜ',
        ]);
    }

    public function test_aggregates_top_products_by_frequency_and_excludes_credit_and_cancelled(): void
    {
        $c = Customer::create(['company_id' => $this->tenant->id, 'name' => 'K']);

        $ups = Product::create(['company_id' => $this->tenant->id, 'sku' => 'UPS1', 'description_short' => 'UPS 850VA', 'product_category_id' => $this->catId, 'vat_category_id' => $this->vatId]);
        $cable = Product::create(['company_id' => $this->tenant->id, 'sku' => 'CBL', 'description_short' => 'Καλώδιο', 'product_category_id' => $this->catId, 'vat_category_id' => $this->vatId]);

        // UPS bought on 3 live invoices; cable on 1.
        $this->line($this->invoice($c, '2026-01-10'), $ups, 1, 50);
        $this->line($this->invoice($c, '2026-02-10'), $ups, 2, 50);
        $latest = $this->invoice($c, '2026-03-10');
        $this->line($latest, $ups, 1, 50);
        $this->line($this->invoice($c, '2026-01-15'), $cable, 5, 2);

        // A credit note (positive line) must NOT count as a purchase.
        $credit = $this->invoice($c, '2026-04-01', ['credited_invoice_id' => $latest->id]);
        $this->line($credit, $ups, 10, 50);

        // A cancelled invoice must be excluded (not live).
        $this->line($this->invoice($c, '2026-04-02', ['local_status' => 'cancelled']), $ups, 99, 50);

        $rows = app(CustomerTopProducts::class)->for($c);

        $this->assertCount(2, $rows);

        // UPS first (3 times), cable second (1).
        $this->assertSame($ups->id, $rows[0]['product_id']);
        $this->assertSame(3, $rows[0]['times']);
        $this->assertSame(4.0, $rows[0]['qty']);          // 1+2+1, credit/cancelled excluded
        $this->assertSame(200.0, $rows[0]['net']);        // (1+2+1)*50
        $this->assertSame('UPS 850VA', $rows[0]['label']);
        $this->assertStringStartsWith('2026-03-10', $rows[0]['last_at']);

        $this->assertSame($cable->id, $rows[1]['product_id']);
        $this->assertSame(1, $rows[1]['times']);
    }

    public function test_free_text_lines_without_product_are_grouped_by_description(): void
    {
        $c = Customer::create(['company_id' => $this->tenant->id, 'name' => 'K']);

        $this->line($this->invoice($c, '2026-01-10'), null, 1, 10, 'Έξοδα αποστολής');
        $this->line($this->invoice($c, '2026-02-10'), null, 1, 10, 'έξοδα αποστολής'); // case-insensitive same

        $rows = app(CustomerTopProducts::class)->for($c);

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['product_id']);
        $this->assertSame(2, $rows[0]['times']);
    }
}
