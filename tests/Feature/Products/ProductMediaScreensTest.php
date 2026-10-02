<?php

namespace Tests\Feature\Products;

use App\Filament\Resources\ProductAttributes\Pages\EditProductAttribute;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\RelationManagers\MediaRelationManager;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductCategory;
use App\Models\ProductMedia;
use App\Models\User;
use App\Models\VatCategory;
use App\Services\Products\ProductMediaService;
use App\Services\Products\VariantGenerator;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The «Φωτογραφίες & βίντεο» tab: uploads (with a colour on a variable product),
 * video link, primary/delete, permission gating, and the list thumbnail that a
 * variant inherits from its parent.
 */
class ProductMediaScreensTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private ProductCategory $category;

    private VatCategory $vat;

    private bool $denyUpdate = false;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->tenant = Company::create(['name' => 'Ρούχα', 'slug' => 'pms-'.uniqid(), 'country_code' => 'GR']);
        $this->category = ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'Ένδυση', 'markup' => 0]);
        $this->vat = VatCategory::create(['company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);

        Gate::before(fn ($user, string $ability): bool => ! ($this->denyUpdate && $ability === 'update'));
        $this->actingAs(User::create(['name' => 'Op', 'email' => 'pms-'.uniqid().'@example.test', 'password' => bcrypt('x')]));
        Filament::setTenant($this->tenant);
    }

    public function test_uploading_photos_for_a_colour_and_managing_them(): void
    {
        [$parent, $black] = $this->variableWithColours();

        $rm = Livewire::test(MediaRelationManager::class, ['ownerRecord' => $parent, 'pageClass' => EditProduct::class])
            ->assertOk()
            ->callTableAction('upload_images', data: [
                'files' => [UploadedFile::fake()->image('front.jpg', 900, 600), UploadedFile::fake()->image('back.png', 300, 300)],
                'product_attribute_value_id' => $black,
                'alt' => 'Μαύρο παντελόνι',
            ])
            ->assertHasNoTableActionErrors();

        $media = ProductMedia::where('product_id', $parent->id)->orderBy('id')->get();
        $this->assertCount(2, $media);
        $this->assertSame([$black, $black], $media->pluck('product_attribute_value_id')->all());
        $this->assertSame('Μαύρο παντελόνι', $media[1]->alt);

        $rm->callTableAction('make_primary', $media[1]);
        $this->assertSame($media[1]->id, ProductMediaService::primaryImage($parent->fresh())->id);

        $rm->callTableAction('video_link', data: ['url' => 'https://vimeo.com/123456']);
        $this->assertTrue(ProductMedia::where('product_id', $parent->id)->where('kind', ProductMedia::KIND_VIDEO_LINK)->exists());

        $rm->callTableAction('remove', $media[0]);
        $this->assertModelMissing($media[0]);
        Storage::disk('local')->assertMissing($media[0]->path);
    }

    public function test_a_value_of_another_product_cannot_be_smuggled_in(): void
    {
        [$parent] = $this->variableWithColours();
        $foreign = ProductAttributeValue::create([
            'company_id' => $this->tenant->id,
            'product_attribute_id' => ProductAttribute::create(['company_id' => $this->tenant->id, 'name' => 'Υλικό'])->id,
            'value' => 'Βαμβάκι',
        ]);

        Livewire::test(MediaRelationManager::class, ['ownerRecord' => $parent, 'pageClass' => EditProduct::class])
            ->callTableAction('upload_images', data: [
                'files' => [UploadedFile::fake()->image('a.jpg', 50, 50)],
                'product_attribute_value_id' => $foreign->id,
            ]);

        // Not a value its variants use → the photo is stored as general, not tied to it.
        $this->assertNull(ProductMedia::where('product_id', $parent->id)->value('product_attribute_value_id'));
    }

    public function test_users_without_update_permission_cannot_upload_reorder_or_delete(): void
    {
        $product = Product::create($this->productData());
        $a = app(ProductMediaService::class)->storeImage($product, $this->jpegPath(), 'a.jpg');
        $b = app(ProductMediaService::class)->storeImage($product, $this->jpegPath(), 'b.jpg');
        $this->denyUpdate = true;

        Livewire::test(MediaRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
            ->assertOk()
            ->assertTableActionHidden('upload_images')
            ->assertTableActionHidden('video_link')
            ->call('reorderTable', [(string) $b->id, (string) $a->id]);

        $this->assertLessThan($b->fresh()->sort, $a->fresh()->sort, 'reorder refused without update permission');
    }

    public function test_a_trashed_product_takes_no_new_media(): void
    {
        $product = Product::create($this->productData());
        $product->delete();

        Livewire::test(MediaRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
            ->assertTableActionHidden('upload_images')
            ->assertTableActionHidden('upload_video')
            ->assertTableActionHidden('video_link');
    }

    public function test_a_colour_value_with_photos_cannot_be_removed(): void
    {
        [$parent, $black] = $this->variableWithColours();
        $photo = app(ProductMediaService::class)->storeImage($parent, $this->jpegPath(), 'black.jpg', $black);
        $parent->variants()->get()->each->delete();   // no live variant uses it any more…
        $attribute = ProductAttributeValue::find($black)->attribute;

        $page = Livewire::test(EditProductAttribute::class, ['record' => $attribute->getKey()]);
        $state = $page->get('data.values');
        unset($state['record-'.$black]);
        $page->set('data.values', $state)->call('save');

        // …but a photo is tied to it: removing it would make the black photo general.
        $this->assertDatabaseHas('product_attribute_values', ['id' => $black]);
        $this->assertSame($black, $photo->fresh()->product_attribute_value_id);
    }

    public function test_the_list_shows_a_variant_its_colours_photo_from_the_parent(): void
    {
        [$parent, $black] = $this->variableWithColours();
        $rm = Livewire::test(MediaRelationManager::class, ['ownerRecord' => $parent, 'pageClass' => EditProduct::class]);
        // General photo first (→ the parent's primary), then a black-only one.
        $rm->callTableAction('upload_images', data: ['files' => [UploadedFile::fake()->image('general.jpg', 50, 50)]]);
        $rm->callTableAction('upload_images', data: ['files' => [UploadedFile::fake()->image('black.jpg', 50, 50)], 'product_attribute_value_id' => $black]);

        $general = ProductMedia::where('product_id', $parent->id)->whereNull('product_attribute_value_id')->first();
        $blackPhoto = ProductMedia::where('product_id', $parent->id)->where('product_attribute_value_id', $black)->first();
        $blackVariant = $parent->variants()->get()->first(fn (Product $v) => str_ends_with($v->description_short, 'Μαύρο'));
        $whiteVariant = $parent->variants()->get()->first(fn (Product $v) => str_ends_with($v->description_short, 'Λευκό'));

        Livewire::test(ListProducts::class)
            ->assertTableColumnStateSet('photo', $general->fileUrl('thumb'), $parent)
            ->filterTable('kind_view', 'variants')
            ->assertTableColumnStateSet('photo', $blackPhoto->fileUrl('thumb'), $blackVariant)
            ->assertTableColumnStateSet('photo', $general->fileUrl('thumb'), $whiteVariant);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function jpegPath(): string
    {
        $img = imagecreatetruecolor(40, 40);
        $path = tempnam(sys_get_temp_dir(), 'pms').'.jpg';
        imagejpeg($img, $path);

        return $path;
    }

    /** @return array{0: Product, 1: int} the variable parent + the «Μαύρο» value id */
    private function variableWithColours(): array
    {
        $color = ProductAttribute::create(['company_id' => $this->tenant->id, 'name' => 'Χρώμα', 'kind' => ProductAttribute::KIND_COLOR, 'sort' => 1]);
        foreach (['Μαύρο', 'Λευκό'] as $i => $value) {
            ProductAttributeValue::create(['company_id' => $this->tenant->id, 'product_attribute_id' => $color->id, 'value' => $value, 'sort' => $i]);
        }
        $parent = Product::create($this->productData(['kind' => Product::KIND_VARIABLE, 'track_stock' => true]));
        app(VariantGenerator::class)->generate($parent, [$color->id => $color->values()->pluck('id')->all()]);

        return [$parent, (int) $color->values()->where('value', 'Μαύρο')->value('id')];
    }

    private function productData(array $overrides = []): array
    {
        return array_merge([
            'company_id' => $this->tenant->id,
            'description_short' => 'Παντελόνι',
            'product_category_id' => $this->category->id,
            'vat_category_id' => $this->vat->id,
        ], $overrides);
    }
}
