<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ParsesTenantAndYears;
use App\Services\Accounting\E3YearTotals;
use Firebed\AadeMyData\Exceptions\RateLimitExceededException;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Console\Command;
use Throwable;

/**
 * Store AADE's Ε3 (the accountant's final classification) for whole years — the
 * source of the «Φορολογικά» income-tax estimate for CLOSED years. The CLI twin
 * of the page's «Ανανέωση Ε3 (ΑΑΔΕ)». Read-only on AADE; overwrites the year's
 * snapshot. A 429 stops the run (earlier years are kept — re-run later).
 *
 * Usage:
 *   php artisan mydata:e3-snapshot --tenant=myip --year=2023 --year=2024 --year=2025
 */
class MyDataE3Snapshot extends Command
{
    use ParsesTenantAndYears;

    protected $signature = 'mydata:e3-snapshot
        {--tenant= : Company slug or id (required)}
        {--year=* : Calendar year(s) (required, repeatable)}
        {--gap=5 : Seconds to wait between years}';

    protected $description = 'Store AADE\'s Ε3 per year (source of the «Φορολογικά» estimate for closed years). Read-only on AADE.';

    /** Test seam: a MockHandler threaded into the Ε3 fetch (no network). */
    public static ?MockHandler $testHandler = null;

    public function handle(): int
    {
        $tenant = $this->tenantOption();
        $years = $this->yearsOption();
        if (! $tenant || $years === null) {
            return self::FAILURE;
        }

        $gap = max(0, (int) $this->option('gap'));
        $rows = [];
        $headers = ['Έτος', 'Έσοδα', 'Έξοδα', 'Αγορές παγίων', 'Αποτέλεσμα', 'Εγγραφές'];

        foreach ($years as $i => $year) {
            if ($i > 0 && $gap > 0 && static::$testHandler === null) {
                sleep($gap);
            }

            try {
                $s = E3YearTotals::refresh($tenant, $year, static::$testHandler);
            } catch (RateLimitExceededException) {
                $this->table($headers, $rows);
                $this->warn("Όριο myDATA στο {$year}. Ό,τι αποθηκεύτηκε κρατήθηκε — ξανατρέξτε αργότερα.");

                return self::FAILURE;
            } catch (Throwable $e) {
                $this->table($headers, $rows);
                $this->error("{$year}: ".$e->getMessage());

                return self::FAILURE;
            }

            $fmt = fn ($v) => number_format((float) $v, 2, ',', '.');
            $rows[] = [$year, $fmt($s->income), $fmt($s->expense), $fmt($s->capex), $fmt((float) $s->income - (float) $s->expense), $s->doc_count];
        }

        $this->table($headers, $rows);
        $this->info("{$tenant->slug}: αποθηκεύτηκε το Ε3 για ".count($rows).' έτος/η.');

        return self::SUCCESS;
    }
}
