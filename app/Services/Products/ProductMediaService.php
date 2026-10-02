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
use RuntimeException;

/**
 * Stores and resolves product photos & videos (docs/woocommerce-bridge-plan.md §0).
 *
 * Images are RE-ENCODED with GD: EXIF orientation applied (all 8 cases), EXIF
 * (incl. GPS) stripped, the full image capped at FULL_PX and a thumbnail made
 * from it. Videos are stored as-is (size-capped); video LINKS store only an
 * allow-listed https URL. Nothing here is public: files are served to signed-in
 * operators only (ProductMediaController) — publishing to the e-shop is the
 * WooCommerce bridge's job (plan §6 Phase 4).
 *
 * The primary photo is simply the FIRST image by `sort` — dragging a photo to
 * the top makes it primary, and there is no flag to keep in sync.
 */
class ProductMediaService
{
    /** Longest side of the stored "full" image. */
    private const FULL_PX = 2000;

    private const THUMB_PX = 400;

    /** Accepted image types → [stored extension, stored mime] (GIF is stored as PNG — no animation kept). */
    private const IMAGE_TYPES = [
        'image/jpeg' => ['jpg', 'image/jpeg'],
        'image/png' => ['png', 'image/png'],
        'image/webp' => ['webp', 'image/webp'],
        'image/gif' => ['png', 'image/png'],
    ];

    private const VIDEO_TYPES = [
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/quicktime' => 'mov',
    ];

    /** Exact hosts a video link may point at (https only; «www.» is stripped first). */
    private const VIDEO_HOSTS = [
        'youtube.com', 'm.youtube.com', 'youtu.be',
        'vimeo.com', 'player.vimeo.com',
        'instagram.com',
        'tiktok.com', 'vm.tiktok.com',
        'facebook.com', 'm.facebook.com', 'fb.watch',
    ];

    /** Paths that are the allowed hosts' open redirectors. */
    private const REDIRECT_PATHS = ['/redirect', '/l.php', '/link'];

    /** @return list<string> mime types the image upload accepts (for the upload widget). */
    public static function imageMimes(): array
    {
        return array_keys(self::IMAGE_TYPES);
    }

    /** @return list<string> mime types the video upload accepts (for the upload widget). */
    public static function videoMimes(): array
    {
        return array_keys(self::VIDEO_TYPES);
    }

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
        $this->assertSize($sourcePath, (int) config('ekdosi.product_media.max_image_kb'), $originalName);
        $this->assertWithinPixels((int) $info[0], (int) $info[1], $originalName);

        [$ext, $storedMime] = self::IMAGE_TYPES[$mime];

        [$fullBytes, $thumbBytes] = $this->withMemoryFor((int) $info[0] * (int) $info[1], $originalName, function () use ($sourcePath, $mime, $ext): array {
            $image = $this->load($sourcePath, $mime);
            $image = self::orient($image, $this->exifOrientation($sourcePath, $mime));
            $full = $this->resized($image, self::FULL_PX);
            unset($image);   // free the original bitmap before the thumbnail pass
            $thumb = $this->resized($full, self::THUMB_PX);

            return [$this->encode($full, $ext), $this->encode($thumb, $ext)];
        });

        $disk = $this->disk();
        $dir = $this->directory($product);
        $name = (string) Str::uuid();
        $path = "{$dir}/{$name}.{$ext}";
        $thumbPath = "{$dir}/thumbs/{$name}.{$ext}";

        $this->put($disk, $path, $fullBytes);
        try {
            $this->put($disk, $thumbPath, $thumbBytes);
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($path);
            throw $e;
        }

