<?php

namespace Tests\Feature\Products;

use App\Filament\Support\PickerOptions;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\VatCategory;
use App\Services\Products\VariantGenerator;
use App\Services\Stock\StockService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Variants (docs/woocommerce-bridge-plan.md §0): a variable parent + one full
 * product row per colour × size combination.
 */
class VariantGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private ProductCategory $category;

    private VatCategory $vat;

    private ProductAttribute $color;

    private ProductAttribute $size;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->company('Ρούχα');
        [$this->category, $this->vat] = $this->lookups($this->tenant);

        // Size is created FIRST but sorted AFTER colour — the name must follow `sort`.
        $this->size = $this->attribute($this->tenant, 'Μέγεθος', ProductAttribute::KIND_SIZE, 2, ['S', 'M', 'L']);
        $this->color = $this->attribute($this->tenant, 'Χρώμα', ProductAttribute::KIND_COLOR, 1, ['Μαύρο', 'Λευκό'], ['BLK', null]);
    }

    public function test_generates_every_colour_size_combination_as_a_full_product(): void
    {
        $parent = $this->parent(['sku' => 'NK-PANT', 'sell_price' => 40, 'price_wvat' => 49.60, 'track_stock' => true]);

        $created = app(VariantGenerator::class)->generate($parent, $this->pick());

        $this->assertCount(6, $created);
        $first = $created->first();
        $this->assertSame(Product::KIND_VARIANT, $first->kind);
        $this->assertSame($parent->id, $first->parent_product_id);
        $this->assertSame('Παντελόνι Nike — Μαύρο / S', $first->description_short);
        $this->assertSame('NK-PANT-BLK-S', $first->sku);
        $this->assertSame('49.60', (string) $first->price_wvat);
        $this->assertTrue($first->track_stock);
        $this->assertSame($this->vat->id, $first->vat_category_id);
        $this->assertCount(2, $first->variantValues);

        // A value without an explicit code falls back to its ASCII transliteration.
        $this->assertTrue($created->contains(fn (Product $v) => $v->sku === 'NK-PANT-LEFKO-L'));
    }

    public function test_generation_is_idempotent_and_only_adds_missing_combinations(): void
    {
        $parent = $this->parent();
        $gen = app(VariantGenerator::class);

        $gen->generate($parent, [$this->color->id => [$this->valueId($this->color, 'Μαύρο')], $this->size->id => $this->ids($this->size)]);
        $this->assertSame(3, $parent->variants()->count());

        $again = $gen->generate($parent, $this->pick());

        $this->assertCount(3, $again, 'only the three «Λευκό» combinations are new');
        $this->assertSame(6, $parent->variants()->count());
        $this->assertCount(0, $gen->generate($parent, $this->pick()));
    }

    public function test_a_soft_deleted_variant_is_not_recreated(): void
    {
        $parent = $this->parent();
        $gen = app(VariantGenerator::class);
        $gen->generate($parent, $this->pick());

        $parent->variants()->first()->delete();

        $this->assertCount(0, $gen->generate($parent, $this->pick()));
    }

    public function test_generated_sku_is_unique_per_company(): void
    {
        Product::create($this->productData(['description_short' => 'Άλλο', 'sku' => 'NK-PANT-BLK-S']));
        $parent = $this->parent(['sku' => 'NK-PANT']);

        $created = app(VariantGenerator::class)->generate($parent, [
            $this->color->id => [$this->valueId($this->color, 'Μαύρο')],
            $this->size->id => [$this->valueId($this->size, 'S')],
        ]);

        $this->assertSame('NK-PANT-BLK-S-2', $created->first()->sku);
    }

    public function test_long_names_and_skus_keep_the_distinguishing_suffix(): void
    {
        $parent = $this->parent([
            'description_short' => str_repeat('Πολύ μακρύ όνομα ', 10),
            'sku' => str_repeat('X', 40),
        ]);

        $variant = app(VariantGenerator::class)->generate($parent, $this->pick())->first();

        $this->assertLessThanOrEqual(120, mb_strlen($variant->description_short));
        $this->assertStringEndsWith(' — Μαύρο / S', $variant->description_short);
        $this->assertLessThanOrEqual(40, mb_strlen($variant->sku));
        $this->assertStringEndsWith('-BLK-S', $variant->sku);
    }

    public function test_rejects_values_of_another_company_or_another_attribute(): void
    {
        $other = $this->company('Άλλη');
        $foreign = $this->attribute($other, 'Χρώμα', ProductAttribute::KIND_COLOR, 1, ['Κόκκινο']);
        $parent = $this->parent();
        $gen = app(VariantGenerator::class);

        try {
            $gen->generate($parent, [$this->color->id => $this->ids($foreign)]);
            $this->fail('a value of another company must be rejected');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        $gen->generate($parent, [$this->color->id => $this->ids($this->size)]);
    }

    public function test_only_a_variable_product_takes_variants(): void
    {
        $simple = Product::create($this->productData(['description_short' => 'Απλό']));

        $this->expectException(InvalidArgumentException::class);
        app(VariantGenerator::class)->generate($simple, $this->pick());
    }

    public function test_a_simple_product_with_history_cannot_become_variable(): void
    {
        $gen = app(VariantGenerator::class);
        $fresh = Product::create($this->productData(['description_short' => 'Νέο', 'track_stock' => true]));
        $this->assertTrue($gen->canBecomeVariable($fresh));

        app(StockService::class)->record($fresh, 5, StockMovement::REASON_RECEIPT);
        $this->assertFalse($gen->canBecomeVariable($fresh));
        $this->assertFalse($gen->canBecomeVariable($this->parent()), 'already variable');
    }

    public function test_sync_from_parent_copies_only_the_chosen_groups(): void
    {
        $parent = $this->parent(['sell_price' => 40, 'price_wvat' => 49.60, 'supplier' => 'Nike']);
        $gen = app(VariantGenerator::class);
        $gen->generate($parent, $this->pick());

        $odd = $parent->variants()->first();
        $odd->update(['sell_price' => 45, 'price_wvat' => 55.80, 'description_short' => 'χειροκίνητο']);

        $parent->update(['sell_price' => 30, 'price_wvat' => 37.20, 'supplier' => 'Adidas', 'description_short' => 'Φόρμα']);

        $this->assertSame(6, $gen->syncFromParent($parent, ['prices']));
        $odd->refresh();
        $this->assertSame('37.20', (string) $odd->price_wvat);
        $this->assertSame('Nike', $odd->supplier, 'catalogue group not chosen');
        $this->assertSame('χειροκίνητο', $odd->description_short, 'names not chosen');

        $gen->syncFromParent($parent, ['names']);
        $this->assertSame('Φόρμα — Μαύρο / S', $odd->refresh()->description_short);
    }

    public function test_variable_parent_never_appears_in_the_document_pickers(): void
    {
        $parent = $this->parent();
        app(VariantGenerator::class)->generate($parent, [
            $this->color->id => [$this->valueId($this->color, 'Μαύρο')],
            $this->size->id => [$this->valueId($this->size, 'M')],
        ]);
        $this->actingAsTenant();

        $options = PickerOptions::searchProductOptions('Nike');

        $this->assertArrayNotHasKey($parent->id, $options);
        $this->assertCount(1, $options);
        $this->assertStringContainsString('Μαύρο / M', reset($options));
        $this->assertArrayNotHasKey($parent->id, PickerOptions::favouriteProductOptions());
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function actingAsTenant(): void
    {
        $this->actingAs(User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('x'),
        ]));
        Filament::setTenant($this->tenant);
    }

    /** @return array<int, list<int>> every value of both axes */
    private function pick(): array
    {
        return [$this->color->id => $this->ids($this->color), $this->size->id => $this->ids($this->size)];
    }

    /** @return list<int> */
    private function ids(ProductAttribute $attribute): array
    {
        return $attribute->values()->pluck('id')->all();
    }

    private function valueId(ProductAttribute $attribute, string $value): int
    {
        return (int) $attribute->values()->where('value', $value)->value('id');
    }

    private function parent(array $overrides = []): Product
    {
        return Product::create($this->productData(array_merge([
            'kind' => Product::KIND_VARIABLE,
            'description_short' => 'Παντελόνι Nike',
        ], $overrides)));
    }

    private function productData(array $overrides): array
    {
        return array_merge([
            'company_id' => $this->tenant->id,
            'product_category_id' => $this->category->id,
            'vat_category_id' => $this->vat->id,
        ], $overrides);
    }

    private function company(string $name): Company
    {
        return Company::create(['name' => $name, 'slug' => 'var-'.uniqid(), 'country_code' => 'GR']);
    }

    /** @return array{0: ProductCategory, 1: VatCategory} */
    private function lookups(Company $company): array
    {
        return [
            ProductCategory::create(['company_id' => $company->id, 'description_short' => 'Ένδυση', 'markup' => 0]),
            VatCategory::create(['company_id' => $company->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]),
        ];
    }

    /**
     * @param  list<string>  $values
     * @param  list<string|null>  $codes
     */
    private function attribute(Company $company, string $name, string $kind, int $sort, array $values, array $codes = []): ProductAttribute
    {
        $attribute = ProductAttribute::create(['company_id' => $company->id, 'name' => $name, 'kind' => $kind, 'sort' => $sort]);
        foreach ($values as $i => $value) {
            ProductAttributeValue::create([
                'company_id' => $company->id,
                'product_attribute_id' => $attribute->id,
                'value' => $value,
                'code' => $codes[$i] ?? null,
                'sort' => $i,
            ]);
        }

        return $attribute;
    }
}
