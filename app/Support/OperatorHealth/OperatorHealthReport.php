<?php

namespace App\Support\OperatorHealth;

use App\Models\Company;
use App\Models\CompanyBackupRun;
use App\Models\CompanyBackupSetting;
use App\Models\InvoiceMailLog;
use App\Models\MyDataMark;
use App\Models\PendingWhmcsInvoice;
use App\Models\ScheduledTaskRun;
use App\Models\Scopes\CompanyScope;
use App\Support\Settings\SystemSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
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
        'whmcs_auto_issue' => 'WHMCS auto-issue',
        'whmcs_payment_sync' => 'WHMCS payment sync',
        'whmcs_payment_reconcile' => 'WHMCS payment reconcile',
        'mydata_reconcile' => 'myDATA reconcile',
        'mydata_vat_picture' => 'VAT picture refresh',
        'mydata_fetch_expenses' => 'myDATA expenses refresh',
        'mydata_console_refresh' => 'myDATA console refresh',
        'mail_sweep' => 'mail sweep',
        'resend_failed_emails' => 'resend failed emails',
        'overdue_notifications' => 'overdue notifications',
        'leads_notify_due' => 'leads next-step reminders',
        'service_renewals' => 'service renewals',
        'service_dunning' => 'service dunning',
        'company_backups' => 'company backups',
        'backup_run' => 'backup run',
        'backup_cleanup' => 'backup cleanup',
        'backup_monitor' => 'backup monitor',
    ];

    /**
     * OPS-13: a per-tenant scheduled sweep (WHMCS fetch / myDATA reconcile) that
     * hasn't recorded a run in this long — WHILE its task is enabled — is «stale»:
     * the run silently stopped (flag flip, crash before recording, orphaned key
     * after a slug rename), so a cached «ok» is no longer trustworthy. Generous
     * vs the cadences (WHMCS every 15', reconcile daily) so a merely-late cron
     * doesn't false-alarm.
     */
    private const TENANT_SWEEP_STALE_HOURS = 26;

    /** @return array<string, mixed> */
    public function build(): array
    {
        $data = [
            'generated_at' => now()->toIso8601String(),
            'queue' => $this->queue(),
            'scheduler' => $this->scheduler(),
            'recent_runs' => $this->recentRuns(),
            'backup' => $this->backup(),
            'mail' => $this->mail(),
            'whmcs' => $this->whmcs(),
            'mydata' => $this->mydata(),
            'security' => $this->security(),
            'disk' => $this->disk(),
        ];

        // OPS-4: one distilled severity + exit code over the whole report.
        $data['severity'] = OperatorHealthSeverity::evaluate($data);

        return $data;
    }

    /**
     * The queue slice (worker heartbeat + pending/failed counts). Public so
     * callers that only need this — e.g. GoLiveCheckReport — can read it without
     * running the full build() (which also walks disk + probes every tenant).
     *
     * @return array<string, mixed>
     */
    public function queue(): array
    {
        $heartbeat = $this->cacheGet(HealthKeys::QUEUE_HEARTBEAT);
        $ageMinutes = $heartbeat ? Carbon::parse($heartbeat)->diffInMinutes(now()) : null;

        return [
            'worker_heartbeat_at' => $heartbeat,
            'worker_heartbeat_age_minutes' => $ageMinutes,
            'worker_heartbeat_status' => $ageMinutes === null ? 'missing' : ($ageMinutes <= 10 ? 'ok' : 'stale'),
            'pending_jobs' => $this->tableCount('jobs'),
            'failed_jobs' => $this->tableCount('failed_jobs'),
            // RECENT failures gate the severity/exit code; the all-time count above
            // is display-only (one old un-flushed failure must not warn forever).
            'failed_jobs_24h' => $this->recentFailedJobs(),
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

    /**
     * Backup-destination drivers that actually leave the VM = every CONFIGURED
     * destination driver except `local` (which lives on the same host, so it's
     * no disaster-recovery copy). Derived from `config/ekdosi.php →
     * backup.destinations` so a future cloud driver counts as off-site without
     * editing this file.
     *
     * @return list<string>
     */
    private function offsiteDrivers(): array
    {
        $configured = array_keys((array) config('ekdosi.backup.destinations', []));

        return array_values(array_filter($configured, static fn (string $d): bool => $d !== 'local'));
    }

    /** How many individual backup files to list on the health screen (newest first). */
    private const LOCAL_BACKUP_LIST_LIMIT = 12;

    /** @return array<string, mixed> */
    private function backup(): array
    {
        $local = $this->localBackups();
        $latest = $local['files'][0] ?? null;
        $monitor = $this->cacheGet(HealthKeys::BACKUP_MONITOR_RESULT);

        return [
            'latest_backup_path' => $latest['path'] ?? null,
            'latest_backup_at' => $latest['modified_at'] ?? null,
            'latest_backup_age_hours' => isset($latest['modified_at']) ? round(Carbon::parse($latest['modified_at'])->diffInMinutes(now()) / 60, 2) : null,
            'latest_backup_size_bytes' => $latest['size_bytes'] ?? null,
            // The whole-DB (spatie) artifacts themselves: where they live, how many,
            // total size, and the newest few with size + timestamp. So the operator
            // can see WHAT exists, not just that the last one is fresh.
            'local_dir' => $local['dir'],
            'local_count' => $local['count'],
            'local_total_bytes' => $local['total_bytes'],
            'local_files' => array_slice($local['files'], 0, self::LOCAL_BACKUP_LIST_LIMIT),
            'monitor' => $monitor ?: ['status' => 'missing', 'checked_at' => null, 'exit_code' => null],
            // Per-tenant off-site verification: are enabled backups actually
            // leaving the VM, and did the last off-site push succeed?
            'companies' => $this->companyBackups(),
        ];
    }

    /**
     * For every company with automated backups ENABLED, report whether an
     * off-site destination is configured and whether the latest run's off-site
     * push succeeded. `offsite_gap` is true if ANY enabled tenant has no off-site
     * destination, or its last off-site push failed — the "your backups never
     * leave the VM / aren't landing" warning. Read-only, all-tenant sweep (CLI
     * context: declare the intent by dropping the CompanyScope).
     *
     * @return array<string, mixed>
     */
    private function companyBackups(): array
    {
        return $this->safeValue(function (): array {
            $offsiteDrivers = $this->offsiteDrivers();

            $settings = CompanyBackupSetting::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('enabled', true)
                ->with('company:id,slug,name')
                ->get();

            $companies = [];
            $gap = false;
            $booksGap = false;

            foreach ($settings as $setting) {
                $destinations = is_array($setting->destinations) ? $setting->destinations : [];
                $offsiteConfigured = collect($destinations)->contains(
                    fn ($d): bool => in_array($d['driver'] ?? null, $offsiteDrivers, true)
                );

                // OPS-5: a `settings_setup` bucket backs up config/lookups but NOT
                // the books (invoices/payments/marks live only in `full`). An
                // enabled backup that excludes the books is a false DR sense.
                $booksIncluded = $setting->bucket === 'full';

                $latest = CompanyBackupRun::query()
                    ->withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $setting->company_id)
                    ->orderByDesc('started_at')
                    ->first();

                // null = no off-site destination ran (can't judge); true/false =
                // every off-site result in the last run was ok / at least one failed.
                $offsitePushOk = null;
                if ($latest !== null && is_array($latest->destinations)) {
                    $offsiteResults = array_filter(
                        $latest->destinations,
                        fn ($r): bool => in_array($r['driver'] ?? null, $offsiteDrivers, true)
                    );
                    if ($offsiteResults !== []) {
                        $offsitePushOk = collect($offsiteResults)
                            ->every(fn ($r): bool => ($r['status'] ?? null) === 'ok');
                    }
                }

                $companyGap = ! $offsiteConfigured || $offsitePushOk === false;
                $gap = $gap || $companyGap;
                $booksGap = $booksGap || ! $booksIncluded;

                $companies[] = [
                    'slug' => $setting->company?->slug,
                    'name' => $setting->company?->name,
                    'frequency' => $setting->frequency,
                    'bucket' => $setting->bucket,
                    'books_included' => $booksIncluded,
                    'offsite_configured' => $offsiteConfigured,
                    'latest_run_at' => $latest?->finished_at?->toIso8601String() ?? $latest?->started_at?->toIso8601String(),
                    'latest_run_status' => $latest?->status,
                    'offsite_push_ok' => $offsitePushOk,
                    'warn' => $companyGap || ! $booksIncluded,
                ];
            }

            return [
                'offsite_gap' => $gap,
                'books_gap' => $booksGap,
                'enabled_count' => count($companies),
                'companies' => $companies,
            ];
        }, ['offsite_gap' => false, 'books_gap' => false, 'enabled_count' => 0, 'companies' => []]);
    }

    /**
     * Enumerate the whole-DB (spatie) backup artifacts on the local disk: the
     * directory, every `.zip`, its size + mtime, newest first, plus count + total.
     * Safe when the dir doesn't exist yet (count 0, files []). Read-only.
     *
     * @return array{dir: ?string, count: int, total_bytes: int, files: list<array{name: string, path: string, size_bytes: int, mtime: int, modified_at: string}>}
     */
    /**
     * The on-disk backup directory. Laravel 11's `local` disk roots at
     * storage/app/PRIVATE, so spatie writes to storage/app/private/{name} — NOT
     * storage/app/{name}. Resolve via the disk (with a legacy fallback) and share
     * it between localBackups() and disk() so the two never drift again (they did:
     * disk() hard-coded storage_path('app/'.name) and reported the backups dir as
     * "missing" while backups were landing fine under private/).
     */
    private function backupRoot(): string
    {
        $root = Storage::disk('local')->path(config('backup.backup.name'));
        if (! is_dir($root)) {
            $root = storage_path('app/'.config('backup.backup.name'));
        }

        return $root;
    }

    private function localBackups(): array
    {
        $root = $this->backupRoot();
        if (! is_dir($root)) {
            return ['dir' => null, 'count' => 0, 'total_bytes' => 0, 'files' => []];
        }

        $files = [];
        $total = 0;
        foreach (File::allFiles($root) as $file) {
            if (! str_ends_with($file->getFilename(), '.zip')) {
                continue;
            }
            $size = $file->getSize();
            $total += $size;
            $mtime = $file->getMTime();
            $files[] = [
                'name' => $file->getFilename(),
                'path' => $file->getPathname(),
                'size_bytes' => $size,
                'mtime' => $mtime,  // raw epoch — the sort key (offset-free, unlike the ISO string)
                'modified_at' => Carbon::createFromTimestamp($mtime)->toIso8601String(),
            ];
        }

        // Newest first, sorting on the raw epoch — a DST-straddling pair can't
        // reorder (the +02:00/+03:00 offset in the ISO string would be ignored
        // by a string compare). usort is stable, so same-mtime files keep order.
        usort($files, fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

        return [
            'dir' => $root,
            'count' => count($files),
            'total_bytes' => $total,
            'files' => $files,
        ];
    }

    /** @return array<string, mixed> */
    private function mail(): array
    {
        // SEC-5: a deliberate cross-tenant health sweep (the whole point is the
        // fleet-wide mail-failure count) — declare the intent by dropping the
        // CompanyScope, matching the other sweeps in this report.
        $sweep = fn () => InvoiceMailLog::query()->withoutGlobalScope(CompanyScope::class);

        return [
            'failed_24h' => $this->safeCount(fn () => $sweep()->where('status', 'failed')->where('failed_at', '>=', now()->subDay())->count()),
            'failed_7d' => $this->safeCount(fn () => $sweep()->where('status', 'failed')->where('failed_at', '>=', now()->subDays(7))->count()),
            'stuck_queued_or_sending' => $this->safeCount(fn () => $sweep()->whereIn('status', ['queued', 'sending'])->where('queued_at', '<', now()->subMinutes((int) config('ekdosi.schedule.mail_sweep_threshold_minutes', 15)))->count()),
            'latest_failure_at' => $this->safeValue(fn () => optional($sweep()->where('status', 'failed')->latest('failed_at')->first())->failed_at?->toIso8601String()),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function whmcs(): array
    {
        $enabled = $this->scheduleEnabled('whmcs_fetch_enabled');

        return $this->safeValue(fn () => Company::query()
            ->whereNotNull('whmcs_api_url')
            ->where('whmcs_api_url', '!=', '')
            ->orderBy('slug')
            ->get()
            ->map(function (Company $tenant) use ($enabled): array {
                $cached = $this->cacheGet(HealthKeys::whmcsFetch((int) $tenant->id), []);
                $checkedAt = $cached['checked_at'] ?? null;

                return [
                    'tenant' => $tenant->slug,
                    'status' => $cached['status'] ?? 'missing',
                    'last_success_at' => $cached['last_success_at'] ?? null,
                    'last_failure_at' => $cached['last_failure_at'] ?? null,
                    'last_exit_code' => $cached['exit_code'] ?? null,
                    'checked_at' => $checkedAt,
                    'enabled' => $enabled,
                    'stale' => $this->sweepIsStale($checkedAt, $enabled),
                    'pending_review' => $this->safeCount(fn () => PendingWhmcsInvoice::query()->where('company_id', $tenant->id)->where('status', 'pending_review')->count()),
                ];
            })
            ->all(), []);
    }

    /** @return array<int, array<string, mixed>> */
    private function mydata(): array
    {
        $enabled = $this->scheduleEnabled('mydata_reconcile_enabled');

        return $this->safeValue(fn () => Company::myDataReadable()
            ->sortBy('slug')
            ->values()
            ->map(function (Company $tenant) use ($enabled): array {
                $cached = $this->cacheGet(HealthKeys::myDataReconcile((int) $tenant->id), []);
                $latestMark = $this->safeValue(fn () => MyDataMark::query()->where('company_id', $tenant->id)->latest('created_at')->first());
                $checkedAt = $cached['checked_at'] ?? null;

                return [
                    'tenant' => $tenant->slug,
                    'status' => $cached['status'] ?? 'missing',
                    'discrepancies' => $cached['discrepancies'] ?? null,
                    'last_success_at' => $cached['last_success_at'] ?? null,
                    'last_failure_at' => $cached['last_failure_at'] ?? null,
                    'last_aade_call_at' => $cached['last_aade_call_at'] ?? null,
                    'checked_at' => $checkedAt,
                    'enabled' => $enabled,
                    'stale' => $this->sweepIsStale($checkedAt, $enabled),
                    'latest_mydata_mark_at' => $latestMark?->created_at?->toIso8601String(),
                ];
            })
            ->all(), []);
    }

    /**
     * OPS-13: mirror routes/console.php's run-time enable check (DB override wins,
     * config/env is the default) so staleness is only judged for tasks that are
     * actually supposed to be running — an opt-out task's silent key is expected.
     */
    private function scheduleEnabled(string $key): bool
    {
        return app(SystemSettings::class)
            ->bool("schedule.{$key}", (bool) config("ekdosi.schedule.{$key}"));
    }

    /**
     * True when an ENABLED sweep last recorded longer ago than the stale window.
     * A never-recorded key (checked_at null) stays «missing», not «stale» — that's
     * a fresh cache / brand-new tenant, not a run that died mid-life.
     */
    private function sweepIsStale(?string $checkedAt, bool $enabled): bool
    {
        if (! $enabled || $checkedAt === null) {
            return false;
        }

        try {
            return Carbon::parse($checkedAt)->lt(now()->subHours(self::TENANT_SWEEP_STALE_HOURS));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * SEC-3: the webhook HMAC is tenant-scoped by the SHARED secret alone (the
     * body/canonical don't bind the slug), so two tenants must NEVER share a
     * `whmcs_webhook_secret` — otherwise either could forge the other's webhook.
     * Surface any collision here so ops can rotate before it's ever exploitable.
     * We report only the affected tenant slugs, never the secret itself.
     *
     * @return array<string, mixed>
     */
    private function security(): array
    {
        return $this->safeValue(function (): array {
            $groups = [];
            Company::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->whereNotNull('whmcs_webhook_secret')
                ->where('whmcs_webhook_secret', '!=', '')
                ->orderBy('slug')
                ->get(['id', 'slug', 'whmcs_webhook_secret'])
                ->each(function (Company $tenant) use (&$groups): void {
                    // Hash the decrypted secret so equal secrets collide WITHOUT
                    // the plaintext ever entering the report or a bucket key.
                    $secret = (string) $tenant->whmcs_webhook_secret;
                    if ($secret === '') {
                        return;
                    }
                    $groups[hash('sha256', $secret)][] = $tenant->slug;
                });

            $shared = array_values(array_filter($groups, fn (array $slugs): bool => count($slugs) > 1));

            return [
                'shared_webhook_secret' => $shared !== [],
                'shared_webhook_secret_tenants' => $shared,
            ];
        }, ['shared_webhook_secret' => false, 'shared_webhook_secret_tenants' => []]);
    }

    /** @return array<string, array<string, mixed>> */
    private function disk(): array
    {
        return [
            'storage' => $this->diskUsage(storage_path()),
            'logs' => $this->diskUsage(storage_path('logs')),
            'temp_uploads' => $this->diskUsage(storage_path('app/livewire-tmp')),
            'backups' => $this->diskUsage($this->backupRoot()),
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

    /** Failed queue jobs in the last 24h (drives severity; total is display-only). */
    private function recentFailedJobs(): int
    {
        try {
            return (int) DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subDay())
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }
}
