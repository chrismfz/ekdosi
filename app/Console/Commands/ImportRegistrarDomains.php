<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesDomainCompanies;
use App\Models\DomainRegistrarConnection;
use App\Services\Domains\DomainImportService;
use Illuminate\Console\Command;

/**
 * domains:import-registrar (Πυλώνας A / A2c) — the registrar-first bootstrap
 * (README §9): pull the account's OWN portfolio + contacts from every
 * import-capable connection, land new domains ΑΔΕΣΠΟΤΑ (customer_id null)
 * with contacts as the manual-assign aid. READ-ONLY at the registrar;
 * manual-run (bootstrap + occasional re-pull — the nightly sync keeps truth
 * fresh afterwards). Re-runnable: upserts by (company_id, fqdn).
 *
 * CLI tenancy rule (CLAUDE.md): explicit ->where('company_id', …) everywhere.
 */
class ImportRegistrarDomains extends Command
{
    use ResolvesDomainCompanies;

    protected $signature = 'domains:import-registrar
        {--tenant= : Slug ή id εταιρείας (κενό = όλες οι domain-enabled)}
        {--connection= : Μόνο αυτή η σύνδεση registrar (id)}';

    protected $description = 'Registrar-first import: λίστα domains + επαφές από τους registrars → αδέσποτα domains για χειροκίνητη ανάθεση';

    public function handle(DomainImportService $import): int
    {
        $companies = $this->domainCompanies();
        if ($companies === []) {
            if ((string) ($this->option('tenant') ?? '') !== '') {
                return self::FAILURE;
            }
            $this->info('Καμία εταιρεία με ενεργή διαχείριση domains.');

            return self::SUCCESS;
        }

        $onlyConnection = (string) ($this->option('connection') ?? '');
        $failures = 0;
        $ran = 0;
        foreach ($companies as $company) {
            $connections = DomainRegistrarConnection::query()
                ->where('company_id', $company->id)
                ->when($onlyConnection !== '', fn ($q) => $q->whereKey((int) $onlyConnection))
                ->orderBy('id')
                ->get();

            foreach ($connections as $connection) {
                if (! $import->isImportable($connection)) {
                    $this->line("{$company->slug} · «{$connection->label}»: παράλειψη (manual/ανενεργή/χωρίς listing API).");

                    continue;
                }
                $ran++;
                try {
                    $counts = $import->import($company, $connection, fn (string $m) => $this->warn('  ⚠ '.$m));
                    $this->info("{$company->slug} · «{$connection->label}»: {$counts['created']} νέα (αδέσποτα), {$counts['updated']} υπάρχοντα ενημερώθηκαν, {$counts['skipped']} παραλείφθηκαν (tombstones/διαγραμμένα TLD/παγωμένα), {$counts['contacts']} επαφές.");
                } catch (\Throwable $e) {
                    // One broken connection must not stall the rest of the run.
                    $failures++;
                    $this->error("{$company->slug} · «{$connection->label}»: ✗ ".$e->getMessage());
                }
            }
        }

        // The loud-failure rule: an EXPLICIT --connection (or a run that found
        // nothing import-capable at all) must never no-op with exit 0.
        if ($ran === 0) {
            $this->error($onlyConnection !== ''
                ? "Η σύνδεση {$onlyConnection} δεν βρέθηκε ή δεν υποστηρίζει import."
                : 'Καμία σύνδεση ικανή για import (όλες manual/ανενεργές) — δεν έγινε τίποτα.');

            return self::FAILURE;
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
