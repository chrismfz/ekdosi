<?php

namespace App\Console\Commands;

use App\Models\CompanyBackupRun;
use App\Models\CompanyBackupSetting;
use App\Services\Backup\CompanyBackupRunner;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Console\Command;

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

            CompanyContext::actAs($s->company, function () use ($runner, $s, &$ran) {
                $run = $runner->run($s->company, $s, 'scheduled');
                $ran++;
                $this->line("• {$s->company->slug}: {$run->status}".($run->message ? " — {$run->message}" : ''));
            });
        }

        $this->info("Έτρεξαν {$ran} αντίγραφα.");

        return self::SUCCESS;
    }
}
