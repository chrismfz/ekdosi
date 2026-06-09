<?php

namespace App\Console\Commands;

use App\Models\CompanyBackupRun;
use App\Models\CompanyBackupSetting;
use App\Models\User;
use App\Notifications\ScheduledBackupFailed;
use App\Services\Backup\CompanyBackupRunner;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Phase 4 scheduler entrypoint: run each enabled company's backup whose cadence
 * is due. Meant to fire hourly (cron); `isDue()` lets exactly one run land per
 * day/week/month at/after the configured time. Tenant-safe (explicit
 * CompanyContext::actAs per company). Inert until the OS cron + a queue worker
 * run AND `EKDOSI_SCHEDULE_COMPANY_BACKUPS=true`.
 */
class RunScheduledCompanyBackups extends Command
{
    protected $signature = 'company:run-scheduled-backups
        {--tenant= : Limit to one company slug}
        {--force : Run now regardless of cadence / run-at-time}';

    protected $description = 'Run due per-company backups (Phase 4).';

    public function handle(CompanyBackupRunner $runner): int
    {
        $settings = CompanyBackupSetting::query()
            ->where('enabled', true)
            ->where('frequency', '!=', 'off')
            ->when($this->option('tenant'), fn ($q, $slug) => $q->whereHas('company', fn ($c) => $c->where('slug', $slug)))
            ->with('company')
            ->get();

        $now = now();
        $ran = 0;

        foreach ($settings as $s) {
            if ($s->company === null) {
                continue;
            }
            // Last ATTEMPT (any status) gates the cadence — see isDue() docblock.
            $lastRunAt = CompanyBackupRun::query()
                ->where('company_id', $s->company_id)
                ->latest('started_at')
                ->value('started_at');
            if (! $this->option('force') && ! $s->isDue($lastRunAt, $now)) {
                continue;
            }

            app(CompanyContext::class)->actAs($s->company, function () use ($runner, $s, &$ran) {
                $run = $runner->run($s->company, $s, 'scheduled');
                $ran++;
                $this->line("• {$s->company->slug}: {$run->status}".($run->message ? " — {$run->message}" : ''));

                if (in_array($run->status, ['failed', 'partial'], true)) {
                    $this->alertFailure($run);
                }
            });
        }

        $this->info("Έτρεξαν {$ran} αντίγραφα.");

        return self::SUCCESS;
    }

    /**
     * A scheduled backup ended failed/partial. ALWAYS log it; additionally email
     * the ops recipients (config address, else super_admins) unless alerting is
     * off. Best-effort — a mail problem must never break the scheduler loop.
     */
    private function alertFailure(CompanyBackupRun $run): void
    {
        Log::error('Scheduled company backup '.$run->status, [
            'company_id' => $run->company_id,
            'run_id' => $run->getKey(),
            'destinations' => $run->destinations,
        ]);

        if (! config('ekdosi.backup.alert_on_failure', true)) {
            return;
        }

        try {
            $recipients = $this->alertRecipients();
            if ($recipients !== []) {
                Notification::route('mail', $recipients)->notify(new ScheduledBackupFailed($run));
            }
        } catch (Throwable $e) {
            Log::warning('Backup failure alert could not be sent: '.$e->getMessage());
        }
    }

    /**
     * Ops recipients: the configured `backup.alert_email` (comma-separated), or
     * — when none is set — every super_admin user with an email. De-duplicated.
     *
     * @return array<int,string>
     */
    private function alertRecipients(): array
    {
        $configured = array_filter(array_map('trim', explode(',', (string) config('ekdosi.backup.alert_email'))));
        if ($configured !== []) {
            return array_values(array_unique($configured));
        }

        return User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))
            ->whereNotNull('email')
            ->pluck('email')
            ->unique()
            ->values()
            ->all();
    }
}
