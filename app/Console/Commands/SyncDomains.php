<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesDomainCompanies;
use App\Models\Domain;
use App\Services\Domains\DomainSyncService;
use Illuminate\Console\Command;

/**
 * domains:sync (Πυλώνας A / A2b) — pull the REGISTRAR truth (expiry/status/NS)
 * for every syncable domain of domain-enabled tenants. READ-ONLY at the
 * registrar; local writes = the domains sync columns. Scheduler-gated
 * (EKDOSI_SCHEDULE_DOMAIN_SYNC, default OFF); safe to run manually anytime.
 *
 * CLI tenancy rule (CLAUDE.md): explicit ->where('company_id', …) everywhere —
 * this command never relies on the ambient scope.
 */
class SyncDomains extends Command
{
    use ResolvesDomainCompanies;

    protected $signature = 'domains:sync
        {--tenant= : Slug ή id εταιρείας (κενό = όλες οι domain-enabled)}
        {--limit=0 : Μέγιστα domains ανά tenant (0 = όλα)}';

    protected $description = 'Συγχρονισμός domains από τους registrars (λήξη/κατάσταση/NS) — read-only στον registrar';

    public function handle(DomainSyncService $sync): int
    {
        $companies = $this->domainCompanies();
        if ($companies === []) {
            // An EXPLICIT --tenant that resolves to nothing is an error (typo,
            // or the pillar is off) — monitoring keyed on the exit code must
            // notice a sync that silently did nothing.
            if ((string) ($this->option('tenant') ?? '') !== '') {
                return self::FAILURE;
            }
            $this->info('Καμία εταιρεία με ενεργή διαχείριση domains.');

            return self::SUCCESS;
        }

        $failures = 0;
        foreach ($companies as $company) {
            $limit = (int) $this->option('limit');
            $synced = 0;
            $skipped = 0;
            $attempted = 0;
            $domains = Domain::query()
                ->where('company_id', $company->id)
                ->whereNull('deleted_at')
                ->with(['registrarConnection', 'tldRule.registrarConnection'])
                ->orderBy('id')
                ->get();
            foreach ($domains as $domain) {
                if (! $sync->isSyncable($domain)) {
                    $skipped++;

                    continue;
                }
                // --limit budgets SYNCABLE attempts (a run of manual-routed rows
                // must not starve the ones the flag exists to bound).
                if ($limit > 0 && $attempted >= $limit) {
                    break;
                }
                $attempted++;
                try {
                    $sync->sync($domain);
                    $synced++;
                } catch (\Throwable $e) {
                    // Recorded on the row by the service; count + keep going —
                    // one broken domain must not stall the whole tenant.
                    $failures++;
                    $this->warn("  ✗ {$domain->fqdn}: ".$e->getMessage());
                }
            }

            $this->info("{$company->slug}: {$synced} synced, {$skipped} skipped (manual/ανενεργή σύνδεση/ακυρωμένα), σφάλματα ως τώρα: {$failures}");
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
