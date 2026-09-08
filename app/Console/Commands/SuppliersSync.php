<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\MyData\SupplierSyncFromMyData;
use Carbon\Carbon;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Συγχρονισμός προμηθευτών από myDATA (E-phase, "sync" provenance).
 *
 * Scans `RequestDocs` for a tenant + window, extracts the unique issuer AFMs,
 * and creates a `Supplier` (`source=sync`) for each one not already on file —
 * enriching GR suppliers from GSIS unless `--no-enrich` is passed. The CLI
 * twin of the "Συγχρονισμός από myDATA" button on the Suppliers list.
 *
 * READ-from-AADE, write-only-to-suppliers; touches no expense/invoice data.
 * Tenant-scoped explicitly (no Filament context on the CLI).
 *
 * Usage:
 *   php artisan suppliers:sync --tenant=nexon
 *   php artisan suppliers:sync --tenant=nexon --from=2026-01-01 --to=2026-03-31
 *   php artisan suppliers:sync --tenant=nexon --no-enrich
 */
class SuppliersSync extends Command
{
    protected $signature = 'suppliers:sync
        {--tenant= : Company slug (or numeric id)}
        {--from= : Window start (Y-m-d). Default: one month ago}
        {--to= : Window end (Y-m-d). Default: today}
        {--no-enrich : Do NOT call GSIS for GR suppliers (create AFM-only)}';

    protected $description = 'Sync suppliers (προμηθευτές) from myDATA RequestDocs issuer AFMs.';

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

        $from = $this->option('from') ? Carbon::parse($this->option('from')) : now()->subMonth();
        $to = $this->option('to') ? Carbon::parse($this->option('to')) : now();
        $enrich = ! $this->option('no-enrich');

        $this->info("Συγχρονισμός προμηθευτών — tenant={$tenant->slug} window={$from->format('Y-m-d')}..{$to->format('Y-m-d')} enrich=".($enrich ? 'on' : 'off'));

        try {
            $result = (new SupplierSyncFromMyData($tenant))->sync($from, $to, $enrich);
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
