<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PR #30 — One row per operator-initiated `.fbk` import attempt.
 *
 * Lifecycle: uploaded → restoring → importing → completed | failed.
 * Rows are immutable (no soft-delete, no edit) — they're audit trail.
 *
 * Multi-tenant via `company_id`. Filament 5 detects tenancy from
 * the `company()` relation defined below (no trait import needed
 * on the model — the trait is applied to the Filament Resource,
 * not the Eloquent model).
 */
class FirebirdImportRun extends Model
{
    use BelongsToCompany;

    use HasFactory;

    public const STATUS_UPLOADED  = 'uploaded';
    public const STATUS_RESTORING = 'restoring';
    public const STATUS_IMPORTING = 'importing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';

    public const SOURCE_FIREBIRD = 'firebird';
    public const SOURCE_EPSILON  = 'epsilon';

    protected $fillable = [
        'company_id',
        'source',
        'uploaded_by_user_id',
        'file_name',
        'file_size',
        'file_sha256',
        'uploaded_path',
        'source_files_json',
        'status',
        'started_at',
        'finished_at',
        'counts_json',
        'error_message',
        'failed_step',
        'fb_host',
        'fb_user',
    ];

    protected function casts(): array
    {
        return [
            'started_at'  => 'datetime',
            'finished_at' => 'datetime',
            'counts_json' => 'array',
            'source_files_json' => 'array',
            'file_size'   => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function uploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    public function durationSeconds(): ?int
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }
        return $this->started_at->diffInSeconds($this->finished_at);
    }
}
