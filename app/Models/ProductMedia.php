<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * A product photo, uploaded video, or video link (docs/woocommerce-bridge-plan.md §0).
 *
 * Hard-deleted on purpose (like Attachment): the row is a pointer to files, so
 * deleting it drops the bytes too — no orphaned images on disk.
 */
class ProductMedia extends Model
{
    use BelongsToCompany;
    use HasFactory;

    public const KIND_IMAGE = 'image';

    public const KIND_VIDEO = 'video';

    public const KIND_VIDEO_LINK = 'video_link';

    public const KINDS = [
        self::KIND_IMAGE => 'Φωτογραφία',
        self::KIND_VIDEO => 'Βίντεο (αρχείο)',
        self::KIND_VIDEO_LINK => 'Βίντεο (σύνδεσμος)',
    ];

    protected $table = 'product_media';

    protected $fillable = [
        'company_id',
        'product_id',
        'product_attribute_value_id',
        'kind',
        'disk',
        'path',
        'thumb_path',
        'url',
        'original_name',
        'mime_type',
        'size',
        'width',
        'height',
        'alt',
        'sort',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'sort' => 'integer',
            'is_primary' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(function (self $media): void {
            if ($media->disk === null) {
                return;
            }
            foreach ([$media->path, $media->thumb_path] as $path) {
                if ($path && Storage::disk($media->disk)->exists($path)) {
                    Storage::disk($media->disk)->delete($path);
                }
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function attributeValue(): BelongsTo
    {
        return $this->belongsTo(ProductAttributeValue::class, 'product_attribute_value_id');
    }

    public function isImage(): bool
    {
        return $this->kind === self::KIND_IMAGE;
    }

    /**
     * Stable, permanently-signed URL to the stored file ('full' or 'thumb').
     * Stable so the browser caches it; signed so ids can't be enumerated; public
     * (no login) so WooCommerce can fetch it. A video link returns its own URL.
     */
    public function publicUrl(string $variant = 'full'): ?string
    {
        if ($this->kind === self::KIND_VIDEO_LINK) {
            return $this->url;
        }
        if ($variant === 'thumb' && ! $this->thumb_path) {
            $variant = 'full';
        }

        return URL::signedRoute('product-media.show', ['media' => $this->getKey(), 'variant' => $variant]);
    }
}
