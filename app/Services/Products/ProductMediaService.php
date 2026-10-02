<?php

namespace App\Services\Products;

use App\Models\Product;
use App\Models\ProductMedia;
use GdImage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Stores and resolves product photos & videos (docs/woocommerce-bridge-plan.md §0).
 *
 * Images are RE-ENCODED with GD: EXIF orientation applied, EXIF (incl. GPS)
 * stripped — these photos end up public on the e-shop — the full image capped
 * at FULL_PX and a thumbnail generated. Videos are stored as-is (size-capped);
 * video LINKS store only an allow-listed https URL.
 */
class ProductMediaService
{
    /** Longest side of the stored "full" image. */
    private const FULL_PX = 2000;

    /** Accepted image types → stored extension (GIF is stored as PNG — no animation kept). */
    private const IMAGE_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'png',
    ];

    private const VIDEO_TYPES = [
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/quicktime' => 'mov',
    ];

    /** Hosts a video link may point at (https only). */
    private const VIDEO_HOSTS = ['youtube.com', 'youtu.be', 'vimeo.com', 'instagram.com', 'tiktok.com', 'facebook.com'];

    /**
     * Store an uploaded image from a local file path.
     */
    public function storeImage(Product $product, string $sourcePath, string $originalName, ?int $valueId = null, ?string $alt = null): ProductMedia
    {
        $valueId = $this->checkedValueId($product, $valueId);

        $info = @getimagesize($sourcePath);
        $mime = $info['mime'] ?? null;
        if ($info === false || ! isset(self::IMAGE_TYPES[$mime])) {
            throw new InvalidArgumentException('Μη υποστηριζόμενη εικόνα «'.$originalName.'» — δεκτά: JPG, PNG, WebP, GIF.');
        }
        $this->assertSize($sourcePath, (int) config('ekdosi.product_media.max_image_kb', 10240), $originalName);
        $this->assertDecodable((int) $info[0], (int) $info[1], $originalName);

        $image = $this->load($sourcePath, $mime);
        $image = $this->applyExifOrientation($image, $sourcePath, $mime);

        $ext = self::IMAGE_TYPES[$mime];
        $disk = $this->disk();
        $dir = $this->directory($product);
        $name = (string) Str::uuid();

        $full = $this->resized($image, self::FULL_PX);
        $thumb = $this->resized($image, max(64, (int) config('ekdosi.product_media.thumb_px', 400)));

        $path = "{$dir}/{$name}.{$ext}";
        $thumbPath = "{$dir}/thumbs/{$name}.{$ext}";
        Storage::disk($disk)->put($path, $this->encode($full, $ext));
        Storage::disk($disk)->put($thumbPath, $this->encode($thumb, $ext));

        $width = imagesx($full);
        $height = imagesy($full);

        return $this->createRowOrCleanUp($disk, [$path, $thumbPath], $product, [
            'kind' => ProductMedia::KIND_IMAGE,
            'disk' => $disk,
            'path' => $path,
            'thumb_path' => $thumbPath,
            'original_name' => mb_substr($originalName, 0, 255),
            'mime_type' => $ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg'),
            'size' => Storage::disk($disk)->size($path),
            'width' => $width,
            'height' => $height,
            'alt' => $alt !== null ? mb_substr($alt, 0, 255) : null,
            'product_attribute_value_id' => $valueId,
        ]);
    }

    /** Store an uploaded video file (as-is, size-capped). */
    public function storeVideo(Product $product, string $sourcePath, string $originalName, ?int $valueId = null): ProductMedia
    {
        $valueId = $this->checkedValueId($product, $valueId);

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($sourcePath);
        if (! isset(self::VIDEO_TYPES[$mime])) {
            throw new InvalidArgumentException('Μη υποστηριζόμενο βίντεο «'.$originalName.'» — δεκτά: MP4, WebM, MOV.');
        }
        $this->assertSize($sourcePath, (int) config('ekdosi.product_media.max_video_kb', 51200), $originalName);

        $disk = $this->disk();
        $path = $this->directory($product).'/'.Str::uuid().'.'.self::VIDEO_TYPES[$mime];
        $stream = fopen($sourcePath, 'rb');
        Storage::disk($disk)->writeStream($path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return $this->createRowOrCleanUp($disk, [$path], $product, [
            'kind' => ProductMedia::KIND_VIDEO,
            'disk' => $disk,
            'path' => $path,
            'original_name' => mb_substr($originalName, 0, 255),
            'mime_type' => $mime,
            'size' => Storage::disk($disk)->size($path),
            'product_attribute_value_id' => $valueId,
        ]);
    }

    /** Store a video LINK (YouTube / Vimeo / Instagram / TikTok / Facebook, https only). */
    public function addVideoLink(Product $product, string $url, ?int $valueId = null): ProductMedia
    {
        $valueId = $this->checkedValueId($product, $valueId);

        $url = trim($url);
        $parts = parse_url($url);
        $host = Str::lower((string) ($parts['host'] ?? ''));
        $host = Str::startsWith($host, 'www.') ? substr($host, 4) : $host;
        $allowed = collect(self::VIDEO_HOSTS)->contains(fn (string $h) => $host === $h || Str::endsWith($host, '.'.$h));
        if (($parts['scheme'] ?? '') !== 'https' || ! $allowed || mb_strlen($url) > 500) {
            throw new InvalidArgumentException('Δεκτοί σύνδεσμοι: https από YouTube, Vimeo, Instagram, TikTok ή Facebook.');
        }

        return $this->createRow($product, [
            'kind' => ProductMedia::KIND_VIDEO_LINK,
            'url' => $url,
            'product_attribute_value_id' => $valueId,
        ]);
    }

    /** Make this image the product's primary one (exactly one primary image per product). */
    public function makePrimary(ProductMedia $media): void
    {
        if (! $media->isImage()) {
            throw new InvalidArgumentException('Κύρια μπορεί να είναι μόνο φωτογραφία.');
        }

        DB::transaction(function () use ($media): void {
            ProductMedia::query()->withoutGlobalScopes()
                ->where('product_id', $media->product_id)
                ->whereKeyNot($media->getKey())
                ->update(['is_primary' => false]);
            $media->forceFill(['is_primary' => true])->save();
        });
    }

    /** Delete a media row (+ its files); a deleted primary hands over to the next image. */
    public function delete(ProductMedia $media): void
    {
        DB::transaction(function () use ($media): void {
            $wasPrimary = $media->is_primary;
            $productId = $media->product_id;
            $media->delete();

            if ($wasPrimary) {
                $next = ProductMedia::query()->withoutGlobalScopes()
                    ->where('product_id', $productId)
                    ->where('kind', ProductMedia::KIND_IMAGE)
                    ->orderBy('sort')->orderBy('id')
                    ->first();
                $next?->forceFill(['is_primary' => true])->save();
            }
        });
    }

    /**
     * The media a product SHOWS, in display order. A variant: its own media, else
     * its parent's media for the variant's colour/values, then the parent's
     * general media. Anything else: its own media.
     *
     * @return Collection<int, ProductMedia>
     */
    public function mediaFor(Product $product): Collection
    {
        $own = ProductMedia::query()->withoutGlobalScopes()
            ->where('product_id', $product->getKey())
            ->orderByDesc('is_primary')->orderBy('sort')->orderBy('id')
            ->get();

        if (! $product->isVariant() || $own->isNotEmpty() || $product->parent_product_id === null) {
            return $own;
        }

        $valueIds = DB::table('product_variant_values')
            ->where('product_id', $product->getKey())
            ->pluck('product_attribute_value_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $parentMedia = ProductMedia::query()->withoutGlobalScopes()
            ->where('product_id', $product->parent_product_id)
            ->get();

        return self::orderForVariant($parentMedia, $valueIds);
    }

    /**
     * Of a parent's media, what a variant with these values shows: its values'
     * media (e.g. the black photos) first, then the general ones; media tied to
     * OTHER values (the white photos) are left out. Shared with the list thumbnail.
     *
     * @param  Collection<int, ProductMedia>  $parentMedia
     * @param  list<int>  $valueIds
     * @return Collection<int, ProductMedia>
     */
    public static function orderForVariant(Collection $parentMedia, array $valueIds): Collection
    {
        return $parentMedia
            ->filter(fn (ProductMedia $m) => $m->product_attribute_value_id === null || in_array((int) $m->product_attribute_value_id, $valueIds, true))
            ->sortBy(fn (ProductMedia $m) => [$m->product_attribute_value_id === null ? 1 : 0, $m->is_primary ? 0 : 1, $m->sort, $m->id])
            ->values();
    }

    /**
     * Values a photo of this variable product may be tied to: the ones its live
     * variants actually use (a photo tied to anything else no variant would show).
     *
     * @return list<int>
     */
    public function usedValueIds(Product $product): array
    {
        return DB::table('product_variant_values')
            ->join('products', 'products.id', '=', 'product_variant_values.product_id')
            ->where('products.parent_product_id', $product->getKey())
            ->whereNull('products.deleted_at')
            ->distinct()
            ->pluck('product_variant_values.product_attribute_value_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** Primary image a product shows (null when it has none). */
    public function primaryImageFor(Product $product): ?ProductMedia
    {
        return $this->mediaFor($product)->first(fn (ProductMedia $m) => $m->isImage());
    }

    /** Remove every stored file of a company's product media (used by the data wiper). */
    public function purgeCompanyFiles(int $companyId): void
    {
        $disk = $this->disk();
        Storage::disk($disk)->deleteDirectory('products/'.$companyId);
    }

    // ── internals ──────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $attributes */
    private function createRow(Product $product, array $attributes): ProductMedia
    {
        return DB::transaction(function () use ($product, $attributes): ProductMedia {
            $base = ProductMedia::query()->withoutGlobalScopes()->where('product_id', $product->getKey());
            $isFirstImage = ($attributes['kind'] === ProductMedia::KIND_IMAGE)
                && ! (clone $base)->where('kind', ProductMedia::KIND_IMAGE)->exists();

            return ProductMedia::create(array_merge($attributes, [
                'company_id' => $product->company_id,
                'product_id' => $product->getKey(),
                'sort' => ((int) (clone $base)->max('sort')) + 1,
                'is_primary' => $isFirstImage,
            ]));
        });
    }

    /** A value may be attached only on a variable parent, and only one of the same company. */
    private function checkedValueId(Product $product, ?int $valueId): ?int
    {
        if ($valueId === null) {
            return null;
        }
        if (! $product->isVariable()) {
            throw new InvalidArgumentException('Σύνδεση με χρώμα/τιμή γίνεται μόνο σε προϊόν με παραλλαγές.');
        }
        if (! in_array($valueId, $this->usedValueIds($product), true)) {
            throw new InvalidArgumentException('Η τιμή δεν χρησιμοποιείται από τις παραλλαγές αυτού του προϊόντος.');
        }

        return $valueId;
    }

    /**
     * GD holds the whole bitmap in memory (~4 bytes/pixel, plus the resized
     * copies). Refuse what can't fit instead of dying with a fatal error; raise
     * memory_limit for this request when that is enough.
     */
    private function assertDecodable(int $width, int $height, string $name): void
    {
        $pixels = $width * $height;
        $maxMp = (float) config('ekdosi.product_media.max_megapixels', 50);   // a 48 MP phone shot ≈ 8000×6000
        if ($pixels > $maxMp * 1_000_000) {
            throw new InvalidArgumentException('Η εικόνα «'.$name.'» είναι πολύ μεγάλη ('.round($pixels / 1_000_000, 1).' MP) — μέχρι '
                .$maxMp.' MP.');
        }

        $needed = (int) ($pixels * 4 * 2.5) + memory_get_usage(true);
        $limit = $this->bytes((string) ini_get('memory_limit'));
        if ($limit > 0 && $needed > $limit) {
            @ini_set('memory_limit', (string) $needed);
            if ($this->bytes((string) ini_get('memory_limit')) < $needed) {
                throw new InvalidArgumentException('Δεν φτάνει η μνήμη για την εικόνα «'.$name.'» — ανέβασε μικρότερη ανάλυση.');
            }
        }
    }

    private function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return 0;   // unlimited
        }
        $n = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }

    /**
     * Insert the row; if that fails, delete the files just written so nothing is
     * orphaned on disk.
     *
     * @param  list<string>  $paths
     * @param  array<string, mixed>  $attributes
     */
    private function createRowOrCleanUp(string $disk, array $paths, Product $product, array $attributes): ProductMedia
    {
        try {
            return $this->createRow($product, $attributes);
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($paths);
            throw $e;
        }
    }

    private function assertSize(string $path, int $maxKb, string $name): void
    {
        if ((int) filesize($path) > $maxKb * 1024) {
            throw new InvalidArgumentException('Το «'.$name.'» ξεπερνά το όριο των '.round($maxKb / 1024, 1).' MB.');
        }
    }

    private function disk(): string
    {
        return (string) config('ekdosi.product_media.disk', 'local');
    }

    private function directory(Product $product): string
    {
        return 'products/'.$product->company_id.'/'.$product->getKey();
    }

    private function load(string $path, string $mime): GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            'image/gif' => @imagecreatefromgif($path),
        };
        if (! $image instanceof GdImage) {
            throw new InvalidArgumentException('Η εικόνα δεν διαβάζεται (κατεστραμμένο αρχείο;).');
        }
        // Keep transparency for PNG/WebP/GIF.
        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }

    /** Phone photos carry their rotation in EXIF — bake it in (EXIF is dropped on re-encode). */
    private function applyExifOrientation(GdImage $image, string $path, string $mime): GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }
        $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated instanceof GdImage ? $rotated : $image;
    }

    /** Down-scale so the longest side is ≤ $max (never up-scales). */
    private function resized(GdImage $image, int $max): GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        if (max($w, $h) <= $max) {
            return $image;
        }
        $scale = $max / max($w, $h);
        $out = imagecreatetruecolor(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagecopyresampled($out, $image, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);

        return $out;
    }

    private function encode(GdImage $image, string $ext): string
    {
        ob_start();
        match ($ext) {
            'png' => imagepng($image, null, 6),
            'webp' => imagewebp($image, null, 85),
            default => imagejpeg($image, null, 85),
        };

        return (string) ob_get_clean();
    }
}
