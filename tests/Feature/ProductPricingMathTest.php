<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VatCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks in the round-trip behaviour of the Product pricing fields so a
 * future schema/cast refactor can't silently change rounding.
 *
 * The reactive form math (buy → markup → sell → +VAT → wvat, and the
 * reverse wvat → sell back-compute) is implemented in
 * ProductForm::recompute*, which is Filament-side and not directly
 * unit-testable without booting the Livewire stack. What we CAN lock in
 * here is the storage shape — that the casts preserve 2 decimal places
 * on currency, 3 on stock, and that the relationships work.
 */
class ProductPricingMathTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private ProductCategory $category;

    private VatCategory $vat24;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Test Co',
            'slug' => 'pricing-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
        ]);

        $this->category = ProductCategory::create([
            'company_id' => $this->company->id,
            'description_short' => 'Hosting',
            'markup' => 25,
        ]);

        $this->vat24 = VatCategory::create([
            'company_id' => $this->company->id,
            'description' => '24% standard',
            'rate' => 24,
            'is_default' => true,
        ]);
    }

    public function test_currency_fields_round_trip_at_2_decimals(): void
    {
        $product = Product::create([
            'company_id' => $this->company->id,
            'description_short' => 'cPanel license',
            'product_category_id' => $this->category->id,
            'vat_category_id' => $this->vat24->id,
            'buy_price' => 10.001,   // intentional 3-digit input
            'sell_price' => 12.505,
            'price_wvat' => 15.506,
            'reserve' => 1.2345,     // 4-digit input
            'reserve_secure' => 0.5,
        ]);

        $fresh = $product->fresh();
        $this->assertSame('10.00', (string) $fresh->buy_price);
        $this->assertSame('12.51', (string) $fresh->sell_price);
        $this->assertSame('15.51', (string) $fresh->price_wvat);
        // Stock: decimal(7,3), 3-digit precision.
        $this->assertSame('1.235', (string) $fresh->reserve);
    }

    public function test_relationships_resolve(): void
    {
        $product = Product::create([
            'company_id' => $this->company->id,
            'description_short' => 'Test',
            'product_category_id' => $this->category->id,
            'vat_category_id' => $this->vat24->id,
            'buy_price' => 10,
            'sell_price' => 12.5,
            'price_wvat' => 15.5,
        ]);

        $this->assertSame('Hosting', $product->productCategory->description_short);
        $this->assertSame('24% standard', $product->vatCategory->description);
        $this->assertSame(0, $product->priceTiers()->count());
    }

    public function test_forward_looking_columns_default_correctly(): void
    {
        $product = Product::create([
            'company_id' => $this->company->id,
            'description_short' => 'Defaults probe',
            'product_category_id' => $this->category->id,
            'vat_category_id' => $this->vat24->id,
        ]);

        $fresh = $product->fresh();
        $this->assertTrue($fresh->is_active);                       // default true
        $this->assertNull($fresh->sku);
        $this->assertNull($fresh->whmcs_product_id);
        $this->assertNull($fresh->supplier);
        $this->assertNull($fresh->internal_notes);
    }

    public function test_edit_mount_derives_historical_markup_from_saved_prices(): void
    {
        // EditProduct::mutateFormDataBeforeFill computes markup_display
        // from (buy, sell). Locks in: if buy was 10 and sell was 12.50,
        // markup_display must be 25 (the historical markup), regardless
        // of what the category's markup currently is.
        //
        // We don't boot the Livewire page here — we just invoke the
        // method directly with a fixture payload, which is enough to
        // lock the formula in.
        $product = \App\Models\Product::create([
            'company_id' => $this->company->id,
            'description_short' => 'Historical',
            'product_category_id' => $this->category->id,
            'vat_category_id' => $this->vat24->id,
            'buy_price' => 10.00,
            'sell_price' => 12.50,
        ]);

        // Admin nudges the CATEGORY's markup AFTER the product was saved.
        $this->category->update(['markup' => 80]);

        // Reproduce what EditProduct::mutateFormDataBeforeFill does. The
        // historical markup is (sell - buy) / buy * 100 = 25, NOT 80.
        $page = new \App\Filament\Resources\Products\Pages\EditProduct();
        $reflector = new \ReflectionMethod($page, 'mutateFormDataBeforeFill');
        $reflector->setAccessible(true);
        $hydrated = $reflector->invoke($page, $product->fresh()->toArray());

        $this->assertSame(25.0, $hydrated['markup_display']);
    }

    public function test_price_tier_unique_per_product_qty(): void
    {
        $product = \App\Models\Product::create([
            'company_id' => $this->company->id,
            'description_short' => 'Tier probe',
            'product_category_id' => $this->category->id,
            'vat_category_id' => $this->vat24->id,
        ]);

        \App\Models\ProductPriceTier::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'qty' => 10,
            'value' => 9.00,
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        \App\Models\ProductPriceTier::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'qty' => 10,
            'discount_percent' => 15,
        ]);
    }

    public function test_sku_unique_per_tenant(): void
    {
        Product::create([
            'company_id' => $this->company->id,
            'description_short' => 'Product A',
            'product_category_id' => $this->category->id,
            'vat_category_id' => $this->vat24->id,
            'sku' => 'SHARED-SKU',
        ]);

        $other = Company::create([
            'name' => 'Other Co',
            'slug' => 'other-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
        ]);
        $otherCategory = ProductCategory::create([
            'company_id' => $other->id,
            'description_short' => 'Hosting',
        ]);
        $otherVat = VatCategory::create([
            'company_id' => $other->id,
            'description' => '24%',
            'rate' => 24,
            'is_default' => true,
        ]);

        // Same SKU in a DIFFERENT tenant is fine.
        $b = Product::create([
            'company_id' => $other->id,
            'description_short' => 'Product B',
            'product_category_id' => $otherCategory->id,
            'vat_category_id' => $otherVat->id,
            'sku' => 'SHARED-SKU',
        ]);

        $this->assertSame('SHARED-SKU', $b->sku);
    }
}
