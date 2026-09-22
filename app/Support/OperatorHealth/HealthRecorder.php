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

    /**
     * Consecutive `delivery:fetch-inbound` failures before a transient hiccup is
     * treated as PERSISTENT (escalated log + ops:health flag). The single source of
     * truth shared by the command (which escalates) and OperatorHealthReport (which
     * marks the row 'persistent' for severity), so the two can never drift.
     */
    public const DELIVERY_INBOUND_PERSISTENT_FAILURES = 4;

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

    /**
     * `delivery:fetch-inbound` is a read-only, self-healing 6-hourly poll: a single
     * failure is a transient AADE hiccup and must NOT alert (that's the midnight
     * false-alarm we deliberately silenced). We track CONSECUTIVE failures so a
     * PERSISTENT problem (bad/expired creds, a multi-day outage) surfaces after N in
     * a row — a success resets the count. Returns the running consecutive-failure
     * count so the command can decide whether to escalate its log to error.
     *
     * @param  array<string, mixed>  $extra
     */
    public function recordDeliveryInboundFetch(Company $tenant, bool $ok, array $extra = []): int
    {
        $previous = $this->get(HealthKeys::deliveryInbound((int) $tenant->id), []);
        $consecutive = $ok ? 0 : ((int) ($previous['consecutive_failures'] ?? 0) + 1);

        $this->forever(HealthKeys::deliveryInbound((int) $tenant->id), array_merge($previous, $extra, [
            'company_id' => $tenant->id,
            'tenant' => $tenant->slug,
            'status' => $ok ? 'ok' : 'failed',
            'consecutive_failures' => $consecutive,
            // Keep the reason WHY on the health row (cleared on recovery), so the
            // ops:health surface can summarise the failure without a log dive.
            'last_error' => $ok ? null : ($extra['last_error'] ?? ($previous['last_error'] ?? null)),
            'last_success_at' => $ok ? now()->toIso8601String() : ($previous['last_success_at'] ?? null),
            'last_failure_at' => $ok ? ($previous['last_failure_at'] ?? null) : now()->toIso8601String(),
            'checked_at' => now()->toIso8601String(),
        ]));

        return $consecutive;
    }

    public function recordQueueHeartbeat(): void
    {
        $this->forever(HealthKeys::QUEUE_HEARTBEAT, now()->toIso8601String());
    }

    /**
     * OPS-001: the scheduler tick — recorded directly by a `->everyMinute()`
     * closure in routes/console.php, so it proves `schedule:run` is firing WITHOUT
     * a queue worker. A stale/missing value = the OS cron isn't calling
     * schedule:run (or the app can't reach its cache).
     */
    public function recordSchedulerHeartbeat(): void
    {
        $this->forever(HealthKeys::SCHEDULER_HEARTBEAT, now()->toIso8601String());
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
