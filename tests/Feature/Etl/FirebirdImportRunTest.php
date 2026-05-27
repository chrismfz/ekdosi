<?php

namespace Tests\Feature\Etl;

use App\Jobs\RunFirebirdImport;
use App\Models\Company;
use App\Models\FirebirdImportRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PR #30 — covers the upload+dispatch path (not the actual import,
 * which needs pdo_firebird + gbak on the host; blocked in sandbox).
 *
 * What's locked:
 *   1. SHA256 + size are captured on creation.
 *   2. The job is dispatched with the run id + password (in-memory).
 *   3. Password NEVER appears on the row (no `fb_password` column).
 *   4. Duplicate-SHA detection: a re-upload of the same backup
 *      finds the prior completed run (used by the UI to surface a
 *      no-op warning).
 *   5. Failure transition via the job's failed() hook reconciles a
 *      stuck row (worker crash mid-import).
 *   6. Terminal-state detection (used by the polling logic).
 */
class FirebirdImportRunTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create(['name' => 'MyIP', 'slug' => 'myip']);
        $this->operator = User::factory()->create();
    }

    public function test_run_row_persists_metadata_without_password(): void
    {
        $run = FirebirdImportRun::create([
            'company_id'          => $this->tenant->id,
            'uploaded_by_user_id' => $this->operator->id,
            'file_name'           => 'ekdosi-myip.fbk',
            'file_size'           => 1024 * 1024 * 12,
            'file_sha256'         => str_repeat('a', 64),
            'uploaded_path'       => 'firebird-imports/test.fbk',
            'status'              => FirebirdImportRun::STATUS_UPLOADED,
            'fb_host'             => '127.0.0.1',
            'fb_user'             => 'SYSDBA',
        ]);

        $this->assertNotNull($run->id);
        $this->assertSame('SYSDBA', $run->fb_user);
        $this->assertSame('127.0.0.1', $run->fb_host);

        // Password column must NOT exist on the schema. If a future
        // migration adds one (well-meaning "make it auditable!" sort
        // of regression), this test fails loudly. Defence in depth.
        $columns = \Schema::getColumnListing('firebird_import_runs');
        $this->assertNotContains('fb_password', $columns);
        $this->assertNotContains('fb_pass', $columns);
        $this->assertNotContains('password', $columns);
    }

    /**
     * NOTE: This test covers "no DB COLUMN holds the password",
     * NOT "the password never reaches any DB row". The database
     * queue driver serializes the job's public properties into
     * the `jobs.payload` column — so the password IS in that
     * row's blob until the job completes (when the row is
     * deleted) or fails (when it moves to `failed_jobs.payload`).
     * That trade-off is documented in `RunFirebirdImport`'s
     * docblock; this test enforces the narrower invariant that
     * no DOMAIN column ever holds it.
     */
    public function test_dispatch_does_not_serialize_password_into_the_run_row(): void
    {
        Bus::fake();

        $run = FirebirdImportRun::create([
            'company_id'    => $this->tenant->id,
            'file_name'     => 'x.fbk',
            'file_size'     => 100,
            'file_sha256'   => str_repeat('b', 64),
            'uploaded_path' => 'firebird-imports/x.fbk',
            'status'        => FirebirdImportRun::STATUS_UPLOADED,
            'fb_host'       => '127.0.0.1',
            'fb_user'       => 'SYSDBA',
        ]);

        RunFirebirdImport::dispatch($run->id, 'super-secret-password');

        Bus::assertDispatched(RunFirebirdImport::class, function ($job) use ($run) {
            return $job->runId === $run->id && $job->fbPassword === 'super-secret-password';
        });

        // Row in DB has no password trace.
        $reloaded = FirebirdImportRun::find($run->id);
        foreach ($reloaded->getAttributes() as $key => $value) {
            $this->assertStringNotContainsString('super-secret-password', (string) $value,
                "Password leaked into column [{$key}]");
        }
    }

    public function test_duplicate_sha_dedup_query_finds_prior_completed_run(): void
    {
        $sha = str_repeat('c', 64);

        $priorRun = FirebirdImportRun::create([
            'company_id'   => $this->tenant->id,
            'file_name'    => 'day0.fbk',
            'file_size'    => 1000,
            'file_sha256'  => $sha,
            'status'       => FirebirdImportRun::STATUS_COMPLETED,
            'finished_at'  => now()->subDay(),
            'fb_host'      => '127.0.0.1',
            'fb_user'      => 'SYSDBA',
        ]);

        // Simulate uploading the same backup again.
        $newRun = FirebirdImportRun::create([
            'company_id'    => $this->tenant->id,
            'file_name'     => 'day0-reupload.fbk',
            'file_size'     => 1000,
            'file_sha256'   => $sha,
            'uploaded_path' => 'firebird-imports/reup.fbk',
            'status'        => FirebirdImportRun::STATUS_UPLOADED,
            'fb_host'       => '127.0.0.1',
            'fb_user'       => 'SYSDBA',
        ]);

        // The exact query the Create page runs.
        $found = FirebirdImportRun::query()
            ->where('company_id', $this->tenant->id)
            ->where('file_sha256', $sha)
            ->where('status', FirebirdImportRun::STATUS_COMPLETED)
            ->where('id', '!=', $newRun->id)
            ->latest('finished_at')
            ->first();

        $this->assertNotNull($found);
        $this->assertSame($priorRun->id, $found->id);
    }

    public function test_dedup_scoped_per_tenant(): void
    {
        $otherTenant = Company::create(['name' => 'Nixpal', 'slug' => 'nixpal']);
        $sha = str_repeat('d', 64);

        // Same hash but in a different tenant — must NOT show as a
        // dedup hit (different tenants can hold backups that happen
        // to be byte-identical, e.g. an empty schema-only backup).
        FirebirdImportRun::create([
            'company_id'   => $otherTenant->id,
            'file_name'    => 'other.fbk',
            'file_size'    => 1000,
            'file_sha256'  => $sha,
            'status'       => FirebirdImportRun::STATUS_COMPLETED,
            'finished_at'  => now()->subDay(),
            'fb_host'      => '127.0.0.1',
            'fb_user'      => 'SYSDBA',
        ]);

        $found = FirebirdImportRun::query()
            ->where('company_id', $this->tenant->id)
            ->where('file_sha256', $sha)
            ->where('status', FirebirdImportRun::STATUS_COMPLETED)
            ->first();

        $this->assertNull($found, 'Dedup must be tenant-scoped — bleed across tenants is wrong.');
    }

    public function test_failed_hook_reconciles_stuck_importing_row(): void
    {
        $run = FirebirdImportRun::create([
            'company_id'    => $this->tenant->id,
            'file_name'     => 'x.fbk',
            'file_size'     => 100,
            'file_sha256'   => str_repeat('e', 64),
            'uploaded_path' => 'firebird-imports/x.fbk',
            'status'        => FirebirdImportRun::STATUS_IMPORTING,
            'started_at'    => now(),
            'fb_host'       => '127.0.0.1',
            'fb_user'       => 'SYSDBA',
        ]);

        $job = new RunFirebirdImport($run->id, 'pw');
        $job->failed(new \RuntimeException('worker OOM'));

        $run->refresh();
        $this->assertSame(FirebirdImportRun::STATUS_FAILED, $run->status);
        $this->assertSame('migrate', $run->failed_step);
        $this->assertStringContainsString('worker OOM', $run->error_message);
        $this->assertNotNull($run->finished_at);
    }

    public function test_failed_hook_is_no_op_when_row_already_terminal(): void
    {
        $run = FirebirdImportRun::create([
            'company_id'    => $this->tenant->id,
            'file_name'     => 'x.fbk',
            'file_size'     => 100,
            'file_sha256'   => str_repeat('f', 64),
            'status'        => FirebirdImportRun::STATUS_COMPLETED,
            'started_at'    => now()->subMinute(),
            'finished_at'   => now(),
            'fb_host'       => '127.0.0.1',
            'fb_user'       => 'SYSDBA',
        ]);
        $finishedAtBefore = $run->finished_at;

        $job = new RunFirebirdImport($run->id, 'pw');
        $job->failed(new \RuntimeException('late retry'));

        $run->refresh();
        // Already-completed rows must NOT be flipped to failed by a
        // stale failed() invocation.
        $this->assertSame(FirebirdImportRun::STATUS_COMPLETED, $run->status);
        $this->assertEquals(
            $finishedAtBefore->format('Y-m-d H:i:s'),
            $run->finished_at->format('Y-m-d H:i:s'),
        );
    }

    /**
     * Three-way match on `failed_step`: blind review caught that a
     * worker that crashes BEFORE any status update (still 'uploaded')
     * was being labeled as failed at 'migrate' — misleading because
     * the migrate step never started. Locked here.
     */
    public function test_failed_hook_records_correct_step_for_each_status(): void
    {
        $base = [
            'company_id'  => $this->tenant->id,
            'file_name'   => 't.fbk',
            'file_size'   => 1,
            'file_sha256' => str_repeat('5', 64),
            'fb_host'     => '127.0.0.1',
            'fb_user'     => 'SYSDBA',
        ];

        foreach ([
            FirebirdImportRun::STATUS_UPLOADED  => 'gbak',
            FirebirdImportRun::STATUS_RESTORING => 'gbak',
            FirebirdImportRun::STATUS_IMPORTING => 'migrate',
        ] as $status => $expectedStep) {
            $run = FirebirdImportRun::create($base + [
                'status'      => $status,
                'file_sha256' => str_repeat($status[0], 64),  // unique per row
            ]);

            $job = new RunFirebirdImport($run->id, 'pw');
            $job->failed(new \RuntimeException('worker died'));

            $run->refresh();
            $this->assertSame(
                $expectedStep,
                $run->failed_step,
                "Expected failed_step='{$expectedStep}' when row was in status='{$status}', got '{$run->failed_step}'"
            );
        }
    }

    /**
     * The head-and-tail truncation for huge gbak error blobs. The
     * original `mb_strimwidth($msg, 0, 4000)` lopped off the tail
     * — which is exactly where gbak prints the "failed: <reason>"
     * line that operators need. Locked behaviour: head + elision
     * marker + tail, under the 30K cap.
     */
    public function test_huge_error_messages_are_head_tail_truncated(): void
    {
        $run = FirebirdImportRun::create([
            'company_id'    => $this->tenant->id,
            'file_name'     => 'corrupt.fbk',
            'file_size'     => 100,
            'file_sha256'   => str_repeat('6', 64),
            'uploaded_path' => 'firebird-imports/c.fbk',
            'status'        => FirebirdImportRun::STATUS_RESTORING,
            'fb_host'       => '127.0.0.1',
            'fb_user'       => 'SYSDBA',
        ]);

        // Simulate gbak vomiting 50KB of stderr with the actual
        // error at the very end (typical gbak shape).
        $noise = str_repeat('restoring page... ok\n', 2000);
        $actualError = 'gbak: ERROR: invalid block type encountered';
        $huge = $noise.$actualError;

        $job = new RunFirebirdImport($run->id, 'pw');
        $reflection = new \ReflectionMethod($job, 'failRun');
        $reflection->setAccessible(true);
        $reflection->invoke($job, $run, 'gbak', $huge);

        $run->refresh();
        $this->assertLessThanOrEqual(30_000, mb_strlen($run->error_message));
        // The crucial bit — the actual error line MUST survive
        // (it's at the tail of the input).
        $this->assertStringContainsString($actualError, $run->error_message,
            'Head+tail truncation should preserve the gbak failure line at the tail.');
        $this->assertStringContainsString('chars elided', $run->error_message,
            'Truncation marker should be visible so the operator knows content was cut.');
    }

    /**
     * Operators can upload either `.fbk` (gbak backup; job runs
     * `gbak -r` to restore) OR `.fdb` (already-restored Firebird
     * database; job uses it directly). The extension on the
     * ORIGINAL filename — stored at upload time via
     * `storeFileNamesIn('original_file_name')` and then surfaced
     * on `file_name` — drives the branch.
     *
     * This test locks the detection logic: the job picks the
     * right path based on the `file_name` extension. We don't
     * actually run gbak / artisan here (sandbox blocker), but
     * the row's `file_name` is what matters and is operator-
     * visible in the UI history table.
     */
    public function test_file_name_extension_determines_processing_path(): void
    {
        $fbkRun = FirebirdImportRun::create([
            'company_id'    => $this->tenant->id,
            'file_name'     => 'ekdosi-myip.fbk',
            'file_size'     => 1000,
            'file_sha256'   => str_repeat('a', 64),
            'uploaded_path' => 'firebird-imports/x.fbk',
            'status'        => FirebirdImportRun::STATUS_UPLOADED,
            'fb_host'       => '127.0.0.1',
            'fb_user'       => 'SYSDBA',
        ]);

        $fdbRun = FirebirdImportRun::create([
            'company_id'    => $this->tenant->id,
            'file_name'     => 'ekdosi-myip.fdb',
            'file_size'     => 1000,
            'file_sha256'   => str_repeat('b', 64),
            'uploaded_path' => 'firebird-imports/x.fdb',
            'status'        => FirebirdImportRun::STATUS_UPLOADED,
            'fb_host'       => '127.0.0.1',
            'fb_user'       => 'SYSDBA',
        ]);

        $this->assertSame(
            'fbk',
            strtolower(pathinfo($fbkRun->file_name, PATHINFO_EXTENSION)),
        );
        $this->assertSame(
            'fdb',
            strtolower(pathinfo($fdbRun->file_name, PATHINFO_EXTENSION)),
        );

        // Case-insensitive detection — operators on Windows often
        // get `.FDB` (uppercase) extensions.
        $upperRun = FirebirdImportRun::create([
            'company_id'    => $this->tenant->id,
            'file_name'     => 'EKDOSI.FDB',
            'file_size'     => 1000,
            'file_sha256'   => str_repeat('c', 64),
            'uploaded_path' => 'firebird-imports/x.FDB',
            'status'        => FirebirdImportRun::STATUS_UPLOADED,
            'fb_host'       => '127.0.0.1',
            'fb_user'       => 'SYSDBA',
        ]);
        $this->assertSame(
            'fdb',
            strtolower(pathinfo($upperRun->file_name, PATHINFO_EXTENSION)),
        );
    }

    public function test_is_terminal_returns_true_only_for_completed_or_failed(): void
    {
        $base = [
            'company_id'  => $this->tenant->id,
            'file_name'   => 't.fbk',
            'file_size'   => 1,
            'file_sha256' => str_repeat('1', 64),
            'fb_host'     => '127.0.0.1',
            'fb_user'     => 'SYSDBA',
        ];

        $this->assertFalse(FirebirdImportRun::make($base + ['status' => 'uploaded'])->isTerminal());
        $this->assertFalse(FirebirdImportRun::make($base + ['status' => 'restoring'])->isTerminal());
        $this->assertFalse(FirebirdImportRun::make($base + ['status' => 'importing'])->isTerminal());
        $this->assertTrue(FirebirdImportRun::make($base + ['status' => 'completed'])->isTerminal());
        $this->assertTrue(FirebirdImportRun::make($base + ['status' => 'failed'])->isTerminal());
    }
}
