<?php

namespace App\Console\Commands;

use App\Support\OperatorHealth\OperatorHealthReport;
use Illuminate\Console\Command;

class OperatorHealth extends Command
{
    protected $signature = 'ops:health {--json : Emit machine-readable JSON instead of operator tables}';

    protected $description = 'Show operator health for queues, scheduler, backups, mail, WHMCS, myDATA, and disk usage.';

    public function handle(OperatorHealthReport $report): int
    {
        $data = $report->build();

        if ($this->option('json')) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Operator health @ '.$data['generated_at']);

        $this->newLine();
        $this->components->twoColumnDetail('Queue heartbeat', $data['queue']['worker_heartbeat_at'] ?? 'missing');
        $this->components->twoColumnDetail('Queue heartbeat age', $data['queue']['worker_heartbeat_age_minutes'] === null ? 'n/a' : $data['queue']['worker_heartbeat_age_minutes'].' min');
        $this->components->twoColumnDetail('Queue heartbeat status', $data['queue']['worker_heartbeat_status']);
        $this->components->twoColumnDetail('Failed jobs', (string) ($data['queue']['failed_jobs'] ?? 'unknown'));

        $this->newLine();
        $this->info('Scheduler last runs');
        $this->table(['Task', 'Last run', 'Status', 'Exit'], collect($data['scheduler'])->map(fn ($row) => [
            $row['label'], $row['last_run_at'] ?? 'missing', $row['status'], $row['exit_code'] ?? 'n/a',
        ])->all());

        $this->newLine();
        $this->info('Backups');
        $this->components->twoColumnDetail('Latest backup', $data['backup']['latest_backup_path'] ?? 'missing');
        $this->components->twoColumnDetail('Latest backup age', $data['backup']['latest_backup_age_hours'] === null ? 'n/a' : $data['backup']['latest_backup_age_hours'].' h');
        $this->components->twoColumnDetail('Latest backup size', $this->bytes($data['backup']['latest_backup_size_bytes']));
        $this->components->twoColumnDetail('Monitor status', $data['backup']['monitor']['status'] ?? 'missing');
        $this->components->twoColumnDetail('Monitor checked', $data['backup']['monitor']['checked_at'] ?? 'missing');

        // Off-site verification: do enabled per-tenant backups actually leave the
        // VM, and did the last off-site push land?
        $cb = $data['backup']['companies'] ?? null;
        if (is_array($cb)) {
            $this->components->twoColumnDetail(
                'Off-site backups',
                $cb['enabled_count'] === 0
                    ? 'no tenant has backups enabled'
                    : ($cb['offsite_gap'] ? '⚠ GAP — see per-tenant below' : 'ok ('.$cb['enabled_count'].' tenant(s))')
            );
            foreach ($cb['companies'] as $row) {
                $parts = [$row['offsite_configured'] ? 'off-site set' : '⚠ LOCAL ONLY'];
                $parts[] = 'last: '.($row['latest_run_status'] ?? 'never').($row['latest_run_at'] ? ' '.$row['latest_run_at'] : '');
                if ($row['offsite_push_ok'] === false) {
                    $parts[] = '⚠ off-site push FAILED';
                }
                $this->components->twoColumnDetail('  '.($row['slug'] ?? '?'), implode(' · ', $parts));
            }
        }

        $this->newLine();
        $this->info('Mail');
        $this->components->twoColumnDetail('Failed invoice mails (24h)', (string) $data['mail']['failed_24h']);
        $this->components->twoColumnDetail('Failed invoice mails (7d)', (string) $data['mail']['failed_7d']);
        $this->components->twoColumnDetail('Stuck queued/sending', (string) $data['mail']['stuck_queued_or_sending']);
        $this->components->twoColumnDetail('Latest failure', $data['mail']['latest_failure_at'] ?? 'none');

        $this->newLine();
        $this->info('WHMCS tenants');
        $this->table(['Tenant', 'Status', 'Last success', 'Last failure', 'Exit', 'Pending review'], collect($data['whmcs'])->map(fn ($row) => [
            $row['tenant'], $row['status'], $row['last_success_at'] ?? 'missing', $row['last_failure_at'] ?? 'none', $row['last_exit_code'] ?? 'n/a', $row['pending_review'],
        ])->all());

        $this->newLine();
        $this->info('myDATA tenants');
        $this->table(['Tenant', 'Status', 'Discrepancies', 'Last success', 'Last AADE call', 'Latest MARK'], collect($data['mydata'])->map(fn ($row) => [
            $row['tenant'], $row['status'], $row['discrepancies'] ?? 'n/a', $row['last_success_at'] ?? 'missing', $row['last_aade_call_at'] ?? 'missing', $row['latest_mydata_mark_at'] ?? 'none',
        ])->all());

        $this->newLine();
        $this->info('Disk usage');
        $this->table(['Area', 'Path', 'Exists', 'Used', 'Free', 'Total'], collect($data['disk'])->map(fn ($row, $area) => [
            $area, $row['path'], $row['exists'] ? 'yes' : 'no', $this->bytes($row['used_bytes']), $this->bytes($row['free_bytes']), $this->bytes($row['total_bytes']),
        ])->all());

        return self::SUCCESS;
    }

    private function bytes(mixed $bytes): string
    {
        if ($bytes === null) {
            return 'n/a';
        }

        $bytes = (float) $bytes;
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($bytes < 1024 || $unit === 'TB') {
                return round($bytes, 2).' '.$unit;
            }
            $bytes /= 1024;
        }

        return round($bytes, 2).' TB';
    }
}
