<?php

namespace Tests\Feature\Products;

use App\Filament\Resources\ProductAttributes\Pages\CreateProductAttribute;
use App\Filament\Resources\ProductAttributes\Pages\EditProductAttribute;
use App\Filament\Resources\ProductAttributes\Pages\ListProductAttributes;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\RelationManagers\PriceTiersRelationManager;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
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
use App\Support\Products\StockDisplay;
use App\Support\Products\VariantStockGrid;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The variant screens boot and behave: attributes CRUD (values repeater + the
 * in-use guard), the variable product's grid + «Παραλλαγές» tab + generation,
 * the list's default hiding of variants, and simple→variable conversion.
 */
class VariantScreensTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private ProductCategory $category;

    private VatCategory $vat;

    /** Flip to simulate a user who may view products but not update them. */
    private bool $denyUpdate = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create(['name' => 'Ρούχα', 'slug' => 'vs-'.uniqid(), 'country_code' => 'GR']);
        $this->category = ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'Ένδυση', 'markup' => 0]);
        $this->vat = VatCategory::create(['company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);

        Gate::before(fn ($user, string $ability): bool => ! ($this->denyUpdate && $ability === 'update'));
        $this->actingAs(User::create(['name' => 'Op', 'email' => 'vs-'.uniqid().'@example.test', 'password' => bcrypt('x')]));
        Filament::setTenant($this->tenant);
    }

    public function test_attribute_with_values_is_created_from_the_form(): void
    {
        Livewire::test(ListProductAttributes::class)->assertOk();

        Livewire::test(CreateProductAttribute::class)
            ->fillForm([
                'name' => 'Μέγεθος',
                'kind' => ProductAttribute::KIND_SIZE,
                'sort' => 2,
                'values' => [
                    ['value' => 'S', 'code' => null],
                    ['value' => 'M', 'code' => null],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $attribute = ProductAttribute::where('company_id', $this->tenant->id)->where('name', 'Μέγεθος')->firstOrFail();
        $this->assertSame(['S', 'M'], $attribute->values()->pluck('value')->all());
        $this->assertSame([$this->tenant->id], $attribute->values()->pluck('company_id')->unique()->values()->all());
    }

    public function test_values_differing_only_in_case_or_accent_are_rejected_like_the_db_index_would(): void
    {
        foreach ([['M', 'm'], ['Μαύρο', 'ΜΑΥΡΟ'], ['Μαύρο', 'Μαυρο']] as [$a, $b]) {
            Livewire::test(CreateProductAttribute::class)
                ->fillForm([
                    'name' => 'Χ-'.$a.$b,
                    'kind' => ProductAttribute::KIND_OTHER,
                    'values' => [['value' => $a], ['value' => $b]],
                ])
                ->call('create')
                ->assertHasFormErrors();
        }
        $this->assertSame(0, ProductAttributeValue::where('company_id', $this->tenant->id)->count());

        Livewire::test(CreateProductAttribute::class)
            ->fillForm(['name' => 'Σειρά', 'kind' => ProductAttribute::KIND_OTHER, 'sort' => 70000])
            ->call('create')
            ->assertHasFormErrors(['sort']);
    }

    public function test_removing_a_value_used_by_variants_is_refused(): void
    {
        [$color, $size] = $this->axes();
        $parent = $this->variableParent();
        app(VariantGenerator::class)->generate($parent, [$size->id => $size->values()->pluck('id')->all()]);

        $usedValue = $size->values()->where('value', 'S')->first();

        $page = Livewire::test(EditProductAttribute::class, ['record' => $size->getKey()]);
        $state = $page->get('data.values');
        unset($state['record-'.$usedValue->id]);

        $page->set('data.values', $state)->call('save');

        $this->assertDatabaseHas('product_attribute_values', ['id' => $usedValue->id]);

        // …while an UNUSED value is removed normally (proves the repeater keys are what the guard reads).
        $unused = ProductAttributeValue::create([
            'company_id' => $this->tenant->id, 'product_attribute_id' => $size->id, 'value' => 'XL', 'sort' => 9,
        ]);
        $page = Livewire::test(EditProductAttribute::class, ['record' => $size->getKey()]);
        $state = $page->get('data.values');
        $this->assertArrayHasKey('record-'.$unused->id, $state);
        unset($state['record-'.$unused->id]);
        $page->set('data.values', $state)->call('save')->assertHasNoFormErrors();

        $this->assertDatabaseMissing('product_attribute_values', ['id' => $unused->id]);
        $this->assertDatabaseHas('product_attribute_values', ['id' => $usedValue->id]);
    }

    public function test_a_new_repeater_row_whose_uuid_starts_with_a_used_id_does_not_fool_the_guard(): void
    {
        [, $size] = $this->axes();
        $parent = $this->variableParent();
        app(VariantGenerator::class)->generate($parent, [$size->id => $size->values()->pluck('id')->all()]);
        $used = $size->values()->where('value', 'S')->first();

        $page = Livewire::test(EditProductAttribute::class, ['record' => $size->getKey()]);
        $state = $page->get('data.values');
        unset($state['record-'.$used->id]);
        // A NEW row keyed by a UUID that begins with the removed row's id.
        $state[$used->id.'ab9c3e-1111-4222-8333-944455556666'] = ['value' => 'XXL', 'code' => null, 'color_hex' => null];
        $page->set('data.values', $state)->call('save');

        $this->assertDatabaseHas('product_attribute_values', ['id' => $used->id]);
    }

    public function test_values_used_only_by_deleted_variants_can_be_removed(): void
    {
        [, $size] = $this->axes();
        $parent = $this->variableParent();
        app(VariantGenerator::class)->generate($parent, [$size->id => $size->values()->pluck('id')->all()]);
        $value = $size->values()->where('value', 'S')->first();
        $parent->variants()->get()->each->delete();

        $page = Livewire::test(EditProductAttribute::class, ['record' => $size->getKey()]);
        $state = $page->get('data.values');
        unset($state['record-'.$value->id]);
        $page->set('data.values', $state)->call('save')->assertHasNoFormErrors();

        $this->assertDatabaseMissing('product_attribute_values', ['id' => $value->id]);
        $this->assertDatabaseMissing('product_variant_values', ['product_attribute_value_id' => $value->id]);
    }

    public function test_restoring_a_variant_is_refused_when_its_parent_is_gone_or_its_axes_changed(): void
    {
        [$color, $size] = $this->axes();
        $parent = $this->variableParent();
        $gen = app(VariantGenerator::class);
        $gen->generate($parent, [$size->id => $size->values()->pluck('id')->all()]);
        $old = $parent->variants()->first();
        $parent->variants()->get()->each->delete();

        // Axes changed: colour × size is the live set now.
        $gen->generate($parent, [
            $color->id => $color->values()->pluck('id')->all(),
            $size->id => $size->values()->pluck('id')->all(),
        ]);
        Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $parent, 'pageClass' => EditProduct::class])
            ->filterTable('trashed', false)
            ->callTableAction('restore', $old);
        $this->assertSoftDeleted($old);
        $this->assertFalse($old->restore(), 'the model hook refuses it on any path');

        // Parent gone: delete the live variants + the parent, then try a bulk restore.
        $parent->variants()->get()->each->delete();
        $parent->delete();
        $one = $parent->variants()->withTrashed()->latest('id')->first();
        Livewire::test(ListProducts::class)
            ->filterTable('kind_view', 'variants')
            ->filterTable('trashed', false)
            ->callTableBulkAction('restore', [$one]);
        $this->assertSoftDeleted($one);
    }

    public function test_bulk_restoring_a_parent_with_its_variants_brings_back_all_of_them(): void
    {
        [, $size] = $this->axes();
        $parent = $this->variableParent();
        app(VariantGenerator::class)->generate($parent, [$size->id => $size->values()->pluck('id')->all()]);
        $variants = $parent->variants()->get();
        $variants->each->delete();
        $parent->delete();

        // Variants FIRST in the selection — the action must still restore the parent first.
        Livewire::test(ListProducts::class)
            ->filterTable('kind_view', 'all')
            ->filterTable('trashed', false)
            ->callTableBulkAction('restore', [...$variants->all(), $parent]);

        $this->assertNotSoftDeleted($parent);
        foreach ($variants as $variant) {
            $this->assertNotSoftDeleted($variant);
        }
    }

    public function test_variant_foreign_keys_never_block_a_company_delete(): void
    {
        $fks = collect(Schema::getForeignKeys('products'))
            ->merge(Schema::getForeignKeys('product_variant_values'))
            ->mapWithKeys(fn (array $fk) => [implode(',', $fk['columns']) => strtolower((string) $fk['on_delete'])]);

        $this->assertSame('set null', $fks['parent_product_id']);
        $this->assertSame('cascade', $fks['product_attribute_id']);
        $this->assertSame('cascade', $fks['product_attribute_value_id']);
    }

    public function test_variable_product_edit_shows_grid_and_variants_tab(): void
    {
        [$color, $size] = $this->axes();
        $parent = $this->variableParent();
        app(VariantGenerator::class)->generate($parent, [
            $color->id => $color->values()->pluck('id')->all(),
            $size->id => $size->values()->pluck('id')->all(),
        ]);
        $variant = $parent->variants()->first();
        app(StockService::class)->record($variant, 7, StockMovement::REASON_RECEIPT);

        Livewire::test(EditProduct::class, ['record' => $parent->getKey()])
            ->assertOk()
            ->assertSee('Απόθεμα παραλλαγών')
            ->assertSee('Μαύρο')
            ->assertSee('Σύνολο');

        Livewire::test(VariantsRelationManager::class, [
            'ownerRecord' => $parent,
            'pageClass' => EditProduct::class,
        ])
            ->assertOk()
            ->assertCanSeeTableRecords($parent->variants()->get());

        // A variant's own edit page names its parent.
        Livewire::test(EditProduct::class, ['record' => $variant->getKey()])
            ->assertOk()
            ->assertSee('Παραλλαγή του');
    }

    public function test_list_tab_and_grid_agree_on_the_stock_tone_and_format(): void
    {
        [$color, $size] = $this->axes();
        $parent = $this->variableParent();
        app(VariantGenerator::class)->generate($parent, [
            $color->id => [$color->values()->where('value', 'Μαύρο')->value('id')],
            $size->id => [$size->values()->where('value', 'S')->value('id')],
        ]);
        $variant = $parent->variants()->first();
        $variant->update(['reorder_level' => 5]);
        app(StockService::class)->record($variant, 3.5, StockMovement::REASON_RECEIPT);

        // Same rule everywhere: 3.5 ≤ reorder 5 → «warning», shown as «3.5».
        $this->assertSame('warning', StockDisplay::tone(3.5, 5.0));
        $this->assertSame('3.5', StockDisplay::format(3.5));
        $grid = VariantStockGrid::for($parent);
        $this->assertSame('warning', $grid['rows'][0]['cells'][0]['tone']);

        Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $parent, 'pageClass' => EditProduct::class])
            ->assertTableColumnFormattedStateSet('stock_on_hand', '3.5', $variant);
        Livewire::test(ListProducts::class)
            ->filterTable('kind_view', 'variants')
            ->assertTableColumnStateSet('stock_on_hand', 3.5, $variant);
    }

    public function test_price_tiers_tab_is_hidden_on_a_variable_parent(): void
    {
        $parent = $this->variableParent();

        $this->assertFalse(PriceTiersRelationManager::canViewForRecord($parent, EditProduct::class));
    }

    public function test_generate_action_creates_the_picked_combinations(): void
    {
        [$color, $size] = $this->axes();
        $parent = $this->variableParent();

        Livewire::test(VariantsRelationManager::class, [
            'ownerRecord' => $parent,
            'pageClass' => EditProduct::class,
        ])
            ->callTableAction('generate_variants', data: [
                'values_'.$color->id => [$color->values()->where('value', 'Μαύρο')->value('id')],
                'values_'.$size->id => $size->values()->pluck('id')->all(),
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(2, $parent->variants()->count());
    }

    public function test_inline_and_bulk_variant_edits_need_update_permission(): void
    {
        [, $size] = $this->axes();
        $parent = $this->variableParent();
        app(VariantGenerator::class)->generate($parent, [$size->id => $size->values()->pluck('id')->all()]);
        $variant = $parent->variants()->first();
        $this->denyUpdate = true;

        Livewire::test(VariantsRelationManager::class, [
            'ownerRecord' => $parent,
            'pageClass' => EditProduct::class,
        ])
            ->assertOk()
            ->assertTableBulkActionHidden('deactivate')
            ->assertTableActionHidden('generate_variants')
            ->call('updateTableColumnState', 'barcode', (string) $variant->getKey(), '999');

        $this->assertNull($variant->refresh()->barcode);

        // …and with the permission, a scanned barcode lands on the variant.
        $this->denyUpdate = false;
        Livewire::test(VariantsRelationManager::class, [
            'ownerRecord' => $parent,
            'pageClass' => EditProduct::class,
        ])->call('updateTableColumnState', 'barcode', (string) $variant->getKey(), '5201234567890');

        $this->assertSame('5201234567890', $variant->refresh()->barcode);
    }

    public function test_list_hides_variants_unless_searching(): void
    {
        [$color, $size] = $this->axes();
        $parent = $this->variableParent();
        app(VariantGenerator::class)->generate($parent, [$size->id => $size->values()->pluck('id')->all()]);
        $variant = $parent->variants()->first();
        $variant->update(['barcode' => '5201234567890']);

        Livewire::test(ListProducts::class)
            ->assertCanSeeTableRecords([$parent])
            ->assertCanNotSeeTableRecords([$variant])
            ->searchTable('5201234567890')
            ->assertCanSeeTableRecords([$variant]);
    }

    public function test_list_shows_a_variable_parent_with_its_variants_total_and_never_as_low_stock(): void
    {
        [, $size] = $this->axes();
        $parent = $this->variableParent();
        app(VariantGenerator::class)->generate($parent, [$size->id => $size->values()->pluck('id')->all()]);
        [$s, $m] = $parent->variants()->orderBy('id')->get()->all();
        app(StockService::class)->record($s, 4, StockMovement::REASON_RECEIPT);
        app(StockService::class)->record($m, 3, StockMovement::REASON_RECEIPT);

        app(StockService::class)->record($m, -3, StockMovement::REASON_ADJUSTMENT); // M → 0

        // The parent's own toggle off must not hide its variants' total.
        $parent->update(['track_stock' => false]);

        Livewire::test(ListProducts::class)
            ->assertTableColumnStateSet('stock_on_hand', 4.0, $parent)
            // Reorder is per variant: on the DEFAULT (grouped) view the low-stock
            // filter must surface the empty variant, never the parent.
            ->filterTable('stock_status', 'low')
            ->assertCanSeeTableRecords([$m])
            ->assertCanNotSeeTableRecords([$parent, $s]);
    }

    public function test_stock_service_defines_a_parents_stock_and_refuses_movements_on_it(): void
    {
        [, $size] = $this->axes();
        $parent = $this->variableParent();
        app(VariantGenerator::class)->generate($parent, [$size->id => $size->values()->pluck('id')->all()]);
        [$s, $m] = $parent->variants()->orderBy('id')->get()->all();
        $stock = app(StockService::class);
        $stock->record($s, 5, StockMovement::REASON_RECEIPT);
        $stock->record($m, 2, StockMovement::REASON_RECEIPT);
        $m->update(['track_stock' => false]);   // untracked variants don't count

        $this->assertSame(5.0, $stock->currentStock($parent->refresh()));

        $this->expectException(\InvalidArgumentException::class);
        $stock->record($parent, 1, StockMovement::REASON_RECEIPT);
    }

    public function test_choosing_variants_turns_stock_tracking_on_by_default(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm(['kind' => Product::KIND_VARIABLE])
            ->assertSchemaStateSet(['track_stock' => true]);
    }

    public function test_create_variable_product_and_convert_a_fresh_simple_one(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                'kind' => Product::KIND_VARIABLE,
                'description_short' => 'Φόρμα Adidas',
                'product_category_id' => $this->category->id,
                'vat_category_id' => $this->vat->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame(Product::KIND_VARIABLE, Product::where('description_short', 'Φόρμα Adidas')->value('kind'));

        $simple = Product::create([
            'company_id' => $this->tenant->id, 'description_short' => 'Μπλούζα',
            'product_category_id' => $this->category->id, 'vat_category_id' => $this->vat->id,
            'barcode' => '111',
        ]);
        Livewire::test(EditProduct::class, ['record' => $simple->getKey()])
            ->callAction('make_variable');
        $simple->refresh();
        $this->assertSame(Product::KIND_VARIABLE, $simple->kind);
        $this->assertNull($simple->barcode);
    }

    public function test_parent_with_live_variants_is_not_deleted(): void
    {
        [, $size] = $this->axes();
        $parent = $this->variableParent();
        app(VariantGenerator::class)->generate($parent, [$size->id => $size->values()->pluck('id')->all()]);

        Livewire::test(EditProduct::class, ['record' => $parent->getKey()])
            ->callAction('delete');

        $this->assertNotSoftDeleted($parent);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /** @return array{0: ProductAttribute, 1: ProductAttribute} */
    private function axes(): array
    {
        $color = ProductAttribute::create(['company_id' => $this->tenant->id, 'name' => 'Χρώμα', 'kind' => ProductAttribute::KIND_COLOR, 'sort' => 1]);
        $size = ProductAttribute::create(['company_id' => $this->tenant->id, 'name' => 'Μέγεθος', 'kind' => ProductAttribute::KIND_SIZE, 'sort' => 2]);
        foreach ([[$color, ['Μαύρο', 'Λευκό']], [$size, ['S', 'M']]] as [$attribute, $values]) {
            foreach ($values as $i => $value) {
                ProductAttributeValue::create([
                    'company_id' => $this->tenant->id, 'product_attribute_id' => $attribute->id,
                    'value' => $value, 'sort' => $i, 'color_hex' => $value === 'Μαύρο' ? '#000000' : null,
                ]);
            }
        }

        return [$color, $size];
    }

    private function variableParent(): Product
    {
        return Product::create([
            'company_id' => $this->tenant->id,
            'kind' => Product::KIND_VARIABLE,
            'description_short' => 'Παντελόνι Nike',
            'product_category_id' => $this->category->id,
            'vat_category_id' => $this->vat->id,
            'track_stock' => true,
        ]);
    }
}
