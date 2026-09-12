<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * One row per auth event (login / logout / failed / lockout) on either panel.
 * Append-only audit trail — see the migration + App\Listeners\RecordAuthEvent.
 *
 * NOT tenant-scoped (no CompanyScope): the log is system-level and super-admin
 * only. Prunable so the table stays bounded even under a sustained brute-force
 * flood — `model:prune` (scheduled, gated) drops rows past the retention window.
 */
class AuthEvent extends Model
{
    use Prunable;

    // Append-only: we set created_at explicitly, there is no updated_at column.
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /** Rows older than the retention window are pruned by `model:prune`. */
    public function prunable(): Builder
    {
        $days = max(1, (int) config('ekdosi.auth_events_retention_days', 180));

        return static::query()->where('created_at', '<=', now()->subDays($days));
    }

    /** Panel label for display: which surface the event happened on. */
    public function panelLabel(): string
    {
        return match ($this->guard) {
            'web' => '/admin',
            'portal' => '/user',
            default => (string) $this->guard,
        };
    }
}
