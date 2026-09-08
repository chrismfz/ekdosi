<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\MyData\CustomerSyncFromMyData;
use Carbon\Carbon;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Συγχρονισμός πελατών από myDATA — the customer twin of `suppliers:sync`.
 *
 * Scans `RequestTransmittedDocs` (our sales) for a tenant + window, extracts the
 * unique counterpart AFMs (the customers), and creates a `Customer` for each one
 * not already on file — enriching GR customers from GSIS unless `--no-enrich`.
 * The CLI twin of the «Συγχρονισμός από myDATA» button on the Customers list.
 *
 * READ-from-AADE, write-only-to-customers. Tenant-scoped explicitly (no Filament
 * context on the CLI). Default window = the last 12 months (capture a year of
 * trading partners); override with --months or explicit --from/--to.
 *
 * Usage:
 *   php artisan customers:sync --tenant=nexon
 *   php artisan customers:sync --tenant=nexon --months=24
 *   php artisan customers:sync --tenant=nexon --from=2026-01-01 --to=2026-03-31
 *   php artisan customers:sync --tenant=nexon --no-enrich
 */
class CustomersSync extends Command
{
    protected $signature = 'customers:sync
        {--tenant= : Company slug (or numeric id)}
        {--months=12 : Look back this many months (ignored if --from is given)}
        {--from= : Window start (Y-m-d). Overrides --months}
        {--to= : Window end (Y-m-d). Default: today}
        {--no-enrich : Do NOT call GSIS for GR customers (create AFM-only)}';

    protected $description = 'Sync customers (πελάτες) from myDATA sales counterpart AFMs.';

    public function handle(): int
    {
        $tenantArg = $this->option('tenant');
        if (! $tenantArg) {
            $this->error('--tenant is required (company slug or id).');

            return self::FAILURE;
        }

        $tenant = Company::findBySlugOrId($tenantArg);
        if (! $tenant) {
            $this->error("Tenant '{$tenantArg}' not found.");

            return self::FAILURE;
        }

        $to = $this->option('to') ? Carbon::parse($this->option('to')) : now();
        $from = $this->option('from')
            ? Carbon::parse($this->option('from'))
            : now()->subMonths(max(1, (int) $this->option('months')));
        $enrich = ! $this->option('no-enrich');

        $this->info("Συγχρονισμός πελατών — tenant={$tenant->slug} window={$from->format('Y-m-d')}..{$to->format('Y-m-d')} enrich=".($enrich ? 'on' : 'off'));

        try {
            $result = (new CustomerSyncFromMyData($tenant))->sync($from, $to, $enrich);
        } catch (RuntimeException $e) {
            // Our own guard messages (non-GR / mode off / missing creds).
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('myDATA call failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(['metric', 'count'], [
            ['scanned docs', $result->scannedDocs],
            ['unique AFMs', $result->uniqueAfms],
            ['created', $result->created],
            ['  ↳ enriched via GSIS', $result->enrichedViaGsis],
            ['  ↳ named from doc', $result->namedFromDoc],
            ['  ↳ name-less (AFM only)', $result->nameless],
            ['skipped (already on file)', $result->skippedExisting],
        ]);

        if ($result->gsisFailures !== []) {
            $this->warn('Χωρίς όνομα (απέτυχε η άντληση GSIS) — συμπληρώστε χειροκίνητα: '.implode(', ', $result->gsisFailures));
        }

        $this->info($result->summary());

        return self::SUCCESS;
    }
}
