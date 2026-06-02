<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Bytes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

/**
 * A file attached to a tenant-owned record (customer, invoice, …). The bytes
 * live on a private disk; this row is just metadata + uploader audit.
 *
 * Hard-deleted on purpose (no SoftDeletes): an attachment is a pointer to a
 * file, so deleting the row also drops the bytes — soft-deleting would leave
 * orphaned, unreachable files on the private disk (storage bloat + a GDPR
 * erasure gap).
 */
class Attachment extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'attachable_type',
        'attachable_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'title',
        'uploaded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Deleting the row drops the bytes too — no orphaned files left behind.
        static::deleted(function (self $attachment): void {
            if ($attachment->path && Storage::disk($attachment->disk)->exists($attachment->path)) {
                Storage::disk($attachment->disk)->delete($attachment->path);
            }
        });
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /** Human-readable file size (e.g. "1.2 MB"; null → "—", real 0-byte → "0 B"). */
    public function humanSize(): string
    {
        return Bytes::forHumans($this->size);
    }
}
