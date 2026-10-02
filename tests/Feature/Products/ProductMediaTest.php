<?php

namespace Tests\Feature\Products;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductCategory;
use App\Models\ProductMedia;
use App\Models\User;
use App\Models\VatCategory;
use App\Services\Portability\CompanyDataWiper;
use App\Services\Products\ProductMediaService;
use App\Services\Products\VariantGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Product photos & videos: GD re-encode (+ thumbnail, size/MP caps, all EXIF
 * orientations), primary = first image by sort, strict video-link allow-list,
 * the ONE display rule for variants, operator-only serving, and files removed
 * only once a delete commits (never orphaned, never lost on rollback).
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

    public function test_an_image_is_reencoded_capped_and_thumbnailed(): void
    {
        $product = $this->product();

        $first = $this->media->storeImage($product, $this->jpeg(2400, 1200), 'big.jpg', alt: 'Μπροστά');
        $second = $this->media->storeImage($product, $this->png(300, 300), 'small.png');

        Storage::disk('local')->assertExists([$first->path, $first->thumb_path]);
        $this->assertSame([2000, 1000], array_slice(getimagesizefromstring(Storage::disk('local')->get($first->path)), 0, 2), 'full capped at 2000px');
        $this->assertSame([400, 200], array_slice(getimagesizefromstring(Storage::disk('local')->get($first->thumb_path)), 0, 2));
        $this->assertSame('image/jpeg', $first->mime_type);
        $this->assertSame('Μπροστά', $first->alt);
        $this->assertSame('image/png', $second->mime_type);
        $this->assertSame([300, 300], array_slice(getimagesizefromstring(Storage::disk('local')->get($second->path)), 0, 2), 'never up-scaled');
        $this->assertGreaterThan($first->sort, $second->sort);

        $this->assertSame($first->id, ProductMediaService::primaryImage($product->fresh())->id, 'first image by sort is the primary');
    }

    public function test_non_images_oversized_files_and_too_many_megapixels_are_rejected(): void
    {
        $product = $this->product();
        $text = tempnam(sys_get_temp_dir(), 'pm');
        file_put_contents($text, 'not an image');

        $this->assertRejected(fn () => $this->media->storeImage($product, $text, 'evil.jpg'));

        config(['ekdosi.product_media.max_megapixels' => 0.01]);   // 10,000 px
        $this->assertRejected(fn () => $this->media->storeImage($product, $this->jpeg(200, 100), 'huge.jpg'), 'MP');

        config(['ekdosi.product_media.max_megapixels' => 50, 'ekdosi.product_media.max_image_kb' => 1]);
        $this->assertRejected(fn () => $this->media->storeImage($product, $this->jpeg(800, 800, noise: true), 'heavy.jpg'));

        $this->assertSame(0, ProductMedia::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_memory_limit_is_restored_after_processing(): void
    {
        $before = ini_get('memory_limit');
        $this->media->storeImage($this->product(), $this->jpeg(1200, 900), 'a.jpg');

        $this->assertSame($before, ini_get('memory_limit'));
    }

    public function test_every_exif_orientation_ends_up_upright(): void
    {
        // A 2×1 image: red on the left, blue on the right. Each EXIF orientation n
        // stores the pixels transformed; orient(n) must restore the original view.
        foreach (range(1, 8) as $orientation) {
            $stored = $this->storedAs($this->redBlue(), $orientation);
            $fixed = ProductMediaService::orient($stored, $orientation);

            $this->assertSame([2, 1], [imagesx($fixed), imagesy($fixed)], "orientation {$orientation}: size");
            $this->assertSame('red', $this->colourAt($fixed, 0, 0), "orientation {$orientation}: left pixel");
            $this->assertSame('blue', $this->colourAt($fixed, 1, 0), "orientation {$orientation}: right pixel");
        }
    }

    public function test_video_files_and_a_strict_link_allow_list(): void
    {
        $product = $this->product();
        $mp4 = tempnam(sys_get_temp_dir(), 'pm');
        file_put_contents($mp4, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64));

        $video = $this->media->storeVideo($product, $mp4, 'clip.mp4');
        $this->assertSame('video/mp4', $video->mime_type);
        Storage::disk('local')->assertExists($video->path);
        $this->assertNull(ProductMediaService::primaryImage($product->fresh()), 'a video is never the primary photo');

        $link = $this->media->addVideoLink($product, 'https://www.youtube.com/watch?v=abc');
        $this->assertSame('https://www.youtube.com/watch?v=abc', $link->fileUrl());

        foreach ([
            'http://youtube.com/watch?v=x',                                  // not https
            'https://evil.example/v.mp4',                                    // unknown host
            'https://youtube.com.evil.example/x',                            // suffix trick
            'https://evil.com\\@youtube.com/x',                               // backslash: parse_url ≠ browser
            'https://user@youtube.com/x',                                    // userinfo
            'https://youtube.com:8443/x',                                    // port
            'https://www.youtube.com/redirect?q=https://evil.com',           // the host's own redirector
            'https://l.facebook.com/l.php?u=https%3A%2F%2Fevil.com',         // facebook redirector host
            'javascript:alert(1)',
        ] as $bad) {
            $this->assertFalse(ProductMediaService::isAllowedVideoLink($bad), $bad);
        }
    }

    public function test_make_primary_moves_the_photo_to_the_top_and_delete_keeps_the_next_one_primary(): void
    {
        $product = $this->product();
        $a = $this->media->storeImage($product, $this->jpeg(60, 60), 'a.jpg');
        $b = $this->media->storeImage($product, $this->jpeg(60, 60), 'b.jpg');

        $this->media->makePrimary($b);
        $this->assertSame($b->id, ProductMediaService::primaryImage($product->fresh())->id);
        $this->assertLessThan($a->fresh()->sort, $b->fresh()->sort);

        $this->media->delete($b);
        Storage::disk('local')->assertMissing([$b->path, $b->thumb_path]);
        $this->assertSame($a->id, ProductMediaService::primaryImage($product->fresh())->id);
    }

    public function test_a_rolled_back_delete_keeps_its_files(): void
    {
        $image = $this->media->storeImage($this->product(), $this->jpeg(60, 60), 'a.jpg');

        try {
            DB::transaction(function () use ($image): void {
                $this->media->delete($image);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $this->assertModelExists($image);
        Storage::disk('local')->assertExists([$image->path, $image->thumb_path]);
    }

    public function test_a_variant_shows_its_colours_photos_and_its_own_kinds_override_per_kind(): void
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
        $this->assertSame([$blackPhoto->id, $general->id], ProductMediaService::displayMedia($blackM)->pluck('id')->all());

        // A variant's OWN video must not hide the inherited photos (per-kind override).
        $ownLink = $this->media->addVideoLink($blackM, 'https://vimeo.com/1');
        $shown = ProductMediaService::displayMedia($blackM->fresh());
        $this->assertSame([$blackPhoto->id, $general->id, $ownLink->id], $shown->pluck('id')->all());
        $this->assertSame($blackPhoto->id, ProductMediaService::primaryImage($blackM->fresh())->id);
    }

    public function test_a_photo_can_only_be_tied_to_a_value_its_variants_use(): void
    {
        [$color, $size] = $this->axes();
        $parent = $this->product(['kind' => Product::KIND_VARIABLE]);
        app(VariantGenerator::class)->generate($parent, [$size->id => $size->values()->pluck('id')->all()]);

        $this->assertRejected(fn () => $this->media->storeImage($this->product(), $this->jpeg(50, 50), 'x.jpg', $color->values()->value('id')));
        // «Μαύρο» exists in the company but no variant of THIS product uses it.
        $this->assertRejected(fn () => $this->media->storeImage($parent, $this->jpeg(50, 50), 'x.jpg', $color->values()->value('id')));
    }

    public function test_details_keep_a_colour_whose_variants_are_gone(): void
    {
        [$color] = $this->axes();
        $parent = $this->product(['kind' => Product::KIND_VARIABLE]);
        app(VariantGenerator::class)->generate($parent, [$color->id => $color->values()->pluck('id')->all()]);
        $black = (int) $color->values()->where('value', 'Μαύρο')->value('id');
        $photo = $this->media->storeImage($parent, $this->jpeg(50, 50), 'b.jpg', $black);
        $parent->variants()->get()->each->delete();

        $this->media->updateDetails($photo, 'Νέο alt', $black);

        $this->assertSame(['Νέο alt', $black], [$photo->fresh()->alt, $photo->fresh()->product_attribute_value_id]);
    }

    public function test_media_is_served_to_operators_of_its_company_only(): void
    {
        $image = $this->media->storeImage($this->product(), $this->jpeg(600, 300), 'p.jpg');
        $url = $image->fileUrl('thumb');

        $this->get($url)->assertRedirect();   // guest → login, never the bytes

        Gate::before(fn () => true);
        $outsider = User::create(['name' => 'X', 'email' => 'x-'.uniqid().'@e.test', 'password' => bcrypt('x')]);
        $this->actingAs($outsider)->get($url)->assertForbidden();

        $operator = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@e.test', 'password' => bcrypt('x')]);
        $this->tenant->users()->attach($operator);
        $this->actingAs($operator)->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('Cache-Control', 'max-age=3600, private');
        $this->actingAs($operator)->get($image->fileUrl(), ['Range' => 'bytes=0-9'])->assertStatus(206);
    }

    public function test_video_uploads_stay_within_livewires_12mb_temp_limit(): void
    {
        $this->assertLessThanOrEqual(12288, (int) config('ekdosi.product_media.max_video_kb'));
        $this->assertNull(config('livewire.temporary_file_upload.rules'), 'the app-wide Livewire limit is left alone');
    }

    public function test_force_delete_wipe_and_company_delete_leave_no_files(): void
    {
        $product = $this->product();
        $image = $this->media->storeImage($product, $this->jpeg(50, 50), 'a.jpg');
        $product->forceDelete();
        Storage::disk('local')->assertMissing([$image->path, $image->thumb_path]);

        $kept = $this->media->storeImage($this->product(), $this->jpeg(50, 50), 'b.jpg');
        app(CompanyDataWiper::class)->wipe($this->tenant, keepParties: false, resetCounter: false, force: true);
        Storage::disk('local')->assertMissing($kept->path);
        $this->assertSame(0, ProductMedia::where('company_id', $this->tenant->id)->count());

        $last = $this->media->storeImage($this->product(), $this->jpeg(50, 50), 'c.jpg');
        $this->tenant->delete();
        Storage::disk('local')->assertMissing([$last->path, $last->thumb_path]);
    }

    public function test_cleanup_covers_files_on_a_disk_the_config_moved_away_from(): void
    {
        Storage::fake('old');
        config(['filesystems.disks.old' => ['driver' => 'local', 'root' => Storage::disk('old')->path('')]]);
        config(['ekdosi.product_media.disk' => 'old']);
        $legacy = $this->media->storeImage($this->product(), $this->jpeg(50, 50), 'legacy.jpg');
        config(['ekdosi.product_media.disk' => 'local']);

        $this->tenant->delete();

        Storage::disk('old')->assertMissing($legacy->path);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function assertRejected(callable $call, ?string $messagePart = null): void
    {
        try {
            $call();
            $this->fail('expected a rejection');
        } catch (InvalidArgumentException $e) {
            if ($messagePart !== null) {
                $this->assertStringContainsString($messagePart, $e->getMessage());
            }
        }
    }

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

    private function redBlue(): \GdImage
    {
        $img = imagecreatetruecolor(2, 1);
        imagesetpixel($img, 0, 0, imagecolorallocate($img, 255, 0, 0));
        imagesetpixel($img, 1, 0, imagecolorallocate($img, 0, 0, 255));

        return $img;
    }

    /** How a camera STORES an upright image under each EXIF orientation (the inverse of the fix). */
    private function storedAs(\GdImage $upright, int $orientation): \GdImage
    {
        $img = imagecrop($upright, ['x' => 0, 'y' => 0, 'width' => 2, 'height' => 1]);
        switch ($orientation) {
            case 2: imageflip($img, IMG_FLIP_HORIZONTAL);
                break;
            case 3: $img = imagerotate($img, 180, 0);
                break;
            case 4: imageflip($img, IMG_FLIP_VERTICAL);
                break;
            case 5: imageflip($img, IMG_FLIP_HORIZONTAL);
                $img = imagerotate($img, 90, 0);
                break;
            case 6: $img = imagerotate($img, 90, 0);
                break;
            case 7: imageflip($img, IMG_FLIP_HORIZONTAL);
                $img = imagerotate($img, -90, 0);
                break;
            case 8: $img = imagerotate($img, -90, 0);
                break;
        }

        return $img;
    }

    private function colourAt(\GdImage $img, int $x, int $y): string
    {
        $c = imagecolorsforindex($img, imagecolorat($img, $x, $y));

        return $c['red'] > 200 && $c['blue'] < 50 ? 'red' : ($c['blue'] > 200 && $c['red'] < 50 ? 'blue' : 'other');
    }
}
