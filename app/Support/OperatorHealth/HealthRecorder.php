<?php

namespace App\Support\OperatorHealth;

use App\Models\Company;
use App\Models\ScheduledTaskRun;
use Illuminate\Support\Facades\Cache;
use Throwable;

class HealthRecorder
{
    /** Durable run rows kept per task — older ones pruned on each close. */
    private const RUN_HISTORY_KEEP = 50;

    /** @param array<string, mixed> $extra */
    public function recordScheduledRun(string $task, string $status, ?int $exitCode = null, array $extra = []): void
    {
        // Fast snapshot (latest-only) for the at-a-glance report — survives a
        // missing scheduled_task_runs table, so the report never goes dark.
        $this->forever(HealthKeys::scheduledTask($task), array_merge($extra, [
            'task' => $task,
            'status' => $status,
            'exit_code' => $exitCode,
            'ran_at' => now()->toIso8601String(),
        ]));

        // Durable history: 'running' opens a row, a terminal status closes the
        // latest open row (or stands alone if the before-hook never fired).
        $this->recordRunHistory($task, $status, $exitCode, $extra['summary'] ?? null);
    }

    private function recordRunHistory(string $task, string $status, ?int $exitCode, ?string $summary): void
    {
        try {
            if ($status === 'running') {
                ScheduledTaskRun::create([
                    'task' => $task, 'status' => 'running', 'started_at' => now(),
                ]);

                return;
            }

            $open = ScheduledTaskRun::query()
                ->where('task', $task)->where('status', 'running')->whereNull('finished_at')
                ->latest('started_at')->first();

            if ($open) {
                $open->forceFill([
                    'status' => $status,
                    'exit_code' => $exitCode,
                    'summary' => $summary,
                    'finished_at' => now(),
                    'duration_ms' => $open->started_at ? (int) $open->started_at->diffInMilliseconds(now()) : null,
                ])->save();
            } else {
                // No open row (e.g. only a terminal hook fired) — record a point-in-time run.
                ScheduledTaskRun::create([
                    'task' => $task, 'status' => $status, 'exit_code' => $exitCode,
                    'summary' => $summary, 'started_at' => now(), 'finished_at' => now(), 'duration_ms' => 0,
                ]);
            }

            $this->pruneRunHistory($task);
        } catch (Throwable) {
            // History is best-effort: the table may not be migrated yet, and a
            // recording failure must never break the business command.
        }
    }

    private function pruneRunHistory(string $task): void
    {
        $cutoff = ScheduledTaskRun::query()
            ->where('task', $task)
            ->orderByDesc('id')
            ->skip(self::RUN_HISTORY_KEEP)
            ->value('id');

        if ($cutoff) {
            ScheduledTaskRun::query()->where('task', $task)->where('id', '<=', $cutoff)->delete();
        }
    }

    /** @param array<string, mixed> $extra */
    public function recordWhmcsFetch(Company $tenant, int $exitCode, array $extra = []): void
    {
        $ok = in_array($exitCode, [0], true);
        $previous = $this->get(HealthKeys::whmcsFetch((int) $tenant->id), []);

        $this->forever(HealthKeys::whmcsFetch((int) $tenant->id), array_merge($previous, $extra, [
            'company_id' => $tenant->id,
            'tenant' => $tenant->slug,
            'status' => $ok ? 'ok' : 'failed',
            'exit_code' => $exitCode,
            'last_success_at' => $ok ? now()->toIso8601String() : ($previous['last_success_at'] ?? null),
            'last_failure_at' => $ok ? ($previous['last_failure_at'] ?? null) : now()->toIso8601String(),
            'checked_at' => now()->toIso8601String(),
        ]));
    }

    /** @param array<string, mixed> $extra */
    public function recordMyDataReconcile(Company $tenant, int $exitCode, int $discrepancies = 0, array $extra = []): void
    {
        $ok = in_array($exitCode, [0, 2], true);
        $previous = $this->get(HealthKeys::myDataReconcile((int) $tenant->id), []);

        $this->forever(HealthKeys::myDataReconcile((int) $tenant->id), array_merge($previous, $extra, [
            'company_id' => $tenant->id,
            'tenant' => $tenant->slug,
            'status' => $ok ? ($discrepancies > 0 ? 'discrepancies' : 'ok') : 'failed',
            'exit_code' => $exitCode,
            'discrepancies' => $discrepancies,
            'last_success_at' => $ok ? now()->toIso8601String() : ($previous['last_success_at'] ?? null),
            'last_failure_at' => $ok ? ($previous['last_failure_at'] ?? null) : now()->toIso8601String(),
            'last_aade_call_at' => $ok ? now()->toIso8601String() : ($previous['last_aade_call_at'] ?? null),
            'checked_at' => now()->toIso8601String(),
        ]));
    }

    public function recordQueueHeartbeat(): void
    {
        $this->forever(HealthKeys::QUEUE_HEARTBEAT, now()->toIso8601String());
    }

    /** @param array<string, mixed> $result */
    public function recordBackupMonitor(array $result): void
    {
        $this->forever(HealthKeys::BACKUP_MONITOR_RESULT, array_merge($result, [
            'checked_at' => now()->toIso8601String(),
        ]));
    }

    private function get(string $key, mixed $default = null): mixed
    {
        try {
            return Cache::get($key, $default);
        } catch (Throwable) {
            return $default;
        }
    }

    /** @param array<string, mixed> $payload */
    private function forever(string $key, array|string $payload): void
    {
        try {
            Cache::forever($key, $payload);
        } catch (Throwable) {
            // Health recording must never make the business command fail.
        }
    }
}
