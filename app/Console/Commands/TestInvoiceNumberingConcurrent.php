<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\InvoiceType;
use App\Services\InvoiceNumberer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Hammer InvoiceNumberer::allocate() with N concurrent processes and verify
 * no two of them allocate the same ΑΑ.
 *
 * Why this isn't a PHPUnit test:
 * - PHPUnit's RefreshDatabase wraps tests in a transaction that forked child
 *   processes (with their own DB connections) can't see — they'd find no
 *   fixtures and fail before contention is exercised.
 * - Running these tests against the dev DB without RefreshDatabase would
 *   wipe real data.
 * - The lock guarantee we're checking is provided by MariaDB+Laravel
 *   themselves; the regression we'd catch here is the call to
 *   lockForUpdate() being dropped from the service, or the transaction
 *   wrapper being dropped from the call site.
 *
 * Operator usage on the test box:
 *
 *     sudo -u ekdosi php artisan test:invoice-numbering-concurrent
 *
 * The command creates a throwaway company + invoice type, spawns N
 * children, asserts a contiguous 1..N allocation sequence + a final
 * invcount of N+1, then cleans up. Idempotent — re-runs work.
 */
class TestInvoiceNumberingConcurrent extends Command
{
    protected $signature = 'test:invoice-numbering-concurrent
        {--workers=12 : Number of concurrent allocator processes to fork}';

    protected $description = 'Hammer InvoiceNumberer::allocate() concurrently to verify lockForUpdate() prevents ΑΑ collisions';

