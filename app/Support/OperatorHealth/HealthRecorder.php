<?php

namespace App\Support\OperatorHealth;

use App\Models\Company;
use Illuminate\Support\Facades\Cache;
use Throwable;

class HealthRecorder
{
    /** @param array<string, mixed> $extra */
    public function recordScheduledRun(string $task, string $status, ?int $exitCode = null, array $extra = []): void
    {
        $this->forever(HealthKeys::scheduledTask($task), array_merge($extra, [
            'task' => $task,
            'status' => $status,
            'exit_code' => $exitCode,
            'ran_at' => now()->toIso8601String(),
        ]));
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