        return $this->createRowOrCleanUp($disk, [$path, $thumbPath], $product, [
            'kind' => ProductMedia::KIND_IMAGE,
            'disk' => $disk,
            'path' => $path,
            'thumb_path' => $thumbPath,
            'original_name' => mb_substr($originalName, 0, 255),
            'mime_type' => $storedMime,
            'size' => strlen($fullBytes),
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
        $this->assertSize($sourcePath, (int) config('ekdosi.product_media.max_video_kb'), $originalName);

        $disk = $this->disk();
        $path = $this->directory($product).'/'.Str::uuid().'.'.self::VIDEO_TYPES[$mime];
        $stream = fopen($sourcePath, 'rb');
        try {
            if ($stream === false || ! Storage::disk($disk)->writeStream($path, $stream)) {
                throw new RuntimeException('Δεν αποθηκεύτηκε το «'.$originalName.'» (δίσκος).');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $this->createRowOrCleanUp($disk, [$path], $product, [
            'kind' => ProductMedia::KIND_VIDEO,
            'disk' => $disk,
            'path' => $path,
            'original_name' => mb_substr($originalName, 0, 255),
            'mime_type' => $mime,
            'size' => (int) filesize($sourcePath),
            'product_attribute_value_id' => $valueId,
        ]);
    }

    /** Store a video LINK (YouTube / Vimeo / Instagram / TikTok / Facebook, https only). */
    public function addVideoLink(Product $product, string $url, ?int $valueId = null): ProductMedia
    {
        $valueId = $this->checkedValueId($product, $valueId);
        $url = trim($url);

        if (! self::isAllowedVideoLink($url)) {
            throw new InvalidArgumentException('Δεκτοί σύνδεσμοι: https από YouTube, Vimeo, Instagram, TikTok ή Facebook.');
        }

        return $this->createRow($product, [
            'kind' => ProductMedia::KIND_VIDEO_LINK,
            'url' => $url,
            'product_attribute_value_id' => $valueId,
        ]);
    }

    /**
     * Strict allow-list: https, an exact known host, no userinfo/port/backslash
     * (parse_url and browsers disagree on those), and none of the hosts' own
     * open-redirect endpoints.
     */
    public static function isAllowedVideoLink(string $url): bool
    {
        if ($url === '' || mb_strlen($url) > 500 || str_contains($url, '\\') || preg_match('/\s/u', $url)) {
            return false;
        }
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        if (! in_array($host, self::VIDEO_HOSTS, true)) {
            return false;
        }
        $path = strtolower((string) ($parts['path'] ?? '/'));

        return ! collect(self::REDIRECT_PATHS)->contains(fn (string $p) => $path === $p || str_starts_with($path, $p.'/'));
    }

    /** Make this image the primary one = move it to the top of the order. */
    public function makePrimary(ProductMedia $media): void
    {
        if (! $media->isImage()) {
            throw new InvalidArgumentException('Κύρια μπορεί να είναι μόνο φωτογραφία.');
        }

        DB::transaction(function () use ($media): void {
            $ids = ProductMedia::query()->withoutGlobalScopes()
                ->where('product_id', $media->product_id)
                ->whereKeyNot($media->getKey())
                ->orderBy('sort')->orderBy('id')
                ->pluck('id')
                ->prepend($media->getKey());

            foreach ($ids->values() as $i => $id) {
                ProductMedia::query()->withoutGlobalScopes()->whereKey($id)->update(['sort' => $i + 1]);
            }
        });
    }

    /** Update the alt text / colour of a media row (same value rules as uploading; its current colour stays valid). */
    public function updateDetails(ProductMedia $media, ?string $alt, ?int $valueId): void
    {
        $product = $media->product()->withTrashed()->firstOrFail();
        if ($valueId !== null && $valueId !== $media->product_attribute_value_id) {
            $valueId = $this->checkedValueId($product, $valueId);
        }

        $media->update([
            'alt' => $alt !== null && $alt !== '' ? mb_substr($alt, 0, 255) : null,
            'product_attribute_value_id' => $valueId,
        ]);
    }

    /** Delete a media row; its files go once the delete commits (ProductMedia::deleted). */
    public function delete(ProductMedia $media): void
    {
        $media->delete();
    }

    /**
     * The media a product SHOWS, in display order — the ONE rule, used by the
     * products list thumbnail and (later) the WooCommerce bridge. Uses the loaded
     * relations when present (media, parent.media, variantValues).
     *
     * Per kind (photos / videos): a variant shows its OWN when it has any of that
     * kind, else its parent's for its values (e.g. the black photos) first, then
     * the parent's general ones — never another colour's.
     *
     * @return Collection<int, ProductMedia>
     */
    public static function displayMedia(Product $product): Collection
    {
        $own = $product->media->sortBy(fn (ProductMedia $m) => [$m->sort, $m->id])->values();

        $parent = $product->isVariant() ? $product->parent : null;
        if ($parent === null) {
            return $own;
        }

        $valueIds = $product->variantValues->pluck('id')->map(fn ($id) => (int) $id)->all();
        $inherited = $parent->media
            ->filter(fn (ProductMedia $m) => $m->product_attribute_value_id === null || in_array((int) $m->product_attribute_value_id, $valueIds, true))
            ->sortBy(fn (ProductMedia $m) => [$m->product_attribute_value_id === null ? 1 : 0, $m->sort, $m->id])
            ->values();

        $pick = fn (bool $images) => (($mine = $own->filter(fn (ProductMedia $m) => $m->isImage() === $images))->isNotEmpty()
            ? $mine
            : $inherited->filter(fn (ProductMedia $m) => $m->isImage() === $images))->values();

        return $pick(true)->concat($pick(false))->values();
    }

    /** The photo a product shows first (null when none). */
    public static function primaryImage(Product $product): ?ProductMedia
    {
        return self::displayMedia($product)->first(fn (ProductMedia $m) => $m->isImage());
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

    /**
     * Every disk a company's media lives on (rows can predate a disk switch) plus
     * the configured one — collect BEFORE the rows are deleted.
     *
     * @return list<string>
     */
    public function companyDisks(int $companyId): array
    {
        return ProductMedia::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNotNull('disk')
            ->distinct()
            ->pluck('disk')
            ->push($this->disk())
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<string> disks one product's media lives on, plus the configured one */
    public function productDisks(Product $product): array
    {
        return ProductMedia::query()->withoutGlobalScopes()
            ->where('product_id', $product->getKey())
            ->whereNotNull('disk')
            ->distinct()
            ->pluck('disk')
            ->push($this->disk())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Remove a company's (or one product's) media directory on each disk. Never
     * throws — a failed cleanup must not break the delete it follows; it is reported.
     *
     * @param  list<string>  $disks
     */
    public function purgeFiles(array $disks, int $companyId, ?int $productId = null): void
    {
        $dir = 'products/'.$companyId.($productId !== null ? '/'.$productId : '');
        foreach ($disks as $disk) {
            try {
                Storage::disk($disk)->deleteDirectory($dir);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * EXIF orientation 1–8 → upright image (rotation + mirror), since the EXIF tag
     * itself is dropped on re-encode and nothing could correct it later.
     */
    public static function orient(GdImage $image, int $orientation): GdImage
    {
        $rotate = fn (GdImage $img, int $angle) => ($r = imagerotate($img, $angle, 0)) instanceof GdImage ? $r : $img;

        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 3:
                $image = $rotate($image, 180);
                break;
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);
                break;
            case 5:
                $image = $rotate($image, -90);
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 6:
                $image = $rotate($image, -90);
                break;
            case 7:
                $image = $rotate($image, 90);
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 8:
                $image = $rotate($image, 90);
                break;
        }

        return $image;
    }

    // ── internals ──────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $attributes */
    private function createRow(Product $product, array $attributes): ProductMedia
    {
        $sort = ((int) ProductMedia::query()->withoutGlobalScopes()->where('product_id', $product->getKey())->max('sort')) + 1;

        return ProductMedia::create(array_merge($attributes, [
            'company_id' => $product->company_id,
            'product_id' => $product->getKey(),
            'sort' => min($sort, 65535),
        ]));
    }

    /** A value may be attached only on a variable parent, and only one its live variants use. */
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

    private function assertWithinPixels(int $width, int $height, string $name): void
    {
        $pixels = $width * $height;
        $maxMp = min(100.0, (float) config('ekdosi.product_media.max_megapixels'));   // hard ceiling, whatever the env says
        if ($pixels > $maxMp * 1_000_000) {
            throw new InvalidArgumentException('Η εικόνα «'.$name.'» είναι πολύ μεγάλη ('.round($pixels / 1_000_000, 1).' MP) — μέχρι '
                .$maxMp.' MP.');
        }
    }

    /**
     * Run the GD work with enough memory (GD holds ~4 B/pixel, plus copies), then
     * RESTORE the previous limit — the same save/restore pattern as the PDF renderers.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function withMemoryFor(int $pixels, string $name, callable $work): mixed
    {
        $previous = (string) ini_get('memory_limit');
        $limit = self::iniBytes($previous);
        $needed = (int) ($pixels * 4 * 2.5) + memory_get_usage(true);

        if ($limit > 0 && $needed > $limit) {
            @ini_set('memory_limit', (string) $needed);
            if (self::iniBytes((string) ini_get('memory_limit')) < $needed) {
                throw new InvalidArgumentException('Δεν φτάνει η μνήμη για την εικόνα «'.$name.'» — ανέβασε μικρότερη ανάλυση.');
            }
        }

        try {
            return $work();
        } finally {
            @ini_set('memory_limit', $previous);
        }
    }

    /** php.ini shorthand («128M», «1G», «-1») → bytes; 0 = unlimited. */
    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || (int) $value <= 0) {
            return 0;
        }
        $n = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }

    private function put(string $disk, string $path, string $bytes): void
    {
        if ($bytes === '' || ! Storage::disk($disk)->put($path, $bytes)) {
            throw new RuntimeException('Δεν αποθηκεύτηκε η εικόνα (δίσκος ή κωδικοποίηση).');
        }
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
        return (string) config('ekdosi.product_media.disk');
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
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            'image/gif' => @imagecreatefromgif($path),
        };
        if (! $image instanceof GdImage) {
            throw new InvalidArgumentException('Η εικόνα δεν διαβάζεται (κατεστραμμένο αρχείο ή κινούμενο WebP).');
        }
        // Keep transparency for PNG/WebP/GIF.
        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }

    private function exifOrientation(string $path, string $mime): int
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return 1;
        }

        return (int) (@exif_read_data($path)['Orientation'] ?? 1);
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
            'webp' => function_exists('imagewebp') ? imagewebp($image, null, 85) : false,
            default => imagejpeg($image, null, 85),
        };

        return (string) ob_get_clean();
    }
}
