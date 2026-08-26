<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * In-app update (Phase 2) — one row per operator-triggered application update.
 *
 * GLOBAL (no company_id): an update is a whole-app deploy, not tenant data. Rows
 * are immutable audit; the live `output` + `phase` are written by
 * `ekdosi:self-update` as it runs and polled by the «Ενημερώσεις» page.
 *
 * Lifecycle: queued → running → succeeded | failed | rolled_back.
 */
class UpdateRun extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ROLLED_BACK = 'rolled_back';

    public const STRATEGY_PHP = 'php';

    public const STRATEGY_SCRIPT = 'script';

    protected $fillable = [
        'status',
        'phase',
        'strategy',
        'from_version',
        'from_ref',
        'to_version',
        'to_ref',
        'snapshot_file',
        'output',
        'error_message',
        'triggered_by_user_id',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function triggeredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCEEDED,
            self::STATUS_FAILED,
            self::STATUS_ROLLED_BACK,
        ], true);
    }

    /** Queued (not yet picked up) or actively running. */
    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }

    public function durationSeconds(): ?int
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at);
    }

    public function scopeQueued(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_QUEUED);
    }

    /** Is there work waiting for the scheduler to pick up? (the `->when()` gate) */
    public static function hasPending(): bool
    {
        return static::query()->queued()->exists();
    }

    /**
     * Is any update currently queued or running? Used to block a second trigger
     * (a deploy must be single-flight) and to decide what the page shows live.
     */
    public static function hasActive(): bool
    {
        return static::query()
            ->whereIn('status', [self::STATUS_QUEUED, self::STATUS_RUNNING])
            ->exists();
    }
}
