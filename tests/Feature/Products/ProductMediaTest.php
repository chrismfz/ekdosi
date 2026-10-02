<?php

namespace Tests\Feature\Products;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductCategory;
use App\Models\ProductMedia;
use App\Models\VatCategory;
use App\Services\Portability\CompanyDataWiper;
use App\Services\Products\ProductMediaService;
use App\Services\Products\VariantGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Product photos & videos: GD re-encode (+ thumbnail, size cap), primary image,
 * video links allow-list, colour-specific photos inherited by variants, the
 * signed public URL, and files never orphaned on delete / force-delete / wipe.
 */
class ProductMediaTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private ProductCategory $category;

    private VatCategory $vat;

    private ProductMediaService $media;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->tenant = Company::create(['name' => 'Ρούχα', 'slug' => 'pm-'.uniqid(), 'country_code' => 'GR']);
        $this->category = ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'Ένδυση', 'markup' => 0]);
        $this->vat = VatCategory::create(['company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
        $this->media = app(ProductMediaService::class);
    }

    public function test_an_image_is_reencoded_capped_and_thumbnailed_and_the_first_is_primary(): void
    {
        $product = $this->product();

        $first = $this->media->storeImage($product, $this->jpeg(2400, 1200), 'big.jpg', alt: 'Μπροστά');
        $second = $this->media->storeImage($product, $this->png(300, 300), 'small.png');

        $this->assertSame(ProductMedia::KIND_IMAGE, $first->kind);
        $this->assertSame([2000, 1000], [$first->width, $first->height], 'full capped at 2000px');
        Storage::disk('local')->assertExists([$first->path, $first->thumb_path]);
        [$tw, $th] = getimagesizefromstring(Storage::disk('local')->get($first->thumb_path));
        $this->assertSame([400, 200], [$tw, $th]);
        $this->assertSame('image/jpeg', $first->mime_type);
        $this->assertTrue($first->is_primary);

        $this->assertFalse($second->is_primary);
        $this->assertSame('image/png', $second->mime_type);
        $this->assertSame([300, 300], [$second->width, $second->height], 'never up-scaled');
        $this->assertGreaterThan($first->sort, $second->sort);
    }

    public function test_non_images_and_oversized_files_are_rejected(): void
    {
        $product = $this->product();
        $text = tempnam(sys_get_temp_dir(), 'pm');
        file_put_contents($text, 'not an image');

        try {
            $this->media->storeImage($product, $text, 'evil.jpg');
            $this->fail('a text file must be rejected');
        } catch (InvalidArgumentException) {
        }

        config(['ekdosi.product_media.max_image_kb' => 1]);
        $this->expectException(InvalidArgumentException::class);
        $this->media->storeImage($product, $this->jpeg(800, 800, noise: true), 'huge.jpg');
    }

    public function test_too_many_megapixels_is_a_friendly_rejection_not_a_fatal(): void
    {
        config(['ekdosi.product_media.max_megapixels' => 0.01]);   // 10,000 px

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('MP');
        $this->media->storeImage($this->product(), $this->jpeg(200, 100), 'huge.jpg');
    }

    public function test_videos_can_be_bigger_than_livewires_default_temp_upload_limit(): void
    {
        $rule = collect(config('livewire.temporary_file_upload.rules'))->first(fn ($r) => str_starts_with((string) $r, 'max:'));

        $this->assertGreaterThanOrEqual((int) config('ekdosi.product_media.max_video_kb'), (int) substr((string) $rule, 4));
    }

    public function test_a_photo_can_only_be_tied_to_a_value_its_variants_use(): void
    {
        [$color, $size] = $this->axes();
        $parent = $this->product(['kind' => Product::KIND_VARIABLE]);
        app(VariantGenerator::class)->generate($parent, [$size->id => $size->values()->pluck('id')->all()]);

        // «Μαύρο» exists in the company but no variant of THIS product uses it.
        $this->expectException(InvalidArgumentException::class);
        $this->media->storeImage($parent, $this->jpeg(50, 50), 'x.jpg', $color->values()->value('id'));
    }

    public function test_video_files_and_allow_listed_links(): void
    {
        $product = $this->product();
        $mp4 = tempnam(sys_get_temp_dir(), 'pm');
        file_put_contents($mp4, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64));

        $video = $this->media->storeVideo($product, $mp4, 'clip.mp4');
        $this->assertSame('video/mp4', $video->mime_type);
        Storage::disk('local')->assertExists($video->path);
        $this->assertFalse($video->is_primary, 'only images can be primary');

        $link = $this->media->addVideoLink($product, 'https://www.youtube.com/watch?v=abc');
        $this->assertSame('https://www.youtube.com/watch?v=abc', $link->publicUrl());

        foreach (['http://youtube.com/watch?v=x', 'https://evil.example/v.mp4', 'https://youtube.com.evil.example/x'] as $bad) {
            try {
                $this->media->addVideoLink($product, $bad);
                $this->fail($bad.' must be rejected');
            } catch (InvalidArgumentException) {
            }
        }
    }

    public function test_deleting_drops_the_files_and_hands_primary_over(): void
    {
        $product = $this->product();
        $a = $this->media->storeImage($product, $this->jpeg(100, 100), 'a.jpg');
        $b = $this->media->storeImage($product, $this->jpeg(100, 100), 'b.jpg');

        $this->media->delete($a);

        Storage::disk('local')->assertMissing([$a->path, $a->thumb_path]);
        $this->assertTrue($b->refresh()->is_primary);

        $this->media->makePrimary($b);
        $this->assertSame(1, ProductMedia::where('product_id', $product->id)->where('is_primary', true)->count());
    }

    public function test_a_variant_shows_its_colours_photos_first_then_the_general_ones(): void
    {
        [$color, $size] = $this->axes();
        $parent = $this->product(['kind' => Product::KIND_VARIABLE, 'track_stock' => true]);
        app(VariantGenerator::class)->generate($parent, [
            $color->id => $color->values()->pluck('id')->all(),
            $size->id => $size->values()->pluck('id')->all(),
        ]);
        $black = $color->values()->where('value', 'Μαύρο')->value('id');
        $white = $color->values()->where('value', 'Λευκό')->value('id');

        $general = $this->media->storeImage($parent, $this->jpeg(50, 50), 'general.jpg');
        $blackPhoto = $this->media->storeImage($parent, $this->jpeg(50, 50), 'black.jpg', $black);
        $this->media->storeImage($parent, $this->jpeg(50, 50), 'white.jpg', $white);

        $blackM = $parent->variants()->get()->first(fn (Product $v) => $v->description_short === 'Παντελόνι — Μαύρο / M');
        $shown = $this->media->mediaFor($blackM);

        $this->assertSame([$blackPhoto->id, $general->id], $shown->pluck('id')->all());
        $this->assertSame($blackPhoto->id, $this->media->primaryImageFor($blackM)->id);
    }

    public function test_a_colour_can_only_be_tied_on_a_variable_product_of_the_same_company(): void
    {
        [$color] = $this->axes();
        $simple = $this->product();

        $this->expectException(InvalidArgumentException::class);
        $this->media->storeImage($simple, $this->jpeg(50, 50), 'x.jpg', $color->values()->value('id'));
    }

    public function test_the_signed_url_serves_the_file_and_nothing_else(): void
    {
        $image = $this->media->storeImage($this->product(), $this->jpeg(600, 300), 'p.jpg');

        $this->get($image->publicUrl('thumb'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');

        $this->get(route('product-media.show', ['media' => $image->id, 'variant' => 'full']))->assertForbidden();

        // Range requests (needed by Safari/iOS for video, and for seeking) get a 206.
        $this->get($image->publicUrl(), ['Range' => 'bytes=0-9'])
            ->assertStatus(206)
            ->assertHeader('Content-Length', '10');

        $url = $image->publicUrl();
        $this->media->delete($image);
        $this->get($url)->assertNotFound();
    }

    public function test_force_deleting_a_product_and_wiping_a_company_leave_no_files(): void
    {
        $product = $this->product();
        $image = $this->media->storeImage($product, $this->jpeg(50, 50), 'a.jpg');
        $product->forceDelete();
        Storage::disk('local')->assertMissing([$image->path, $image->thumb_path]);

        $other = $this->product();
        $kept = $this->media->storeImage($other, $this->jpeg(50, 50), 'b.jpg');
        app(CompanyDataWiper::class)->wipe($this->tenant, keepParties: false, resetCounter: false, force: true);
        Storage::disk('local')->assertMissing($kept->path);
        $this->assertSame(0, ProductMedia::where('company_id', $this->tenant->id)->count());
    }

    public function test_deleting_the_company_purges_its_files(): void
    {
        $image = $this->media->storeImage($this->product(), $this->jpeg(50, 50), 'a.jpg');

        $this->tenant->delete();

        Storage::disk('local')->assertMissing([$image->path, $image->thumb_path]);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function product(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'company_id' => $this->tenant->id,
            'description_short' => 'Παντελόνι',
            'product_category_id' => $this->category->id,
            'vat_category_id' => $this->vat->id,
        ], $overrides));
    }

    /** @return array{0: ProductAttribute, 1: ProductAttribute} */
    private function axes(): array
    {
        $color = ProductAttribute::create(['company_id' => $this->tenant->id, 'name' => 'Χρώμα', 'kind' => ProductAttribute::KIND_COLOR, 'sort' => 1]);
        $size = ProductAttribute::create(['company_id' => $this->tenant->id, 'name' => 'Μέγεθος', 'kind' => ProductAttribute::KIND_SIZE, 'sort' => 2]);
        foreach ([[$color, ['Μαύρο', 'Λευκό']], [$size, ['S', 'M']]] as [$attribute, $values]) {
            foreach ($values as $i => $value) {
                ProductAttributeValue::create(['company_id' => $this->tenant->id, 'product_attribute_id' => $attribute->id, 'value' => $value, 'sort' => $i]);
            }
        }

        return [$color, $size];
    }

    private function jpeg(int $w, int $h, bool $noise = false): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
        if ($noise) {
            for ($i = 0; $i < $w * $h / 2; $i++) {
                imagesetpixel($img, random_int(0, $w - 1), random_int(0, $h - 1), random_int(0, 0xFFFFFF));
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'pm').'.jpg';
        imagejpeg($img, $path, 95);

        return $path;
    }

    private function png(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        $path = tempnam(sys_get_temp_dir(), 'pm').'.png';
        imagepng($img, $path);

        return $path;
    }
}
