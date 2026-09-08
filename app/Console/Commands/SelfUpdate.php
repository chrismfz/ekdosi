<?php

namespace App\Console\Commands;

use App\Models\UpdateRun;
use App\Support\BuildInfo;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * `ekdosi:self-update` — apply a pending in-app update (Phase 2).
 *
 * This runs OUT-OF-BAND (the cron scheduler calls it with --pending; see
 * routes/console.php), never inside a web request: an update restarts the app,
 * so it must not run on the queue worker it restarts nor block an HTTP request.
 * The «Ενημερώσεις» page just creates a `queued` UpdateRun and the scheduler
 * picks it up here on the next tick.
 *
 * NO PRIVILEGE REQUIRED (shared-hosting-first): everything runs as the app's own
 * account user, which already owns the tree. The command is a THIN ORCHESTRATOR
 * that shells out to git/composer/fresh `php artisan …` subprocesses (each a new
 * PHP process that loads the new code) — the same ordering as deploy/update.sh,
 * so the parent never autoloads swapped classes mid-run. Restarts use no root:
 * queue:restart is a cache flag, opcache is a best-effort self-hit.
 *
 * Two strategies (config: ekdosi.updates.strategy):
 *   - 'php'    (default, portable): the PHP orchestration below.
 *   - 'script' (VPS opt-in): wrap deploy/update.sh, which owns its own safety.
 *
 * A failed apply deliberately leaves the app in maintenance mode once it has
 * gone down (same choice as deploy/update.sh) — recover with Επαναφορά (Phase B)
 * or `php artisan up`. The full log lands in storage/logs/updates/<id>.log.
 *
 * See docs/versioning-and-updates.md.
 */
class SelfUpdate extends Command
{
    protected $signature = 'ekdosi:self-update
        {--pending : Process the oldest queued update run (the scheduler entry point)}
        {--run= : Process a specific UpdateRun id}';

    protected $description = 'Apply a pending in-app update (git checkout + composer + migrate + caches), out-of-band.';

    /** In-memory mirror of the run output; flushed to the DB row at each step. */
    private string $buffer = '';

    /** Was the app put into maintenance yet? (drives on-failure recovery.) */
    private bool $wentDown = false;

    public function handle(): int
    {
        $run = $this->resolveRun();
        if ($run === null) {
            $this->info('Καμία εκκρεμής ενημέρωση.');

            return self::SUCCESS;
        }

        if ($run->status !== UpdateRun::STATUS_QUEUED) {
            $this->warn("Η ενημέρωση #{$run->id} δεν είναι σε κατάσταση «queued» (={$run->status}) — παράλειψη.");

            return self::SUCCESS;
        }

        // UPD-001…015 (triage 2026-09-02): applying code from inside the app is
        // OFF by default. FAIL the row rather than skipping it — the scheduler runs
        // this every minute while anything is queued, so a silent skip would spin
        // forever and the operator would never learn why nothing happened.
        if (! UpdateRun::inAppApplyEnabled()) {
            // The host command differs by KIND. Telling an operator to run
            // deploy/update.sh for a queued ROLLBACK would check out the old code
            // WITHOUT restoring the pre-update DB snapshot — a worse state than the
            // one they were trying to leave.
            $command = $run->isRollback()
                ? 'deploy/rollback.sh'
                : 'deploy/update.sh '.($run->to_ref ?: '<tag>');

            $message = 'Η εφαρμογή ενημερώσεων μέσα από το panel είναι απενεργοποιημένη '
                .'(ekdosi.updates.allow_in_app_apply = false). Κάνε την ενέργεια από τον '
                .'server: '.$command;

            $run->update([
                'status' => UpdateRun::STATUS_FAILED,
                'finished_at' => now(),
                'phase' => 'disabled',
                'error_message' => $message,
                'output' => trim((string) $run->output."\n".$message)."\n",
            ]);

            $this->warn($message);

            // SUCCESS, not FAILURE: the RUN failed (recorded on the row above), but
            // the scheduled TASK did exactly what it should. $trackSchedule's
            // onFailure would otherwise stamp `self_update => failed` in the health
            // record, and since the task never runs again once nothing is queued,
            // ops:health would report «Απέτυχε προγραμματισμένη εργασία: self-update»
            // and exit 1 forever over a correct, intentional state.
            return self::SUCCESS;
        }

        $this->buffer = (string) $run->output;
        $run->update([
            'status' => UpdateRun::STATUS_RUNNING,
            'started_at' => now(),
            'phase' => 'preflight',
        ]);
        $this->append($run, sprintf(
            "== ekdosi:self-update #%d ==\nstrategy=%s  target=%s  from=%s\n",
            $run->id,
            $run->strategy,
            (string) ($run->to_ref ?? '?'),
            (string) ($run->from_ref ?? '?'),
        ));
        $this->persist($run);

        try {
            if ($run->isRollback()) {
                $this->runRollback($run);
            } elseif ($run->strategy === UpdateRun::STRATEGY_SCRIPT) {
                $this->runScript($run);
            } else {
                $this->runPhp($run);
            }
        } catch (Throwable $e) {
            $this->failRun($run, (string) $run->phase, $e->getMessage());

            return self::FAILURE;
        }

        $run->update([
            'status' => UpdateRun::STATUS_SUCCEEDED,
            'phase' => 'done',
            'finished_at' => now(),
        ]);
        $this->append($run, "\n✓ Η ενημέρωση ολοκληρώθηκε.\n");
        $this->persist($run);
        $this->info("✓ Η ενημέρωση #{$run->id} ολοκληρώθηκε.");

        return self::SUCCESS;
    }

