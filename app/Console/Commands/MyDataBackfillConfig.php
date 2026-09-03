<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\MyData\ConfigBackfiller;
use App\Support\MyData\VatExemptionGuidance;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Bring an IMPORTED (legacy-ETL) tenant up to the fresh-setup myDATA config
 * defaults — the config gap that leaves a migrated tenant failing/warning the
 * preflight even though a freshly-seeded one passes. See {@see ConfigBackfiller}.
 *
 * DRY-RUN by default (prints what it WOULD change); pass --execute to write.
 * The §8.3 exemption default (a legal code) is written only with --exemption-default.
 * Idempotent: only NULL rows are touched, so a second run is a no-op. Scope is
 * AADE-filing tenants (gr-mydata / gr-provider) — §8.3/§8.12 are Greek AADE codes.
 *
 * Usage:
 *   php artisan mydata:backfill-config                    # dry-run, all AADE tenants
 *   php artisan mydata:backfill-config --tenant=myip      # dry-run, one tenant
 *   php artisan mydata:backfill-config --tenant=myip --execute
 *   php artisan mydata:backfill-config --tenant=myip --execute --exemption-default
 */
class MyDataBackfillConfig extends Command
{
    protected $signature = 'mydata:backfill-config
        {--tenant= : Company slug or id (default: all AADE-filing tenants)}
        {--execute : Apply the changes (default is a read-only dry-run)}
        {--exemption-default : ALSO write the fresh-setup §8.3 default to a single reason-less 0% category (a legal code — opt-in)}';

    protected $description = 'Backfill imported tenants\' §8.3 exemption reason + §8.12 payment type to the fresh-setup defaults (dry-run unless --execute).';

