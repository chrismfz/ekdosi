<?php

namespace Tests\Feature\Invoice;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\Product;
use App\Models\VatCategory;
use App\Services\RecomputeInvoiceTaxes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The on-save myDATA tax recompute: product-linked per-unit fees + rate-driven
 * amounts, written into the invoice taxesTotals columns.
 */
class RecomputeInvoiceTaxesTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'Tax', 'slug' => 'tax-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'afm' => '800561849',
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1,
        ]);
    }

    private function invoice(array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'company_id' => $this->tenant->id, 'invoice_type_id' => $this->type->id,
            'code' => 1, 'invcode' => 'TPY1', 'issued_at' => now(),
        ], $overrides));
    }

    private function product(array $tax): Product
    {
        $cat = \App\Models\ProductCategory::create([
            'company_id' => $this->tenant->id, 'description_short' => 'Cat',
        ]);

        $vat = VatCategory::create([
            'company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24,
        ]);

        return Product::create(array_merge([
            'company_id' => $this->tenant->id, 'description_short' => 'P', 'sell_price' => 10,
            'product_category_id' => $cat->id, 'vat_category_id' => $vat->id,
        ], $tax));
    }

    private function line(Invoice $inv, ?Product $product, float $qty, float $price = 10): void
    {
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'product_id' => $product?->id, 'qty' => $qty, 'price_per_item' => $price, 'vat_percent' => 24,
        ]);
    }

    public function test_product_linked_fee_aggregates_qty_times_per_unit(): void
    {
        $bag = $this->product(['mydata_tax_type' => 2, 'mydata_tax_category' => 1, 'mydata_tax_per_unit' => 0.07]);
        $inv = $this->invoice();
        $this->line($inv, $bag, qty: 10);

        $inv = app(RecomputeInvoiceTaxes::class)($inv);

        $this->assertSame('0.70', (string) $inv->fees_amount);   // 10 × 0.07
        $this->assertSame(1, $inv->fees_category);
    }

    public function test_rate_driven_amount_is_recomputed_from_net(): void
    {
        $inv = $this->invoice();
        $this->line($inv, null, qty: 1, price: 1000); // net_total 1000 after totals recompute
        $inv->forceFill(['net_total' => 1000, 'stamp_duty_rate' => 3.6, 'stamp_duty_category' => 3])->save();

        $inv = app(RecomputeInvoiceTaxes::class)($inv);

        $this->assertSame('36.00', (string) $inv->stamp_duty_amount); // 3.6% × 1000
    }

    public function test_conflicting_product_categories_for_one_tax_type_throw(): void
    {
        $a = $this->product(['mydata_tax_type' => 2, 'mydata_tax_category' => 1, 'mydata_tax_per_unit' => 0.07]);
        $b = $this->product(['mydata_tax_type' => 2, 'mydata_tax_category' => 5, 'mydata_tax_per_unit' => 0.10]);
        $inv = $this->invoice();
        $this->line($inv, $a, qty: 1);
        $this->line($inv, $b, qty: 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/multiple myDATA categories/');

        app(RecomputeInvoiceTaxes::class)($inv);
    }

    public function test_manual_flat_amount_is_left_untouched(): void
    {
        $inv = $this->invoice();
        $this->line($inv, null, qty: 1);
        $inv->forceFill(['fees_amount' => 12.50, 'fees_category' => 4])->save();

        $inv = app(RecomputeInvoiceTaxes::class)($inv);

        $this->assertSame('12.50', (string) $inv->fees_amount); // no product, no rate → untouched
    }
}
