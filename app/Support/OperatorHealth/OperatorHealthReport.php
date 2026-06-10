<?php

namespace App\Support\OperatorHealth;

use App\Models\Company;
use App\Models\InvoiceMailLog;
use App\Models\MyDataMark;
use App\Models\PendingWhmcsInvoice;
use App\Models\ScheduledTaskRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use SplFileInfo;
use Throwable;

class OperatorHealthReport
{
    /**
     * Known scheduled-task keys → human labels (shared by the per-task summary
     * and the durable run-history list).
     *
     * @var array<string, string>
     */
    private const TASK_LABELS = [
        'whmcs_fetch' => 'WHMCS fetch',
        'mydata_reconcile' => 'myDATA reconcile',
        'mydata_vat_picture' => 'VAT picture refresh',
        'mail_sweep' => 'mail sweep',
        'backup_run' => 'backup run',
        'backup_cleanup' => 'backup cleanup',
        'backup_monitor' => 'backup monitor',
    ];

    /** @return array<string, mixed> */
    public function build(): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'queue' => $this->queue(),
            'scheduler' => $this->scheduler(),
            'recent_runs' => $this->recentRuns(),
            'backup' => $this->backup(),
            'mail' => $this->mail(),
            'whmcs' => $this->whmcs(),
            'mydata' => $this->mydata(),
            'disk' => $this->disk(),
        ];
    }

    /** @return array<string, mixed> */
    private function queue(): array
    {
        $heartbeat = $this->cacheGet(HealthKeys::QUEUE_HEARTBEAT);
        $ageMinutes = $heartbeat ? Carbon::parse($heartbeat)->diffInMinutes(now()) : null;

        return [
            'worker_heartbeat_at' => $heartbeat,
            'worker_heartbeat_age_minutes' => $ageMinutes,
            'worker_heartbeat_status' => $ageMinutes === null ? 'missing' : ($ageMinutes <= 10 ? 'ok' : 'stale'),
            'pending_jobs' => $this->tableCount('jobs'),
            'failed_jobs' => $this->tableCount('failed_jobs'),
        ];
    }

    /** @return array<string, mixed> */
    private function scheduler(): array
    {
        $rows = [];
        foreach (self::TASK_LABELS as $key => $label) {
            $payload = $this->cacheGet(HealthKeys::scheduledTask($key));
            $rows[$key] = [
                'label' => $label,
                'last_run_at' => $payload['ran_at'] ?? null,
                'status' => $payload['status'] ?? 'missing',
                'exit_code' => $payload['exit_code'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * Durable run history (last N across all tasks) — survives cache:clear,
     * unlike scheduler()'s latest-only snapshot.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentRuns(int $limit = 20): array
    {
        return $this->safeValue(fn () => ScheduledTaskRun::query()
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get()
            ->map(fn (ScheduledTaskRun $run): array => [
                'task' => $run->task,
                'label' => self::TASK_LABELS[$run->task] ?? $run->task,
                'status' => $run->status,
                'exit_code' => $run->exit_code,
                'duration_ms' => $run->duration_ms,
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'summary' => $run->summary,
            ])
            ->all(), []);
    }

    /** @return array<string, mixed> */
    private function backup(): array
    {
        $latest = $this->latestLocalBackup();
        $monitor = $this->cacheGet(HealthKeys::BACKUP_MONITOR_RESULT);

        return [
            'latest_backup_path' => $latest['path'] ?? null,
            'latest_backup_at' => $latest['modified_at'] ?? null,
            'latest_backup_age_hours' => isset($latest['modified_at']) ? round(Carbon::parse($latest['modified_at'])->diffInMinutes(now()) / 60, 2) : null,
            'latest_backup_size_bytes' => $latest['size_bytes'] ?? null,
            'monitor' => $monitor ?: ['status' => 'missing', 'checked_at' => null, 'exit_code' => null],
        ];
    }

    /** @return array<string, mixed>|null */
    private function latestLocalBackup(): ?array
    {
        $disk = Storage::disk('local');
        $root = $disk->path(config('backup.backup.name'));
        if (! is_dir($root)) {
            $root = storage_path('app/'.config('backup.backup.name'));
        }
        if (! is_dir($root)) {
            return null;
        }

        $latest = null;
        foreach (File::allFiles($root) as $file) {
            if (! str_ends_with($file->getFilename(), '.zip')) {
                continue;
            }
            if ($latest === null || $file->getMTime() > $latest->getMTime()) {
                $latest = $file;
            }
        }

        if (! $latest instanceof SplFileInfo) {
            return null;
        }

        return [
            'path' => $latest->getPathname(),
            'modified_at' => Carbon::createFromTimestamp($latest->getMTime())->toIso8601String(),
            'size_bytes' => $latest->getSize(),
        ];
    }

    /** @return array<string, mixed> */
    private function mail(): array
    {
        return [
            'failed_24h' => $this->safeCount(fn () => InvoiceMailLog::query()->where('status', 'failed')->where('failed_at', '>=', now()->subDay())->count()),
            'failed_7d' => $this->safeCount(fn () => InvoiceMailLog::query()->where('status', 'failed')->where('failed_at', '>=', now()->subDays(7))->count()),
            'stuck_queued_or_sending' => $this->safeCount(fn () => InvoiceMailLog::query()->whereIn('status', ['queued', 'sending'])->where('queued_at', '<', now()->subMinutes((int) config('ekdosi.schedule.mail_sweep_threshold_minutes', 15)))->count()),
            'latest_failure_at' => $this->safeValue(fn () => optional(InvoiceMailLog::query()->where('status', 'failed')->latest('failed_at')->first())->failed_at?->toIso8601String()),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function whmcs(): array
    {
        return $this->safeValue(fn () => Company::query()
            ->whereNotNull('whmcs_api_url')
            ->where('whmcs_api_url', '!=', '')
            ->orderBy('slug')
            ->get()
            ->map(function (Company $tenant): array {
                $cached = $this->cacheGet(HealthKeys::whmcsFetch((int) $tenant->id), []);

                return [
                    'tenant' => $tenant->slug,
                    'status' => $cached['status'] ?? 'missing',
                    'last_success_at' => $cached['last_success_at'] ?? null,
                    'last_failure_at' => $cached['last_failure_at'] ?? null,
                    'last_exit_code' => $cached['exit_code'] ?? null,
                    'pending_review' => $this->safeCount(fn () => PendingWhmcsInvoice::query()->where('company_id', $tenant->id)->where('status', 'pending_review')->count()),
                ];
            })
            ->all(), []);
    }

    /** @return array<int, array<string, mixed>> */
    private function mydata(): array
    {
        return $this->safeValue(fn () => Company::myDataReadable()
            ->sortBy('slug')
            ->values()
            ->map(function (Company $tenant): array {
                $cached = $this->cacheGet(HealthKeys::myDataReconcile((int) $tenant->id), []);
                $latestMark = $this->safeValue(fn () => MyDataMark::query()->where('company_id', $tenant->id)->latest('created_at')->first());

                return [
                    'tenant' => $tenant->slug,
                    'status' => $cached['status'] ?? 'missing',
                    'discrepancies' => $cached['discrepancies'] ?? null,
                    'last_success_at' => $cached['last_success_at'] ?? null,
                    'last_failure_at' => $cached['last_failure_at'] ?? null,
                    'last_aade_call_at' => $cached['last_aade_call_at'] ?? null,
                    'latest_mydata_mark_at' => $latestMark?->created_at?->toIso8601String(),
                ];
            })
            ->all(), []);
    }

    /** @return array<string, array<string, mixed>> */
    private function disk(): array
    {
        return [
            'storage' => $this->diskUsage(storage_path()),
            'logs' => $this->diskUsage(storage_path('logs')),
            'temp_uploads' => $this->diskUsage(storage_path('app/livewire-tmp')),
            'backups' => $this->diskUsage(storage_path('app/'.config('backup.backup.name'))),
        ];
    }

    /** @return array<string, mixed> */
    private function diskUsage(string $path): array
    {
        $existing = is_dir($path) || is_file($path);
        $probe = $existing ? $path : dirname($path);

        return [
            'path' => $path,
            'exists' => $existing,
            'used_bytes' => $existing ? $this->directorySize($path) : null,
            'free_bytes' => @disk_free_space($probe) ?: null,
            'total_bytes' => @disk_total_space($probe) ?: null,
        ];
    }

    private function directorySize(string $path): int
    {
        if (is_file($path)) {
            return (int) filesize($path);
        }
        if (! is_dir($path)) {
            return 0;
        }

        $size = 0;
        foreach (File::allFiles($path) as $file) {
            $size += $file->getSize();
        }

        return $size;
    }

    private function cacheGet(string $key, mixed $default = null): mixed
    {
        try {
            return Cache::get($key, $default);
        } catch (Throwable) {
            return $default;
        }
    }

    private function safeCount(callable $callback): ?int
    {
        return $this->safeValue($callback);
    }

    private function safeValue(callable $callback, mixed $default = null): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return $default;
        }
    }

    private function tableCount(string $table): ?int
    {
        try {
            return DB::table($table)->count();
        } catch (Throwable) {
            return null;
        }
    }
}
