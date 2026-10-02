<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A product photo, uploaded video, or video link (docs/woocommerce-bridge-plan.md §0).
 *
 * Hard-deleted on purpose (like Attachment): the row is a pointer to files, so
 * deleting it drops the bytes too (after commit) — no orphaned images on disk.
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
        'alt',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'sort' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Drop the bytes only once the delete COMMITS — a rolled-back delete must
        // not leave a row pointing at files that are gone. (Runs at once outside a
        // transaction.)
        static::deleted(function (self $media): void {
            if ($media->disk === null) {
                return;
            }
            $disk = $media->disk;
            $paths = array_values(array_filter([$media->path, $media->thumb_path]));
            DB::afterCommit(function () use ($disk, $paths): void {
                try {
                    Storage::disk($disk)->delete($paths);
                } catch (\Throwable $e) {
                    report($e);
                }
            });
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
     * URL for a signed-in operator ('full' or 'thumb'); a video link returns its
     * own URL. NOT public: the e-shop gets the files through the WooCommerce
     * bridge and serves them from its own CDN (docs/woocommerce-bridge-plan.md §6).
     */
    public function fileUrl(string $variant = 'full'): ?string
    {
        if ($this->kind === self::KIND_VIDEO_LINK) {
            return $this->url;
        }

        return route('product-media.show', ['media' => $this->getKey(), 'variant' => $variant === 'thumb' && $this->thumb_path ? 'thumb' : 'full']);
    }
}
