<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\MyData\ExpenseImporter;
use Carbon\Carbon;
use Firebed\AadeMyData\Exceptions\RateLimitExceededException;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Console\Command;
use Throwable;

/**
 * Back-fill a tenant's expenses from myDATA for whole past years — the CLI twin
 * of the Κονσόλα myDATA → Έξοδα actions («Εισαγωγή αδέσποτων» + «Δικά μας
 * έξοδα»), for when the expense import started later than the books (earlier
 * years then look like pure profit on «Φορολογικά»).
 *
 * Same services as the console, so the same guarantees: ExpenseImporter is
 * IDEMPOTENT (a MARK already present — even soft-deleted — is skipped, never
 * duplicated), writes each expense in one transaction and scopes every query by
 * company_id (explicit tenant — the CLI rule in CLAUDE.md). Re-running a year is
 * safe. The window is fetched QUARTER BY QUARTER with a gap between calls, to
 * stay under AADE's rate limit; a 429 stops the run (already-imported quarters
 * are kept — just re-run later).
 *
 * Usage:
 *   php artisan mydata:import-expenses --tenant=myip --year=2025
 *   php artisan mydata:import-expenses --tenant=myip --year=2023 --year=2024 --year=2025
 *   php artisan mydata:import-expenses --tenant=myip --year=2025 --only=suppliers   # or --only=self
 */
class MyDataImportExpenses extends Command
{
    protected $signature = 'mydata:import-expenses
        {--tenant= : Company slug or id (required)}
        {--year=* : Calendar year(s) to import (required, repeatable)}
        {--only= : "suppliers" (RequestDocs) or "self" (our 13/14/17.x) — default both}
        {--gap=2 : Seconds to wait between AADE calls}';

    protected $description = 'Back-fill expenses from myDATA for whole years (supplier docs + our self-declared 13/14/17.x), quarter by quarter. Idempotent.';

    /** Test seam: a MockHandler threaded into the importer (no network). */
    public static ?MockHandler $testHandler = null;

    public function handle(): int
    {
        $key = (string) $this->option('tenant');
        // Slug first, then a numeric id — never an OR that could match two tenants.
        $tenant = $key === '' ? null
            : (Company::query()->where('slug', $key)->first()
                ?? (ctype_digit($key) ? Company::query()->find((int) $key) : null));
        if (! $tenant) {
            $this->error('Δώστε υπαρκτή εταιρία: --tenant=SLUG');

            return self::FAILURE;
        }

        $raw = (array) $this->option('year');
        // Reject anything but a plain year: intval('2023,2024') would silently import only 2023.
        if (array_filter($raw, fn ($y) => ! ctype_digit((string) $y)) !== []) {
            $this->error('Κάθε --year πρέπει να είναι ένα έτος (π.χ. --year=2023 --year=2024).');

            return self::FAILURE;
        }
        $years = array_values(array_unique(array_map('intval', $raw)));
        sort($years);
        $thisYear = (int) now()->year;
        if ($years === [] || array_filter($years, fn (int $y) => $y < 2019 || $y > $thisYear) !== []) {
            $this->error("Δώστε έτος/έτη με --year (2019–{$thisYear}).");

            return self::FAILURE;
        }

        $only = (string) $this->option('only');
        if (! in_array($only, ['', 'suppliers', 'self'], true)) {
            $this->error('--only δέχεται "suppliers" ή "self".');

            return self::FAILURE;
        }
        $directions = $only === '' ? ['suppliers', 'self'] : [$only];
        $gap = max(0, (int) $this->option('gap'));

        $importer = new ExpenseImporter($tenant, static::$testHandler);
        $rows = [];
        $first = true;

        foreach ($years as $year) {
            foreach ([1, 2, 3, 4] as $q) {
                $from = Carbon::create($year, ($q - 1) * 3 + 1, 1)->startOfDay();
                if ($from->isFuture()) {
                    break;
                }
                $to = $from->copy()->addMonths(2)->endOfMonth();
                if ($to->isFuture()) {
                    $to = now()->endOfDay();
                }

                foreach ($directions as $direction) {
                    if (! $first && $gap > 0 && static::$testHandler === null) {
                        sleep($gap);
                    }
                    $first = false;

                    try {
                        $r = $direction === 'suppliers'
                            ? $importer->import($from->copy(), $to->copy())
                            : $importer->importSelfDeclared($from->copy(), $to->copy());
                    } catch (RateLimitExceededException $e) {
                        $this->table(['Περίοδος', 'Είδος', 'Σαρώθηκαν', 'Νέα', 'Υπήρχαν'], $rows);
                        $this->warn("Όριο myDATA στο {$year} Τ{$q} ({$direction}). Ό,τι μπήκε κρατήθηκε — ξανατρέξτε αργότερα.");

                        return self::FAILURE;
                    } catch (Throwable $e) {
                        $this->table(['Περίοδος', 'Είδος', 'Σαρώθηκαν', 'Νέα', 'Υπήρχαν'], $rows);
                        $this->error("{$year} Τ{$q} ({$direction}): ".$e->getMessage());

                        return self::FAILURE;
                    }

                    $rows[] = [
                        "{$year} Τ{$q}",
                        $direction === 'suppliers' ? 'Προμηθευτών' : 'Δικά μας (13/14/17.x)',
                        $r->scannedDocs,
                        $r->created,
                        $r->skippedExisting,
                    ];
                }
            }
        }

        $this->table(['Περίοδος', 'Είδος', 'Σαρώθηκαν', 'Νέα', 'Υπήρχαν'], $rows);
        $created = array_sum(array_column($rows, 3));
        $this->info("{$tenant->slug}: καταχωρήθηκαν {$created} νέα έξοδα.");

        return self::SUCCESS;
    }
}
