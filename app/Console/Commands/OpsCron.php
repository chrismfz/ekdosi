<?php

namespace App\Console\Commands;

use App\Support\OperatorHealth\OperatorHealthReport;
use Illuminate\Console\Command;

/**
 * OPS-001 / OPS-003: the host-provisioning helper. `ops:health` can now tell
 * «cron down» from «worker down» (the scheduler heartbeat), but on a fresh box the
 * operator still has to WIRE the OS cron + a queue worker — and «what exactly do I
 * paste?» is the friction. This command prints the exact copy-paste lines computed
 * from THIS host's real PHP binary + application path, for both a VPS (systemd) and
 * a shared-hosting/cPanel/DirectAdmin box (cron-driven worker, no systemd), plus the
 * live state so the operator sees whether cron/worker are already alive.
 *
 * Read-only: it writes nothing and provisions nothing — it just tells you the lines
 * to add. See INSTALL.md (VPS) and docs/shared-hosting-deploy.md (cPanel/DirectAdmin).
 *
 *   php artisan ops:cron            # human-readable recipe + current state
 *   php artisan ops:cron --json     # machine-readable (paths + commands)
 */
class OpsCron extends Command
{
    protected $signature = 'ops:cron {--json : Emit machine-readable JSON instead of the operator recipe}';

    protected $description = 'Print the exact OS cron line + queue-worker recipe (VPS systemd and shared-hosting/cPanel) for THIS host, with the live cron/worker state.';

    public function handle(OperatorHealthReport $report): int
    {
        // PHP_BINARY under `artisan` is the CLI php — the one the crontab must call.
        // On shared hosting it may differ from the web (fpm) php; we surface it so the
        // operator can swap in the account's CLI php path if theirs is elsewhere.
        $php = PHP_BINARY ?: 'php';
        $base = base_path();
        $artisan = base_path('artisan');

        // Single-quote every interpolated path so a copy-paste survives an install
        // dir (or php binary path) that contains a space — the lines are meant to be
        // pasted verbatim, so a broken `cd /home/user/My Site/…` must not happen.
        $cronLine = "* * * * * cd '{$base}' && '{$php}' artisan schedule:run >> /dev/null 2>&1";
        $workerCommand = "'{$php}' artisan queue:work --queue=default --tries=3 --max-time=3600";
        // Shared hosting has no supervisor: a once-a-minute cron that drains the
        // queue and exits keeps jobs flowing (less responsive than a resident worker,
        // fine for mail/imports/backups). --max-time=55 keeps ONE run UNDER the 60s
        // tick so two never overlap even without flock; flock is the belt to that
        // braces where the host provides it.
        $sharedWorkerCron = "* * * * * flock -n /tmp/ekdosi-queue.lock '{$php}' '{$artisan}' queue:work --stop-when-empty --queue=default --tries=3 --max-time=55 >> '{$base}/storage/logs/queue.log' 2>&1";

        $cron = $report->cron();
        $queue = $report->queue();

        if ($this->option('json')) {
            $this->line(json_encode([
                'php_binary' => $php,
                'app_path' => $base,
                'artisan' => $artisan,
                'cron_line' => $cronLine,
                'worker_command' => $workerCommand,
                'shared_hosting_worker_cron' => $sharedWorkerCron,
                'state' => [
                    'cron' => $cron,
                    'queue' => [
                        'worker_heartbeat_at' => $queue['worker_heartbeat_at'] ?? null,
                        'worker_heartbeat_status' => $queue['worker_heartbeat_status'] ?? 'missing',
                    ],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('Ρύθμιση χρονοπρογραμματιστή + queue worker για αυτόν τον host');
        $this->newLine();
        $this->components->twoColumnDetail('PHP (CLI)', $php);
        $this->components->twoColumnDetail('Φάκελος εφαρμογής', $base);
        $this->newLine();

        // Live state first — so the operator knows if anything is even missing.
        $this->line('<fg=cyan>Τρέχουσα κατάσταση</>');
        $this->components->twoColumnDetail('Cron (scheduler) tick', $cron['last_tick_at'] ?? '—');
        $this->components->twoColumnDetail('Cron status', $this->badge($cron['status'] ?? 'missing'));
        $this->components->twoColumnDetail('Queue worker heartbeat', $queue['worker_heartbeat_at'] ?? '—');
        $this->components->twoColumnDetail('Queue status', $this->badge($queue['worker_heartbeat_status'] ?? 'missing'));
        $this->newLine();

        $this->line('<fg=cyan>1) OS cron — απαραίτητο σε ΚΑΘΕ host</> (κάθε λεπτό τρέχει τον χρονοπρογραμματιστή):');
        $this->line('   Βάλε το με «crontab -e»:');
        $this->newLine();
        $this->line("   <fg=green>{$cronLine}</>");
        $this->newLine();

        $this->line('<fg=cyan>2α) Queue worker — VPS με systemd</> (συνεχώς ζωντανός· βλ. INSTALL.md → ekdosi-queue.service):');
        $this->newLine();
        $this->line("   <fg=green>{$workerCommand}</>");
        $this->newLine();

        $this->line('<fg=cyan>2β) Queue worker — shared hosting / cPanel / DirectAdmin</> (χωρίς systemd):');
        $this->line('   Πρόσθεσε ΔΕΥΤΕΡΗ γραμμή cron που αδειάζει την ουρά κάθε λεπτό και βγαίνει:');
        $this->newLine();
        $this->line("   <fg=green>{$sharedWorkerCron}</>");
        $this->newLine();
        $this->line('   • Αν λείπει το «flock», αφαίρεσέ το — το --max-time=55 (< 60s) κρατά τη μία εκτέλεση κάτω από το επόμενο tick, ώστε να μην επικαλύπτονται δύο.');
        $this->line('   • Αν το CLI php του account είναι αλλού (π.χ. /usr/local/bin/ea-php84), βάλε ΕΚΕΙΝΟ το path στη θέση του παραπάνω.');
        $this->line('   • Log ουράς: '.$base.'/storage/logs/queue.log');
        $this->newLine();

        $this->line('Μετά το στήσιμο, επιβεβαίωσε με «php artisan ops:health» (το cron/queue θα δείξουν «ok» σε 1–5 λεπτά).');

        return self::SUCCESS;
    }

    private function badge(string $status): string
    {
        return match ($status) {
            'ok' => '<fg=green>ok</>',
            'stale' => '<fg=yellow>stale (παλιό)</>',
            default => '<fg=red>missing (δεν τρέχει)</>',
        };
    }
}
