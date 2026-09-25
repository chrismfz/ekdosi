<?php

namespace Tests\Feature\MyData;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VatCategory;
use App\Support\MyData\Taric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ενιαία Κωδικοποίηση Ειδών (TARIC, 1/1/2027): normalisation to myDATA's exactly-10
 * TaricNo, the ΤΔΑ/9.x-only acceptance rule, and the line SNAPSHOT.
 */
class TaricTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalisation_to_exactly_ten(): void
    {
        $this->assertSame('8471300000', Taric::normalize('84713000'));        // ΣΟ 8 → +«00»
        $this->assertSame('8471300000', Taric::normalize('8471 30 00'));      // spaces allowed
        $this->assertSame('8471300010', Taric::normalize('8471.30.00.10'));   // 10 as is
        $this->assertNull(Taric::normalize(''));
        $this->assertNull(Taric::normalize('847130'));                        // 6 → invalid
        $this->assertTrue(Taric::isValidInput(''));
        $this->assertFalse(Taric::isValidInput('847130'));
    }

    public function test_accepted_only_on_a_tda_or_a_9x(): void
    {
        $this->assertTrue(Taric::appliesTo('9.3'));
        $this->assertTrue(Taric::appliesTo('1.1', true));   // ΤΔΑ
        $this->assertFalse(Taric::appliesTo('1.1'));        // plain sales invoice
        $this->assertFalse(Taric::appliesTo('2.1'));
        $this->assertFalse(Taric::appliesTo('11.1'));
    }

    public function test_the_line_snapshots_the_product_and_keeps_it(): void
    {
        $tenant = Company::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'country_code' => 'GR']);
        $vat = VatCategory::create(['company_id' => $tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
        $cat = ProductCategory::create(['company_id' => $tenant->id, 'description_short' => 'Hardware']);
        $product = Product::create([
            'company_id' => $tenant->id, 'description_short' => 'Server', 'sku' => 'SRV-R640',
            'taric_code' => '8471300000', 'product_category_id' => $cat->id, 'vat_category_id' => $vat->id,
        ]);
        $type = InvoiceType::create(['company_id' => $tenant->id, 'code' => 'ΤΔΑ', 'name' => 'ΤΔΑ', 'invcount' => 1, 'mydata_type' => '1.1']);
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'Π']);
        $invoice = Invoice::create(['company_id' => $tenant->id, 'invcode' => 'X1', 'code' => 1, 'invoice_type_id' => $type->id,
            'customer_id' => $customer->id, 'issued_at' => now(), 'header_discount_percent' => 0]);

        $line = InvoiceLine::create(['company_id' => $tenant->id, 'invoice_id' => $invoice->id, 'product_id' => $product->id,
            'product_descr' => 'Server', 'qty' => 1, 'price_per_item' => 100, 'vat_percent' => 24]);
        $this->assertSame('8471300000', $line->taric_code);
        $this->assertSame('SRV-R640', $line->item_code);

        // Editing the PRODUCT later never rewrites the line (a filed document keeps its code)…
        $product->update(['taric_code' => '8471410000']);
        $line->update(['qty' => 2]);
        $this->assertSame('8471300000', $line->fresh()->taric_code);

        // …only switching the line to another product re-snapshots.
        $other = Product::create(['company_id' => $tenant->id, 'description_short' => 'Switch', 'taric_code' => '8517620000',
            'product_category_id' => $cat->id, 'vat_category_id' => $vat->id]);
        $line->update(['product_id' => $other->id]);
        $this->assertSame('8517620000', $line->fresh()->taric_code);
        $this->assertNull($line->fresh()->item_code);

        // Clearing the product on a draft line clears the codes (no stale TaricNo filed).
        $line->update(['product_id' => null]);
        $this->assertNull($line->fresh()->taric_code);

        // A line created before its product had a code: the builder falls back to the
        // product's current code at filing time.
        $late = Product::create(['company_id' => $tenant->id, 'description_short' => 'Router', 'sku' => 'RT-1',
            'product_category_id' => $cat->id, 'vat_category_id' => $vat->id]);
        $draftLine = InvoiceLine::create(['company_id' => $tenant->id, 'invoice_id' => $invoice->id, 'product_id' => $late->id,
            'product_descr' => 'Router', 'qty' => 1, 'price_per_item' => 50, 'vat_percent' => 24]);
        $late->update(['taric_code' => '8517 62 00']);                        // normalised by the model
        $this->assertSame('8517620000', $late->fresh()->taric_code);
        $this->assertSame(['8517620000', 'RT-1'], Taric::lineCodes($draftLine->fresh()));
    }
}