    private function resolveRun(): ?UpdateRun
    {
        if ($id = $this->option('run')) {
            return UpdateRun::find((int) $id);
        }

        // Oldest queued — one at a time; a second queued row waits for the next tick.
        return UpdateRun::query()->queued()->orderBy('id')->first();
    }

    // ─────────────────────────── the PHP strategy ───────────────────────────

    private function runPhp(UpdateRun $run): void
    {
        $php = PHP_BINARY;
        $artisan = base_path('artisan');
        $composer = (string) (getenv('COMPOSER') ?: 'composer');

        // ── preflight (app still UP) ────────────────────────────────────────
        if (! function_exists('proc_open')) {
            throw new \RuntimeException('Η PHP συνάρτηση proc_open είναι απενεργοποιημένη — δεν είναι δυνατή η in-app ενημέρωση σε αυτόν τον host.');
        }
        if (! is_dir(base_path('.git'))) {
            throw new \RuntimeException('Δεν βρέθηκε φάκελος .git — η in-app ενημέρωση («php» strategy) απαιτεί deployment μέσω git checkout.');
        }
        // TRACKED changes only (`--untracked-files=no`), same rule as
        // deploy/update.sh: a bare `--porcelain` counts UNTRACKED files, and the
        // `shield:generate` step below writes one for any resource shipping
        // without a policy — which then refused every later update. Here it is
        // worse than on the shell script: the panel operator has no shell to
        // clear it with, so this must never be the stop condition.
        $dirty = trim($this->capture(['git', 'status', '--porcelain', '--untracked-files=no'], base_path()));
        if ($dirty !== '') {
            throw new \RuntimeException("Το working tree δεν είναι καθαρό — ματαίωση:\n".$dirty);
        }

        // ── fetch — still UP, so a network failure costs no downtime (same
        //    order as deploy/update.sh: fetch, then resolve, then go down) ────
        $target = (string) $run->to_ref;
        if ($target === '') {
            throw new \RuntimeException('Δεν έχει οριστεί target ref (to_ref) στην ενημέρωση.');
        }
        $this->step($run, 'fetch', 'git fetch', function () use ($run) {
            $this->gitFetch($run);
        });

        // Copy aside anything the checkout would replace — needs the ref FETCHED
        // (to know what it ships) and must run BEFORE maintenance, so its
        // abort-on-failed-backup never strands the app down with no shell.
        $this->step($run, 'protect', 'Αντίγραφα untracked αρχείων', function () use ($run, $target) {
            $this->protectUntracked($run, $target);
        });

        // ── maintenance ON — from here on, a failure leaves the app DOWN ─────
        $this->step($run, 'maintenance', 'Maintenance mode ON', function () use ($run, $php, $artisan) {
            $this->exec($run, [$php, $artisan, 'down', '--retry=15']);
            $this->wentDown = true;
        });

        // ── snapshot (the rollback point) ───────────────────────────────────
        $snapshot = storage_path('app/db-snapshots/update-'.$run->id.'-'.now()->format('Ymd-His').'.sql.gz');
        $this->step($run, 'snapshot', 'DB snapshot', function () use ($run, $php, $artisan, $snapshot) {
            $this->exec($run, [$php, $artisan, 'ekdosi:db-snapshot', '--out='.$snapshot, '--keep=10']);
            $run->update(['snapshot_file' => $snapshot]);
        });

        // ── checkout the target ref ─────────────────────────────────────────
        $this->step($run, 'checkout', 'git checkout '.$target, function () use ($run, $target) {
            $this->exec($run, ['git', 'checkout', '--force', $target], base_path());
            $this->writeBuildStamp($run, $target);
        });

        // ── composer (from the committed lock — NEVER `composer update`) ─────
        $this->step($run, 'composer', 'composer install', function () use ($run, $composer) {
            $this->exec(
                $run,
                [$composer, 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--prefer-dist'],
                base_path(),
                ['COMPOSER_MEMORY_LIMIT' => '-1'],
                3600,
            );
        });

        // ── migrate ─────────────────────────────────────────────────────────
        $this->step($run, 'migrate', 'php artisan migrate', function () use ($run, $php, $artisan) {
            $this->exec($run, [$php, $artisan, 'migrate', '--force']);
        });

        // ── caches + permissions ────────────────────────────────────────────
        $this->step($run, 'optimize', 'php artisan optimize', function () use ($run, $php, $artisan) {
            $this->exec($run, [$php, $artisan, 'optimize']);
            // Bust the cached update-check result so the panel re-checks post-deploy.
            $this->exec($run, [$php, $artisan, 'cache:forget', 'ekdosi.updates.status'], base_path(), null, 60, allowFailure: true);
        });
        $this->step($run, 'shield', 'shield:generate + sync', function () use ($run, $php, $artisan) {
            $this->exec($run, [$php, $artisan, 'shield:generate', '--all', '--panel=admin', '--ignore-existing-policies', '--no-interaction'], base_path(), null, 300, allowFailure: true);
            $this->exec($run, [$php, $artisan, 'shield:sync-super-admin'], base_path(), null, 300, allowFailure: true);
        });

        // ── restarts (no root) ──────────────────────────────────────────────
        $this->step($run, 'queue_restart', 'php artisan queue:restart', function () use ($run, $php, $artisan) {
            $this->exec($run, [$php, $artisan, 'queue:restart']);
        });
        $this->step($run, 'opcache', 'opcache flush (best-effort)', function () use ($run) {
            $this->flushOpcache($run);
        });

        // ── maintenance OFF ─────────────────────────────────────────────────
        $this->step($run, 'maintenance_off', 'Maintenance mode OFF', function () use ($run, $php, $artisan) {
            $this->exec($run, [$php, $artisan, 'up']);
            $this->wentDown = false;
        });

        // ── post-deploy health (advisory — never fails the run) ─────────────
        $this->step($run, 'health', 'php artisan ops:health', function () use ($run, $php, $artisan) {
            $this->exec($run, [$php, $artisan, 'ops:health'], base_path(), null, 120, allowFailure: true);
        });

        // Refresh the in-process BuildInfo memo so an immediate read is correct.
        BuildInfo::flush();
    }

    // ────────────────────────── the script strategy ─────────────────────────

    private function runScript(UpdateRun $run): void
    {
        $target = (string) $run->to_ref;
        if ($target === '') {
            throw new \RuntimeException('Δεν έχει οριστεί target ref (to_ref) στην ενημέρωση.');
        }
        $script = base_path('deploy/update.sh');
        if (! is_file($script)) {
            throw new \RuntimeException('Δεν βρέθηκε το deploy/update.sh — άλλαξε strategy σε «php».');
        }

        // The script owns its own maintenance/snapshot/restart choreography.
        $this->wentDown = true;
        $this->step($run, 'script', 'deploy/update.sh '.$target, function () use ($run, $script, $target) {
            $this->exec($run, ['sh', $script, $target], base_path(), null, 3600);
        });
        $this->wentDown = false;

        BuildInfo::flush();
    }

    // ─────────────────────────────── rollback ───────────────────────────────

    /**
     * Revert to a previous build: check out the old commit, reinstall its
     * dependencies, and RESTORE the pre-update DB snapshot (destructive). A fresh
     * safety snapshot of the current state is taken first, so a rollback is itself
     * undoable.
     *
     * The DB restore rewinds the WHOLE database — including this `update_runs`
     * audit table — back to the pre-update state, so it runs LATE (after the code
     * is in place) and `reconcileAudit()` re-stamps the audit rows afterwards.
     */
    private function runRollback(UpdateRun $run): void
    {
        $php = PHP_BINARY;
        $artisan = base_path('artisan');
        $composer = (string) (getenv('COMPOSER') ?: 'composer');

        $target = (string) $run->to_ref;
        $snapshot = (string) $run->restore_snapshot;
        if ($target === '') {
            throw new \RuntimeException('Επαναφορά χωρίς target ref (to_ref).');
        }
        if ($snapshot === '' || ! is_file($snapshot)) {
            throw new \RuntimeException('Το στιγμιότυπο επαναφοράς δεν βρέθηκε: '.$snapshot);
        }

        // ── preflight ───────────────────────────────────────────────────────
        if (! function_exists('proc_open')) {
            throw new \RuntimeException('Η PHP συνάρτηση proc_open είναι απενεργοποιημένη — αδύνατη η επαναφορά.');
        }
        if (! is_dir(base_path('.git'))) {
            throw new \RuntimeException('Δεν βρέθηκε φάκελος .git — αδύνατη η επαναφορά κώδικα.');
        }

        // ── maintenance ON (idempotent — a failed apply may have left it down) ─
        $this->step($run, 'maintenance', 'Maintenance mode ON', function () use ($run, $php, $artisan) {
            $this->exec($run, [$php, $artisan, 'down', '--retry=15']);
            $this->wentDown = true;
        });

        // ── safety snapshot of the CURRENT state (so the rollback is undoable) ─
        $safety = storage_path('app/db-snapshots/rollback-'.$run->id.'-'.now()->format('Ymd-His').'.sql.gz');
        $this->step($run, 'snapshot', 'DB snapshot (pre-rollback)', function () use ($run, $php, $artisan, $safety) {
            $this->exec($run, [$php, $artisan, 'ekdosi:db-snapshot', '--out='.$safety, '--keep=10']);
            $run->update(['snapshot_file' => $safety]);
        });

        // ── check out the previous code + reinstall its deps ─────────────────
        $this->step($run, 'checkout', 'git checkout '.$target, function () use ($run, $target) {
            $this->exec($run, ['git', 'checkout', '--force', $target], base_path());
            $this->writeBuildStamp($run, $target);
        });
        $this->step($run, 'composer', 'composer install', function () use ($run, $composer) {
            $this->exec(
                $run,
                [$composer, 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--prefer-dist'],
                base_path(),
                ['COMPOSER_MEMORY_LIMIT' => '-1'],
                3600,
            );
        });
        $this->step($run, 'optimize', 'php artisan optimize', function () use ($run, $php, $artisan) {
            $this->exec($run, [$php, $artisan, 'optimize']);
        });
        $this->step($run, 'queue_restart', 'php artisan queue:restart', function () use ($run, $php, $artisan) {
            $this->exec($run, [$php, $artisan, 'queue:restart']);
        });
        $this->step($run, 'opcache', 'opcache flush (best-effort)', function () use ($run) {
            $this->flushOpcache($run);
        });

        // ── restore the pre-update DB — LAST DB write (rewinds update_runs) ───
        $this->step($run, 'db_restore', 'db-restore '.basename($snapshot), function () use ($run, $php, $artisan, $snapshot) {
            $this->exec($run, [$php, $artisan, 'ekdosi:db-restore', '--file='.$snapshot, '--force']);
        });

        // ── re-stamp the audit rows the restore rewound, then lift maintenance ─
        $this->reconcileAudit($run);

        $this->step($run, 'maintenance_off', 'Maintenance mode OFF', function () use ($run, $php, $artisan) {
            $this->exec($run, [$php, $artisan, 'up']);
            $this->wentDown = false;
        });

        BuildInfo::flush();
    }

    /**
     * After a rollback's DB restore, the restored snapshot's `update_runs` table
     * is the pre-update one: it lacks THIS rollback row and shows the reverted
     * update as mid-flight. Re-insert this run (so the generic success write +
     * the UI find it) and flag the reverted update as rolled_back.
     */
    private function reconcileAudit(UpdateRun $run): void
    {
        UpdateRun::query()->updateOrInsert(
            ['id' => $run->id],
            [
                'status' => UpdateRun::STATUS_RUNNING,
                'kind' => UpdateRun::KIND_ROLLBACK,
                'phase' => $run->phase,
                'strategy' => $run->strategy,
                'from_version' => $run->from_version,
                'from_ref' => $run->from_ref,
                'to_version' => $run->to_version,
                'to_ref' => $run->to_ref,
                'rollback_of_id' => $run->rollback_of_id,
                'snapshot_file' => $run->snapshot_file,
                'restore_snapshot' => $run->restore_snapshot,
                'triggered_by_user_id' => $run->triggered_by_user_id,
                'started_at' => $run->started_at,
                'created_at' => $run->created_at ?? now(),
                'updated_at' => now(),
            ],
        );

        if ($run->rollback_of_id) {
            UpdateRun::query()
                ->whereKey($run->rollback_of_id)
                ->update(['status' => UpdateRun::STATUS_ROLLED_BACK]);
        }
    }

    // ───────────────────────────── step helpers ─────────────────────────────

    private function step(UpdateRun $run, string $phase, string $label, Closure $fn): void
    {
        $run->update(['phase' => $phase]);
        $this->append($run, "\n▸ {$label}\n");
        $this->persist($run);

        $fn();

        $this->persist($run);
    }

    /**
     * Run a subprocess, streaming its output (redacted) into the run log + buffer.
     * Throws on a non-zero exit unless $allowFailure (advisory steps).
     *
     * @param  list<string>  $cmd
     * @param  array<string, string>|null  $env
     */
    private function exec(
        UpdateRun $run,
        array $cmd,
        ?string $cwd = null,
        ?array $env = null,
        int $timeout = 1800,
        bool $allowFailure = false,
    ): void {
        $process = new Process($cmd, $cwd ?? base_path(), $env, null, $timeout);
        $process->run(function ($type, $chunk) use ($run) {
            $this->append($run, $chunk);
        });

        if (! $process->isSuccessful() && ! $allowFailure) {
            $tail = trim($process->getErrorOutput()) ?: trim($process->getOutput());
            throw new \RuntimeException(sprintf(
                '«%s» exited %d: %s',
                $cmd[0].' '.($cmd[1] ?? ''),
                (int) $process->getExitCode(),
                $this->redact($tail),
            ));
        }
    }

    /**
     * `checkout --force` REPLACES an untracked file whose path the target ref
     * ships as a tracked one — usually a generated artefact, which is exactly
     * what should happen. Copy them aside first anyway (the panel operator has
     * no shell to recover one), and abort rather than overwrite blind if the
     * copy fails. Mirrors the same block in deploy/update.sh.
     */
    private function protectUntracked(UpdateRun $run, string $target): void
    {
        // -z + quotePath=false: git C-quotes non-ASCII paths by default, which
        // would make the cat-file probe miss a Greek filename.
        $listed = $this->capture(
            ['git', '-c', 'core.quotePath=false', 'ls-files', '--others', '--exclude-standard', '-z'],
            base_path(),
        );

        $backup = storage_path('app/deploy-untracked/'.now()->format('Ymd-His'));

        foreach (array_filter(explode("\0", $listed)) as $path) {
            $tracked = new Process(['git', 'cat-file', '-e', $target.':'.$path], base_path(), null, null, 60);
            $tracked->run();

            if (! $tracked->isSuccessful()) {
                continue;   // not in the target ref — the checkout leaves it alone
            }

            $to = $backup.'/'.$path;
            File::ensureDirectoryExists(dirname($to));

            if (! File::copy(base_path($path), $to)) {
                throw new \RuntimeException("Δεν μπόρεσα να κρατήσω αντίγραφο του '{$path}' στο {$backup} — ματαίωση πριν αντικατασταθεί.");
            }

            $this->append($run, "  αντίγραφο: {$path} → {$to}\n");
        }
    }

    /**
     * Run a subprocess and RETURN its stdout (no streaming) — for tiny probes.
     * THROWS on a non-zero exit: every caller reads the output as fact (the
     * clean-tree pre-flight, the untracked listing), so a failed `git` returning
     * an empty string would read as «clean» / «nothing to protect».
     */
    private function capture(array $cmd, ?string $cwd = null): string
    {
        $process = new Process($cmd, $cwd ?? base_path(), null, null, 60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                '«%s» exited %d: %s',
                implode(' ', array_slice($cmd, 0, 2)),
                (int) $process->getExitCode(),
                $this->redact(trim($process->getErrorOutput()) ?: trim($process->getOutput())),
            ));
        }

        return $process->getOutput();
    }

