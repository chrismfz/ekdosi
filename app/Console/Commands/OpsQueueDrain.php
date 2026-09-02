<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;

/**
 * OPS-6b — «άδειασε την ουρά» ΧΩΡΙΣ root: the portable queue drain for hosts
 * where `systemctl stop` is not available or not permitted (cPanel / Plesk /
 * DirectAdmin / shared hosting, a cron-driven `queue:work`, a systemd timer,
 * or simply a deploy user without sudo).
 *
 *   php artisan ops:queue-drain [--timeout=60] [--quiet-ok]
 *
 * How it is safe without stopping anything:
 *   1. `queue:restart` — every worker finishes its CURRENT job and exits
 *      (Laravel's cooperative restart signal, no signals/permissions needed).
 *   2. We then WAIT until no job is reserved (= nothing is being processed),
 *      which is the empirical proof the caller actually wants.
 *   3. `update.sh` has already put the app in maintenance mode, and a worker
 *      started WITHOUT `--force` refuses to pick up new work while the app is
 *      down — so a supervisor that restarts the worker (systemd
 *      `Restart=always`, cron) brings up a worker that SLEEPS until the deploy
 *      is over. That is what makes this enough on the labrat VM too.
 *
 * Exit 0 = the queue is idle (or there is nothing to drain), 1 = a job was
 * still running when the timeout expired (the caller must not migrate).
 *
 * ⚠ A worker running with `--force` ignores maintenance mode and WILL keep
 * consuming during the deploy — never add `--force` to the unit/cron line.
 */
class OpsQueueDrain extends Command
{
    protected $signature = 'ops:queue-drain
        {--timeout=60 : Δευτερόλεπτα αναμονής μέχρι να αδειάσει η ουρά}
        {--quiet-ok : Χωρίς έξοδο όταν δεν υπάρχει τίποτα να αδειάσει}';

    protected $description = 'Αδειάζει την ουρά χωρίς root (queue:restart + αναμονή) — για hosts χωρίς systemctl/sudo.';

    /** How often we look again while waiting. */
    private const POLL_SECONDS = 2;

    /** Best-effort grace for a driver whose in-flight jobs we cannot inspect. */
    private const BLIND_GRACE_SECONDS = 15;

    public function handle(): int
    {
        $timeout = max(0, (int) $this->option('timeout'));
        $connection = (string) config('queue.default');
        $driver = (string) config("queue.connections.{$connection}.driver", $connection);

        if (in_array($driver, ['sync', 'null'], true)) {
            $this->quietLine("Ουρά «{$driver}» — δεν τρέχει worker, δεν χρειάζεται drain.");

            return self::SUCCESS;
        }

        // Tell every worker: finish the job you hold, then exit.
        $this->call('queue:restart');

        if ($driver !== 'database') {
            // Redis/SQS/…: we cannot count in-flight jobs portably here.
            $grace = min($timeout, self::BLIND_GRACE_SECONDS);
            $this->warn("Ουρά «{$driver}»: δεν μπορώ να μετρήσω τα jobs που τρέχουν — αναμονή {$grace}s (best-effort).");
            Sleep::for($grace)->seconds();

            return self::SUCCESS;
        }

        $table = (string) config("queue.connections.{$connection}.table", 'jobs');
        if (! Schema::hasTable($table)) {
            $this->quietLine("Ο πίνακας «{$table}» δεν υπάρχει — καμία ουρά βάσης να αδειάσει.");

            return self::SUCCESS;
        }

        $inFlight = fn (): int => (int) DB::table($table)->whereNotNull('reserved_at')->count();

        $running = $inFlight();
        if ($running === 0) {
            $this->quietLine('Η ουρά είναι ήδη ήσυχη (κανένα job σε εξέλιξη).');

            return self::SUCCESS;
        }

        $this->line("Αναμονή να τελειώσουν {$running} job(s) σε εξέλιξη (έως {$timeout}s)…");

        $waited = 0;
        while ($waited < $timeout) {
            Sleep::for(self::POLL_SECONDS)->seconds();
            $waited += self::POLL_SECONDS;

            $running = $inFlight();
            if ($running === 0) {
                $this->info("✓ Η ουρά άδειασε μετά από {$waited}s.");

                return self::SUCCESS;
            }
        }

        $this->error("Μετά από {$timeout}s τρέχουν ακόμη {$running} job(s) — ΜΗΝ κάνεις migrate.");
        $this->line('  Δες τα: SELECT id, queue, attempts, reserved_at FROM '.$table.' WHERE reserved_at IS NOT NULL;');
        $this->line('  Περίμενε να τελειώσουν (π.χ. μεγάλο import) ή ανέβασε το --timeout.');

        return self::FAILURE;
    }

    private function quietLine(string $message): void
    {
        if (! $this->option('quiet-ok')) {
            $this->line($message);
        }
    }
}
