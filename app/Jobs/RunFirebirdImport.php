<?php

namespace App\Jobs;

use App\Models\FirebirdImportRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * PR #30 — Queued Firebird `.fbk` import.
 *
 * Three steps:
 *   1. status=restoring — run `gbak -r` to materialise the uploaded
 *      `.fbk` as a temp `.fdb` on the local filesystem.
 *   2. status=importing — invoke `migrate:firebird` against the temp
 *      `.fdb`, using --company-id to target the existing tenant.
 *   3. status=completed (or failed) — parse the per-table counts
 *      JSON if the command emitted one; clean up the temp `.fdb`
 *      and the uploaded `.fbk` on success (keep both on failure for
 *      operator debugging).
 *
 * Required on the host:
 *   - `gbak` binary in PATH (firebird-classic / firebird3.0-utils
 *     package on Debian; `brew install firebird` on macOS dev).
 *   - `pdo_firebird` PHP extension (CLAUDE.md env-prep note — the
 *     ondrej/php PPA blocks in Claude Code on the web sandbox; ETL
 *     only runs on operator's deploy box).
 *
 * Idempotency: the run row is keyed by surrogate id; re-dispatching
 * the same job (e.g. queue worker retry) re-runs the import, which
 * is safe by design via PR #29's upsert semantics. So even a
 * mid-run worker crash + retry produces correct final state, just
 * with a duplicate "completed" log entry — acceptable.
 *
 * Why a queue job vs. sync execution:
 *   - Importing a 100K-row tenant takes minutes; HTTP request timeout
 *     would kill it.
 *   - Operator can close the panel tab and check back later; the row
 *     keeps live status.
 *
 * Failure handling: any throw transitions the row to status='failed'
 * via the `failed()` hook (Laravel calls it after retries are
 * exhausted). The exception message + which step it was in are
 * persisted; operator sees a clear "failed at gbak" or "failed at
 * migrate" badge in the UI.
 */
class RunFirebirdImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * One attempt only — a `gbak -r` failure or a `migrate:firebird`
     * exception is almost always a real environmental / data issue
     * (missing extension, corrupt backup, schema drift). Retrying
     * blindly burns minutes of CPU and risks side-effects from a
     * partial transaction rollback. Operator re-dispatches manually
     * after fixing root cause.
     */
    public int $tries = 1;

    /**
     * Long-running by design. 30 minutes is generous for a 100K-row
     * tenant; the cutover backup of myip is ~12K invoices, so well
     * inside the budget. Bump via job middleware if a large tenant
     * surfaces.
     */
    public int $timeout = 1800;

    public function __construct(public int $runId, public string $fbPassword)
    {
        // password is passed by value (in-memory only) — NOT stored on
        // the run row. The worker serializes the job before pickup;
        // the database queue driver stores the serialized payload
        // including this property. Trade-off: queue payload contains
        // plaintext, but it's transient (deleted on success) and
        // already inside the application's trust boundary (no
        // worker-to-third-party hop). Acceptable.
    }

    public function handle(): void
    {
        $run = FirebirdImportRun::findOrFail($this->runId);

        $run->update([
            'status'     => FirebirdImportRun::STATUS_RESTORING,
            'started_at' => now(),
        ]);

        $uploadedFullPath = Storage::disk('local')->path($run->uploaded_path);
        if (! is_file($uploadedFullPath)) {
            $this->failRun($run, 'gbak', "Uploaded file vanished: {$run->uploaded_path}");
            throw new \RuntimeException("Uploaded file not found");
        }

        // Temp .fdb + counts JSON live in the OS temp dir, NOT under
        // storage/. Reason: Laravel 11+ sets the `local` disk root to
        // storage/app/private/, while storage_path('app/...') points
        // at storage/app/. Mixing the two on a fresh install produced
        // a "directory doesn't exist" failure that the blind review
        // caught. sys_get_temp_dir() is the right home for truly
        // ephemeral byproducts:
        //   - Already exists on every host (no mkdir needed)
        //   - Auto-cleaned by the OS on reboot if our explicit
        //     cleanup misses one
        //   - Not backed up by spatie/laravel-backup (storage/ IS,
        //     by default — so temp .fdb files would leak into
        //     nightly backups otherwise)
        $tempBase = rtrim(sys_get_temp_dir(), '/');
        $tempFdb = "{$tempBase}/ekdosi-import-{$run->id}.fdb";
        $countsPath = "{$tempBase}/ekdosi-counts-{$run->id}.json";
        @unlink($tempFdb);
        @unlink($countsPath);

        $gbak = new Process([
            'gbak', '-r',
            $uploadedFullPath,
            $tempFdb,
            '-user', $run->fb_user,
            '-password', $this->fbPassword,
        ]);
        $gbak->setTimeout(600);  // 10 minutes for the restore alone

        try {
            $gbak->run();
        } catch (Throwable $e) {
            $this->failRun($run, 'gbak', 'gbak execution failed: '.$e->getMessage());
            throw $e;
        }

        if (! $gbak->isSuccessful()) {
            $stderr = trim($gbak->getErrorOutput()) ?: trim($gbak->getOutput());
            $this->failRun($run, 'gbak', "gbak exited {$gbak->getExitCode()}: {$stderr}");
            throw new \RuntimeException("gbak failed: {$stderr}");
        }

        // --- step 2: drain the .fdb into ekdosi via migrate:firebird ---
        $run->update(['status' => FirebirdImportRun::STATUS_IMPORTING]);

        $artisan = new Process([
            PHP_BINARY,
            base_path('artisan'),
            'migrate:firebird',
            '--company-id='.$run->company_id,
            '--fdb='.$tempFdb,
            '--host='.$run->fb_host,
            '--fbuser='.$run->fb_user,
            '--fbpass='.$this->fbPassword,
            '--counts-out='.$countsPath,
        ], base_path());
        $artisan->setTimeout($this->timeout - 60);  // leave headroom

        try {
            $artisan->run();
        } catch (Throwable $e) {
            $this->failRun($run, 'migrate', 'migrate:firebird execution failed: '.$e->getMessage());
            @unlink($tempFdb);
            throw $e;
        }

        if (! $artisan->isSuccessful()) {
            $stderr = trim($artisan->getErrorOutput()) ?: trim($artisan->getOutput());
            $this->failRun($run, 'migrate', "migrate:firebird exited {$artisan->getExitCode()}: {$stderr}");
            @unlink($tempFdb);
            throw new \RuntimeException("migrate:firebird failed: {$stderr}");
        }

        // --- step 3: success — collect counts, cleanup ---
        $counts = null;
        if (is_file($countsPath)) {
            $counts = json_decode((string) file_get_contents($countsPath), true);
            @unlink($countsPath);
        }

        $run->update([
            'status'      => FirebirdImportRun::STATUS_COMPLETED,
            'finished_at' => now(),
            'counts_json' => $counts,
        ]);

        // Clean up temp + uploaded files on success. Keep on failure
        // (handled by failRun) for operator debugging.
        @unlink($tempFdb);
        if ($run->uploaded_path) {
            Storage::delete($run->uploaded_path);
            $run->update(['uploaded_path' => null]);
        }

        Log::info('firebird-import.completed', [
            'run_id'     => $run->id,
            'company_id' => $run->company_id,
            'counts'     => $counts,
        ]);
    }

    /**
     * Final-state failure path — called from `handle()` for clean
     * exceptions AND from `failed()` for crashes / timeouts the
     * try/catch doesn't cover.
     */
    private function failRun(FirebirdImportRun $run, string $step, string $message): void
    {
        $run->update([
            'status'        => FirebirdImportRun::STATUS_FAILED,
            'failed_step'   => $step,
            'error_message' => $this->truncateForLog($message),
            'finished_at'   => now(),
        ]);
        Log::warning('firebird-import.failed', [
            'run_id' => $run->id,
            'step'   => $step,
            'error'  => $message,
        ]);
    }

    /**
     * gbak's stderr on a corrupt `.fbk` can be tens of KB. The original
     * `mb_strimwidth($msg, 0, 4000)` truncated from the TAIL — losing
     * the actual error line which is usually at the end of gbak's
     * "restoring from x... ok / failed: ..." output. Instead: keep
     * head + tail bracketing a "… N chars elided …" marker, so the
     * operator sees both the "what was happening" prefix AND the
     * "what blew up" suffix. Total cap stays well under the TEXT
     * column ceiling.
     */
    private function truncateForLog(string $message): string
    {
        $max = 30_000;
        if (mb_strlen($message) <= $max) {
            return $message;
        }
        $headLen = $tailLen = (int) ($max / 2) - 50;
        $head = mb_substr($message, 0, $headLen);
        $tail = mb_substr($message, -$tailLen);
        $elided = mb_strlen($message) - $headLen - $tailLen;
        return $head."\n…\n[{$elided} chars elided]\n…\n".$tail;
    }

    /**
     * Laravel calls this when the queue runner finishes processing
     * the job AND tries=1 attempts have been exhausted. We use it
     * for crash/timeout reconciliation — if the worker died mid-
     * handle (kill -9, OOM, DB connection drop), the row never
     * transitioned out of restoring/importing. Mark it failed so
     * the operator sees a clear "this run died" instead of a row
     * stuck in 'importing' forever.
     */
    public function failed(?Throwable $exception): void
    {
        $run = FirebirdImportRun::find($this->runId);
        if ($run === null || $run->isTerminal()) {
            return;
        }
        // Map the current status to the step it died IN. Edge case:
        // 'uploaded' means the worker crashed BEFORE any update —
        // never reached the gbak step. Record it as 'gbak' (the
        // step we were ABOUT to run) so the operator sees a coherent
        // "didn't even start" failure rather than the misleading
        // 'migrate' (a step that definitely didn't run).
        $step = match ($run->status) {
            FirebirdImportRun::STATUS_IMPORTING => 'migrate',
            FirebirdImportRun::STATUS_RESTORING => 'gbak',
            default                             => 'gbak',  // 'uploaded' falls here
        };
        $this->failRun(
            $run,
            $step,
            'Worker terminated unexpectedly: '.($exception?->getMessage() ?? 'unknown'),
        );
    }
}
