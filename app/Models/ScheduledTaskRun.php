<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per scheduled-task run — the durable history behind the cache's
 * latest-run snapshot. Written only by {@see \App\Support\OperatorHealth\HealthRecorder}
 * (opened on 'running', closed on ok/failed); never tenant-scoped (cron-global).
 *
 * @property string $task
 * @property string $status     running|ok|failed
 * @property int|null $exit_code
 * @property int|null $duration_ms
 * @property string|null $summary
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 */
class ScheduledTaskRun extends Model
{
    protected $fillable = [
        'task', 'status', 'exit_code', 'duration_ms', 'summary', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'exit_code' => 'integer',
        'duration_ms' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
