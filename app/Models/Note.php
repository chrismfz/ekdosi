<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An internal, operator-only note on a tenant-owned record (customer, invoice…).
 * NEVER printed on a PDF, NEVER sent to AADE — purely for the back office. The
 * "internal note per παραστατικό" lives here, distinct from the printed
 * `invoices.notes` field.
 */
class Note extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    /** Origin marker for an imported (ETL-synced) note; NULL = operator-authored. */
    public const SOURCE_BACKUP = 'backup';

    protected $fillable = [
        'company_id',
        'notable_type',
        'notable_id',
        'body',
        'is_pinned',
        'source',
        'author_user_id',
    ];

    /** Whether the note is import-managed (read-only for operators). */
    public function isImported(): bool
    {
        return $this->source !== null;
    }

    /** Operator-facing label for the note's origin (null = no badge). */
    public function sourceLabel(): ?string
    {
        return $this->source === self::SOURCE_BACKUP ? 'από backup' : null;
    }

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
        ];
    }

    public function notable(): MorphTo
    {
        return $this->morphTo();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
