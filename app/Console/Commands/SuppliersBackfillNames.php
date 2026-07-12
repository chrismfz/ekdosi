<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Supplier;
use App\Services\MyData\SupplierGsisEnricher;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Backfill names on suppliers auto-created from myDATA with only their ΑΦΜ.
 *
 * GR myDATA documents forbid the domestic party name ([219]/[220]), so a
 * supplier discovered from an expense doc lands as a bare ΑΦΜ («παύλα» in the
 * Έξοδα list). New imports now GSIS-enrich on create; this repairs the ones
 * created BEFORE that, or where GSIS was down at import time. Fill-only-empty —
 * an operator-typed name/address is never overwritten.
 *
 * READ-from-GSIS, write-only-to-suppliers. Tenant-scoped explicitly (no
 * Filament context on the CLI). Best-effort: a supplier whose ΑΦΜ GSIS can't
 * resolve is left as-is and reported.
 *
 * Usage:
 *   php artisan suppliers:backfill-names --tenant=myip
 *   php artisan suppliers:backfill-names                 # all GR tenants
 */
class SuppliersBackfillNames extends Command
{
    protected $signature = 'suppliers:backfill-names
        {--tenant= : Company slug or id (default: all GR tenants)}
        {--limit=0 : Max suppliers to process per tenant (0 = no limit)}';

    protected $description = 'Fill missing supplier names (ΑΦΜ-only «αδέσποτοι») from the GSIS registry.';

    public function handle(): int
    {
        $tenants = $this->resolveTenants();
        if ($tenants->isEmpty()) {
            $this->warn('No matching GR tenant.');

            return self::SUCCESS;
        }

        foreach ($tenants as $tenant) {
            $this->backfillTenant($tenant);
        }

        return self::SUCCESS;
    }

    private function backfillTenant(Company $tenant): void
    {
        $limit = max(0, (int) $this->option('limit'));

        // Nameless GR suppliers with an ΑΦΜ. Scope by company_id explicitly —
        // Supplier's global scope is a no-op on the CLI. withTrashed is skipped:
        // a soft-deleted supplier was removed on purpose, don't resurrect its name.
        $query = Supplier::query()
            ->where('company_id', $tenant->getKey())
            ->where(fn ($q) => $q->where('country', 'GR')->orWhereNull('country'))
            ->whereNotNull('afm')
            ->where('afm', '!=', '')
            ->where(fn ($q) => $q->whereNull('name')->orWhere('name', ''))
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $suppliers = $query->get();
        if ($suppliers->isEmpty()) {
            $this->line("• {$tenant->slug}: καμία ανώνυμη εγγραφή προμηθευτή.");

            return;
        }

        $enricher = new SupplierGsisEnricher($tenant);
        $enriched = 0;
        $failed = [];

        foreach ($suppliers as $supplier) {
            $gsis = $enricher->enrich((string) $supplier->afm);
            if ($gsis === null) {
                $failed[] = $supplier->afm;

                continue;
            }

            // Fill only-empty columns — never clobber an operator's manual edit.
            $changed = false;
            foreach ($gsis as $column => $value) {
                if (blank($supplier->{$column})) {
                    $supplier->{$column} = $value;
                    $changed = true;
                }
            }

            if ($changed) {
                $supplier->save();
                $enriched++;
            }
        }

        $this->info("✓ {$tenant->slug}: συμπληρώθηκαν {$enriched}/{$suppliers->count()} προμηθευτές από ΑΑΔΕ.");

        if ($failed !== []) {
            $this->warn('  ↳ χωρίς όνομα (απέτυχε η άντληση GSIS): '.implode(', ', $failed));
        }
    }

    /**
     * @return Collection<int, Company>
     */
    private function resolveTenants(): Collection
    {
        if ($arg = $this->option('tenant')) {
            $tenant = Company::findBySlugOrId($arg);

            return $tenant ? collect([$tenant]) : collect();
        }

        return Company::query()->where('country_code', 'GR')->get();
    }
}