    /**
     * git fetch --tags. When a token is configured, fetch over authenticated
     * https via GIT_ASKPASS (username embedded in the URL, token from the helper)
     * so the token never lands in argv or .git/config; otherwise fetch from the
     * on-disk `origin` remote (SSH key / credential helper).
     */
    private function gitFetch(UpdateRun $run): void
    {
        $token = (string) config('ekdosi.updates.token', '');
        $repo = trim((string) config('ekdosi.updates.repo', ''));

        if ($token !== '' && $repo !== '') {
            $askpass = $this->makeAskpass($token);
            try {
                $this->exec(
                    $run,
                    ['git', 'fetch', '--tags', '--force', 'https://x-access-token@github.com/'.$repo.'.git'],
                    base_path(),
                    [
                        'GIT_ASKPASS' => $askpass,
                        'GIT_TERMINAL_PROMPT' => '0',
                    ],
                    600,
                );
            } finally {
                @unlink($askpass);
            }

            return;
        }

        $this->exec($run, ['git', 'fetch', '--tags', '--force', 'origin'], base_path(), ['GIT_TERMINAL_PROMPT' => '0'], 600);
    }

    /** A throwaway GIT_ASKPASS helper that echoes the token (chmod 0700). */
    private function makeAskpass(string $token): string
    {
        $dir = storage_path('app/updates');
        File::ensureDirectoryExists($dir);
        $path = $dir.'/askpass-'.bin2hex(random_bytes(6)).'.sh';
        // The token is passed via env (EKDOSI_GIT_TOKEN), not baked into the file,
        // so it isn't left on disk in plaintext.
        File::put($path, "#!/bin/sh\nprintf '%s' \"\$EKDOSI_GIT_TOKEN\"\n");
        @chmod($path, 0700);
        putenv('EKDOSI_GIT_TOKEN='.$token);

        return $path;
    }