    public function handle(ConfigBackfiller $backfiller): int
    {
        $companies = $this->resolveCompanies();
        if ($companies === null) {
            return self::FAILURE;
        }
        if ($companies->isEmpty()) {
            $this->warn('No matching AADE-filing tenants (einvoice_provider gr-mydata / gr-provider).');

            return self::SUCCESS;
        }

        $execute = (bool) $this->option('execute');
        $writeExemption = (bool) $this->option('exemption-default');
        $appliedExemption = $execute && $writeExemption;
        $this->line($execute
            ? '<fg=yellow>EXECUTE — writing changes.</>'
            : 'DRY-RUN — nothing is written. Re-run with --execute to apply.');

        $payDone = 0;       // §8.12 written (or would be) on --execute
        $vatDone = 0;       // §8.3 written on --execute --exemption-default
        $vatPending = 0;    // §8.3 single candidate NOT written (needs --exemption-default)
        $vatAmbiguous = 0;  // 2+ reason-less 0% categories → operator decides
        $payUnmatched = 0;  // payment methods no keyword matched

        foreach ($companies as $company) {
            $plan = $execute ? $backfiller->apply($company, $writeExemption) : $backfiller->plan($company);

            if ($this->planIsEmpty($plan)) {
                continue;
            }

            $this->newLine();
            $this->line(str_repeat('═', 56));
            $this->line("Tenant: {$company->name} (#{$company->id})");

            if ($plan['vat'] !== []) {
                $this->newLine();
                $this->line($appliedExemption
                    ? '§8.3 αιτία απαλλαγής — 0% κατηγορία χωρίς λόγο (ΕΓΓΡΑΦΗ — προεπιλογή, επιβεβαίωσέ την):'
                    : '<fg=yellow>§8.3 αιτία απαλλαγής — 0% κατηγορία χωρίς λόγο (ΠΡΟΤΕΙΝΟΜΕΝΗ προεπιλογή· --exemption-default για εγγραφή, ή όρισέ την στη φόρμα):</>');
                foreach ($plan['vat'] as $row) {
                    $label = VatExemptionGuidance::labelForCode($row['to']);
                    $arrow = $appliedExemption ? '<fg=green>→</>' : '<fg=yellow>?</>';
                    $this->line("  {$arrow} «{$row['label']}» → κωδ. {$row['to']} — {$label}");
                    $appliedExemption ? $vatDone++ : $vatPending++;
                }
            }

            if ($plan['vat_ambiguous'] !== []) {
                $this->newLine();
                $this->line('<fg=yellow>§8.3 — πολλαπλές 0% κατηγορίες: όρισε τον λόγο ανά κατηγορία (δεν μαντεύω):</>');
                foreach ($plan['vat_ambiguous'] as $row) {
                    $this->line("  <fg=yellow>?</> «{$row['label']}» (#{$row['id']})");
                    $vatAmbiguous++;
                }
            }

            if ($plan['payments'] !== []) {
                $this->newLine();
                $this->line('§8.12 τύπος πληρωμής — προτεινόμενη αντιστοίχιση:');
                foreach ($plan['payments'] as $row) {
                    $this->line("  <fg=green>→</> «{$row['description']}» → τύπος {$row['to']} (match: «{$row['keyword']}»)");
                    $payDone++;
                }
            }

            if ($plan['payments_unmatched'] !== []) {
                $this->newLine();
                $this->line('<fg=yellow>Τρόποι πληρωμής χωρίς αντιστοίχιση — όρισέ τους χειροκίνητα (§8.12):</>');
                foreach ($plan['payments_unmatched'] as $row) {
                    $this->line("  <fg=yellow>?</> «{$row['description']}» (#{$row['id']})");
                    $payUnmatched++;
                }
            }
        }

        $this->newLine();
        $this->line(str_repeat('─', 56));
        $verb = $execute ? 'Έγραψα' : 'Θα έγραφα';
        $this->line("{$verb}: {$payDone} τύπο(-ους) πληρωμής (§8.12)"
            .($appliedExemption ? ", {$vatDone} αιτία(-ες) απαλλαγής (§8.3)" : '').'.');
        $this->line("Χρειάζονται χειροκίνητα: {$vatAmbiguous} κατηγορ. ΦΠΑ (ασαφείς), {$payUnmatched} τρόποι πληρωμής.");

        if (! $execute && $payDone > 0) {
            $this->info('Τρέξε ξανά με --execute για να εφαρμοστούν οι τύποι πληρωμής.');
        }
        if ($vatPending > 0) {
            $this->info("§8.3: {$vatPending} κατηγορία(-ες) 0% με προτεινόμενη προεπιλογή — πρόσθεσε --exemption-default για εγγραφή (ή όρισέ την στη φόρμα).");
        }

        return self::SUCCESS;
    }

    /** @param array{vat:array, vat_ambiguous:array, payments:array, payments_unmatched:array} $plan */
    private function planIsEmpty(array $plan): bool
    {
        return $plan['vat'] === []
            && $plan['vat_ambiguous'] === []
            && $plan['payments'] === []
            && $plan['payments_unmatched'] === [];
    }

    /** @return Collection<int, Company>|null */
    private function resolveCompanies()
    {
        $arg = $this->option('tenant');

        if ($arg) {
            $company = Company::query()
                ->where(fn ($q) => $q
                    ->where('slug', $arg)
                    ->orWhere('id', is_numeric($arg) ? (int) $arg : 0))
                ->first();

            if (! $company) {
                $this->error("Tenant '{$arg}' not found.");

                return null;
            }

            if (! in_array($company->einvoice_provider, ConfigBackfiller::AADE_PROVIDERS, true)) {
                // Single, specific message (return null → no generic "no tenants" warning too).
                $this->warn("Tenant '{$arg}' is not an AADE-filing tenant (einvoice_provider={$company->einvoice_provider}); §8.3/§8.12 do not apply.");

                return null;
            }

            return collect([$company]);
        }

        return Company::query()->whereIn('einvoice_provider', ConfigBackfiller::AADE_PROVIDERS)->get();
    }
}
