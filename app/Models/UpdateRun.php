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

    public const KIND_UPDATE = 'update';

    public const KIND_ROLLBACK = 'rollback';

    protected $fillable = [
        'status',
        'kind',
        'phase',
        'strategy',
        'from_version',
        'from_ref',
        'to_version',
        'to_ref',
        'rollback_of_id',
        'snapshot_file',
        'restore_snapshot',
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

    /** The update run this one reverts (rollback runs only). */
    public function rollbackOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rollback_of_id');
    }

    public function isRollback(): bool
    {
        return $this->kind === self::KIND_ROLLBACK;
    }

    /**
     * Is applying code from inside the app armed? (UPD-001…015, triage 2026-09-02.)
     *
     * OFF by default. The update CHECK stays on — it is read-only and useful — but
     * the APPLY is not something a web request should drive: the audit found it
     * does not drain the queue worker it restarts, fails open after a partial apply
     * (maintenance lifted over inconsistent code), targets a mutable tag rather than
     * a verified SHA, and can start without a proven rollback path. The supported
     * upgrade is `deploy/update.sh <tag>` on the host.
     *
     * ONE definition, deliberately: the same flag hides the install button and the
     * rollback action, and — the part that actually matters — SelfUpdate refuses
     * any queued run, so nothing can be applied even if a row is created some other
     * way. Kept OUT of canRollback(), which answers a different question (is this
     * run structurally reversible) that arming does not change.
     */
    public static function inAppApplyEnabled(): bool
    {
        return (bool) config('ekdosi.updates.allow_in_app_apply', false);
    }

    /**
     * Can this (update) run be rolled back? It must be a finished UPDATE that took
     * a snapshot and recorded where it came from, nothing else in flight, and — the
     * safety rule — it must be the LATEST update: restoring an older snapshot rewinds
     * the whole DB past every newer update too, so only the most recent one is
     * reversible (roll those back in turn).
     *
     * Purely STRUCTURAL: whether the in-app applier is armed is a separate question
     * (inAppApplyEnabled()) that the callers test alongside this one.
     */
    public function canRollback(): bool
    {
        return $this->kind === self::KIND_UPDATE
            && in_array($this->status, [self::STATUS_SUCCEEDED, self::STATUS_FAILED], true)
            && filled($this->snapshot_file)
            && filled($this->from_ref)
            && ! static::hasActive()
            && ! static::query()
                ->where('kind', self::KIND_UPDATE)
                ->where('id', '>', $this->id)
                ->exists();
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
