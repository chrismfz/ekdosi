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

        // Throwaway tenant + invoice type. Slug uniquified so re-running
        // the command doesn't collide with a previous orphan.
        $slug = 'numberer-test-'.uniqid();

        try {
            $company = Company::create([
                'name' => 'Numberer concurrency probe',
                'slug' => $slug,
                'country_code' => 'GR',
                'einvoice_provider' => 'gr-mydata',
                'mydata_production' => false,
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

            DB::disconnect();

            $pids = [];
            for ($i = 0; $i < $workers; $i++) {
                $pid = pcntl_fork();

                if ($pid === -1) {
                    $this->error('pcntl_fork failed');
                    return self::FAILURE;
                }

                if ($pid === 0) {
                    // Child — re-establish DB, allocate once, write result.
                    try {
                        DB::purge();
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

            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
            }

            DB::purge();

            // Collect.
            $allocated = [];
            $errors = [];
            for ($i = 0; $i < $workers; $i++) {
                if (file_exists("$resultsDir/$i.ok")) {
                    $allocated[] = (int) file_get_contents("$resultsDir/$i.ok");
                } elseif (file_exists("$resultsDir/$i.err")) {
                    $errors[] = "child $i: ".file_get_contents("$resultsDir/$i.err");
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
            }
        }
    }
}