    /**
     * Record storage/app/build.json exactly as deploy/update.sh does, so BuildInfo
     * reflects the freshly checked-out commit.
     */
    private function writeBuildStamp(UpdateRun $run, string $ref): void
    {
        // Best-effort: this runs AFTER the checkout, with the app down, and a
        // missing build stamp is cosmetic (BuildInfo falls back) — never a reason
        // to abort a deploy that already landed. Hence tolerant, unlike the
        // pre-flight probes that capture() now throws for.
        $stamp = function (array $cmd): string {
            try {
                return trim($this->capture($cmd, base_path()));
            } catch (Throwable) {
                return '';
            }
        };

        $sha = $stamp(['git', 'rev-parse', '--short', 'HEAD']);
        $committedAt = $stamp(['git', 'log', '-1', '--format=%cI']);

        File::ensureDirectoryExists(storage_path('app'));
        File::put(storage_path('app/build.json'), (string) json_encode([
            'sha' => $sha ?: null,
            'committed_at' => $committedAt ?: null,
            'ref' => $ref,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Best-effort FPM opcache reset via a signed self-hit — resetting from the CLI
     * process wouldn't touch the FPM pool. Non-fatal: some hosts rely on
     * opcache.validate_timestamps instead, and the deploy is already applied.
     */
    private function flushOpcache(UpdateRun $run): void
    {
        try {
            $url = URL::signedRoute('internal.opcache-flush', [], now()->addMinutes(5));
            $res = Http::timeout(10)->get($url);
            $this->append($run, 'opcache-flush → HTTP '.$res->status().' '.$res->body()."\n");
        } catch (Throwable $e) {
            $this->append($run, 'opcache-flush best-effort απέτυχε (μη-κρίσιμο): '.$this->redact($e->getMessage())."\n");
        }
    }

    // ─────────────────────────── output plumbing ────────────────────────────

    /** Append redacted text to the buffer AND the per-run log file. */
    private function append(UpdateRun $run, string $text): void
    {
        $text = $this->redact($text);
        $this->buffer .= $text;

        $dir = storage_path('logs/updates');
        File::ensureDirectoryExists($dir);
        File::append($dir.'/'.$run->id.'.log', $text);
    }

    /** Flush the buffer (capped) into the DB row so the UI poll sees progress. */
    private function persist(UpdateRun $run): void
    {
        $max = 200_000;
        $out = $this->buffer;
        if (mb_strlen($out) > $max) {
            $out = "…[παλαιότερη έξοδος περικόπηκε]…\n".mb_substr($out, -$max);
        }
        $run->update(['output' => $out]);
    }

    /** Strip the configured token + any inline URL credentials from output. */
    private function redact(string $text): string
    {
        $token = (string) config('ekdosi.updates.token', '');
        if ($token !== '') {
            $text = str_replace($token, '***', $text);
        }

        // https://user:pass@host  and  x-access-token:TOKEN@host
        $text = preg_replace('#(https?://)[^/\s:@]+:[^/\s@]+@#i', '$1***:***@', $text) ?? $text;

        return preg_replace('#(x-access-token:)[^@\s]+@#i', '$1***@', $text) ?? $text;
    }

    private function failRun(UpdateRun $run, string $phase, string $message): void
    {
        // Lift maintenance so the operator can reach the panel to roll back or fix
        // forward — an in-app updater on shared hosting has no shell fallback. The
        // app may be in an inconsistent state (half-applied); the failure row says
        // so and «Επαναφορά» restores the pre-update snapshot.
        $inconsistent = $this->wentDown;
        if ($this->wentDown) {
            try {
                (new Process([PHP_BINARY, base_path('artisan'), 'up'], base_path(), null, null, 60))->run();
                $this->wentDown = false;
            } catch (Throwable) {
                // Couldn't lift it — leave it down; nothing safe left to do.
            }
        }

        $run->update([
            'status' => UpdateRun::STATUS_FAILED,
            'phase' => $phase,
            'error_message' => mb_substr($this->redact($message), 0, 60_000),
            'finished_at' => now(),
        ]);
        $this->append($run, "\n✗ ΑΠΟΤΥΧΙΑ στο «{$phase}»: ".$this->redact($message)."\n");
        if ($inconsistent) {
            $this->append($run, $this->wentDown
                ? "⚠ Η εφαρμογή παραμένει σε maintenance mode — χρήση «Επαναφορά» ή `php artisan up`.\n"
                : "⚠ Πιθανή ασυνεπής κατάσταση (μερική εφαρμογή) — χρήση «Επαναφορά» για ασφαλή επιστροφή.\n");
        }
        $this->persist($run);
        $this->error("✗ Η ενημέρωση #{$run->id} απέτυχε στο «{$phase}».");
    }
}