    public function handle(): int
    {
        if (! function_exists('pcntl_fork')) {
            $this->error('pcntl_fork is not available — this command needs CLI PHP with pcntl enabled.');

            return self::FAILURE;
        }

        $workers = (int) $this->option('workers');
        if ($workers < 2) {
            $this->error('--workers must be >= 2');

            return self::FAILURE;
        }

        // Sweep orphans from prior runs that died between create and the
        // cleanup in the finally block (SIGKILL, host crash, etc.). The
        // slug pattern is owned exclusively by this command so the wildcard
        // is safe — no real tenant will ever match.
        $orphanCompanies = Company::where('slug', 'like', 'numberer-test-%')->pluck('id');
        if ($orphanCompanies->isNotEmpty()) {
            InvoiceType::whereIn('company_id', $orphanCompanies)->forceDelete();
            Company::whereIn('id', $orphanCompanies)->forceDelete();
            // Mass delete skips the CompanyObserver → clean the tenant roles too.
            DB::table('roles')->whereIn('company_id', $orphanCompanies)->delete();
            $this->warn('Swept '.$orphanCompanies->count().' orphan probe tenant(s) from prior runs.');
        }

        // Throwaway tenant + invoice type. Slug uniquified so re-running
        // the command doesn't collide with a previous orphan.
        $slug = 'numberer-test-'.uniqid();

        try {
            $company = Company::create([
                'name' => 'Numberer concurrency probe',
                'slug' => $slug,
                'country_code' => 'GR',
                'einvoice_provider' => 'gr-mydata',
                'mydata_mode' => 'off',
            ]);

            $type = InvoiceType::create([
                'company_id' => $company->id,
                'code' => 'APY',
                'name' => 'ΑΠΥ — concurrency probe',
                'invcount' => 1,
            ]);

            $this->info("Spawning {$workers} workers against company_id={$company->id} / code=APY ...");

            $resultsDir = sys_get_temp_dir().'/numberer-'.uniqid();
            mkdir($resultsDir);

            // Start barrier (SET-3 review): without it, the children can serialise
            // (each commits before the next reads) and a DROPPED lockForUpdate()
            // would still produce a clean 1..N — a false-green. Each child sets up
            // its own connection, signals "ready", then SPINS until the parent
            // releases the GO flag, so all workers hit the locked SELECT within
            // microseconds of each other → maximal contention → a missing lock
            // deterministically collides.
            $goFile = "$resultsDir/GO";

            DB::disconnect();

            $pids = [];
            for ($i = 0; $i < $workers; $i++) {
                $pid = pcntl_fork();

                if ($pid === -1) {
                    $this->error('pcntl_fork failed');

                    return self::FAILURE;
                }

                if ($pid === 0) {
                    // Child — re-establish DB, wait at the barrier, allocate once.
                    try {
                        DB::purge();
                        DB::connection()->getPdo();          // open the connection NOW (before the barrier)
                        touch("$resultsDir/ready-$i");        // signal ready
                        $spin = 0;
                        while (! file_exists($goFile) && $spin++ < 300_000) {
                            usleep(100);                      // ≤30s safety, then proceed regardless
                        }
                        $allocation = DB::transaction(fn () => app(InvoiceNumberer::class)
                            ->allocate($company, 'APY'));
                        file_put_contents("$resultsDir/$i.ok", (string) $allocation->code);
                    } catch (Throwable $e) {
                        file_put_contents("$resultsDir/$i.err", $e->getMessage());
                    }
                    exit(0);
                }

                $pids[] = $pid;
            }

            // Release all children at once, once every worker has a live connection
            // parked at the barrier (or a 30s safety deadline elapses).
            $deadline = microtime(true) + 30;
            while (count(glob("$resultsDir/ready-*")) < $workers && microtime(true) < $deadline) {
                usleep(500);
            }
            touch($goFile);

            // Track which workers exited cleanly. A SIGSEGV / OOM / SIGKILL
            // before the child writes its .ok/.err file would otherwise be
            // invisible — the parent would just see "no result file" and
            // skip silently, under-counting allocations and making a real
            // lock-skip bug look like a passing run.
            $crashed = [];
            foreach ($pids as $idx => $pid) {
                pcntl_waitpid($pid, $status);
                if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                    $crashed[$idx] = "child {$idx} (pid {$pid}) exited abnormally: status={$status}";
                }
            }

            DB::purge();

            // Collect.
            $allocated = [];
            $errors = array_values($crashed);
            for ($i = 0; $i < $workers; $i++) {
                if (file_exists("$resultsDir/$i.ok")) {
                    $allocated[] = (int) file_get_contents("$resultsDir/$i.ok");
                } elseif (file_exists("$resultsDir/$i.err")) {
                    $errors[] = "child $i: ".file_get_contents("$resultsDir/$i.err");
                } elseif (! isset($crashed[$i])) {
                    // Child exited 0 but wrote no file — also a silent failure.
                    $errors[] = "child $i: no result file (exited cleanly but produced no output)";
                }
            }

            array_map('unlink', glob("$resultsDir/*"));
            rmdir($resultsDir);

            if ($errors) {
                $this->error('Children errored:');
                foreach ($errors as $e) {
                    $this->line('  '.$e);
                }

                return self::FAILURE;
            }

            sort($allocated);
            $expected = range(1, $workers);

            $finalCount = $type->fresh()->invcount;

            $this->table(
                ['Workers', 'Allocations returned', 'Duplicates?', 'Gaps?', 'Final invcount', 'Expected'],
                [[
                    $workers,
                    implode(',', $allocated),
                    count($allocated) === count(array_unique($allocated)) ? 'no' : 'YES',
                    $allocated === $expected ? 'no' : 'YES',
                    $finalCount,
                    $workers + 1,
                ]],
            );

            $pass = $allocated === $expected && $finalCount === $workers + 1;

            if (! $pass) {
                $this->error('FAILED — concurrent allocation produced duplicates, gaps, or wrong final count.');

                return self::FAILURE;
            }

            $this->info('PASS — '.$workers.' concurrent allocations produced a contiguous 1..N sequence with no collisions.');

            return self::SUCCESS;

        } finally {
            // Always clean up the throwaway tenant, even on failure.
            if (isset($company)) {
                InvoiceType::where('company_id', $company->id)->forceDelete();
                Company::where('id', $company->id)->forceDelete();
                // Mass delete skips the CompanyObserver → clean the tenant roles too.
                DB::table('roles')->where('company_id', $company->id)->delete();
            }
        }
    }
}
