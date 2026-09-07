<?php

namespace App\Console\Commands;

use App\Actions\StageServiceRenewal;
use App\Models\Company;
use App\Models\Domain;
use App\Models\ServiceContract;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PR-C — recurring-services renewal sweep.
 *
 *   php artisan services:stage-renewals [--tenant=SLUG] [--dry-run] [--lead-days=N]
 *
 * Stages a DRAFT renewal invoice for every Active contract whose next_due_date
 * has arrived (scopeDue), once per tenant. Operator-gated: this only STAGES
 * drafts (via StageServiceRenewal), it NEVER files at AADE — the drafts flow
 * through the normal invoice lifecycle (Οριστικοποίηση → Υποβολή στο myDATA)
 * so a human reviews every legally-significant document. Default OFF on the
 * scheduler (it creates real draft documents).
 *
 * Safety / robustness:
 *   - Tenant-safe: every query is explicitly scoped by company_id (the models
 *     deliberately do NOT rely on Filament's BelongsToTenant — this runs in CLI
 *     with no panel tenant context), so the global CompanyScope no-ops here.
 *   - A contract without an invoice_type_id would make StageServiceRenewal
 *     THROW — those are counted as "skipped (no type)", warned, and never
 *     reach the action, so one mis-configured contract can't abort the run.
 *   - The action is called inside a per-contract try/catch: a single bad
 *     contract is logged and skipped, the batch continues.
 *   - Idempotent: StageServiceRenewal advances the cursor + guards against a
 *     second open draft, so a re-run in the same period stages nothing.
 *   - --lead-days=N also stages contracts due within the next N days (early
 *     billing); 0 (default) = only those already due.
 *
 * Inert until the scheduler + a queue worker are live (see CLAUDE.md Env-prep).
 *
 * Exit codes:
 *   0 success (incl. nothing to do)
 *   2 invalid usage (--tenant slug unknown)
 */
class StageServiceRenewals extends Command
{
    protected $signature = 'services:stage-renewals
        {--tenant= : Limit to one Company slug (default: every tenant).}
        {--dry-run : List what WOULD be staged; create nothing.}
        {--lead-days=0 : Also stage contracts due within N days (early billing).}';

    protected $description = 'Recurring services: stage DRAFT renewal invoices for due service contracts, per tenant. Never files at AADE — drafts go through the normal lifecycle.';

    public function handle(StageServiceRenewal $stage): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $slug = (string) $this->option('tenant');
        $leadDays = max(0, (int) $this->option('lead-days'));

        $tenants = $this->resolveTenants($slug);
        if ($tenants === null) {
            return self::INVALID;
        }

        if ($tenants->isEmpty()) {
            $this->info('No tenants to process. Nothing to do.');

            return self::SUCCESS;
        }

        // $asOf is the renewal cut-off: today + lead days. The action's cursor
        // check uses the SAME value, so a contract due within the lead window
        // is treated as due and the cursor advances past $asOf.
        $asOf = Carbon::today()->addDays($leadDays);

        $totalStaged = 0;
        $totalSkipped = 0;
        $totalErrors = 0;

        foreach ($tenants as $tenant) {
            [$staged, $skipped, $errors] = $this->processTenant($tenant, $stage, $asOf, $dryRun);
            $totalStaged += $staged;
            $totalSkipped += $skipped;
            $totalErrors += $errors;
        }

        $verb = $dryRun ? 'would stage' : 'staged';
        $this->newLine();
        $this->info("Done. {$verb} {$totalStaged} renewal draft(s)"
            .($totalSkipped > 0 ? "; skipped {$totalSkipped} (no invoice type)" : '')
            .($totalErrors > 0 ? "; {$totalErrors} error(s) — see log" : '')
            .'.');

        return self::SUCCESS;
    }

    /**
     * Resolve the tenants to process. Returns null on an unknown --tenant slug
     * (caller maps that to exit 2).
     *
     * @return Collection<int, Company>|null
     */
    private function resolveTenants(string $slug): ?Collection
    {
        if ($slug !== '') {
            $tenant = Company::query()->where('slug', $slug)->first();
            if ($tenant === null) {
                $this->error("No tenant with slug='{$slug}'.");

                return null;
            }

            return collect([$tenant]);
        }

        return Company::query()->orderBy('id')->get();
    }

    /**
     * @return array{0:int,1:int,2:int} [staged, skipped(no type), errors]
     */
    private function processTenant(Company $tenant, StageServiceRenewal $stage, Carbon $asOf, bool $dryRun): array
    {
        $this->info("Tenant: {$tenant->name} (slug={$tenant->slug})");

        // Explicit company_id scope — no BelongsToTenant in CLI. scopeDue adds
        // Active + next_due_date <= $asOf.
        $due = ServiceContract::query()
            ->where('company_id', $tenant->id)
            ->due($asOf)
            ->with(['customer:id,name'])
            ->orderBy('next_due_date')
            ->orderBy('id')
            ->get();

        if ($due->isEmpty()) {
            $this->line('  No contracts due.');

            return [0, 0, 0];
        }

        $staged = 0;
        $skipped = 0;
        $errors = 0;

        // Domains pillar guard: a contract that IS a domain's 1:1 renewal clock
        // must not stage while the domain is dead (transferred_away/cancelled/
        // deleted — money-wrong direction: billing a name the tenant no longer
        // holds). One query, keyed by contract. docs/domains/README.md §6.
        $domainsByContract = Domain::query()
            ->where('company_id', $tenant->id)
            ->whereIn('service_contract_id', $due->pluck('id'))
            ->get()
            ->keyBy('service_contract_id');

        foreach ($due as $contract) {
            $label = "#{$contract->id} {$contract->customer?->name} — ".($contract->description ?: 'υπηρεσία');

            $domain = $domainsByContract->get($contract->id);
            if ($domain !== null && ! $domain->status->isRenewable()) {
                $skipped++;
                $this->warn("  · {$label}: το domain {$domain->fqdn} είναι «{$domain->status->getLabel()}» — δεν χρεώνουμε, skipped.");
                Log::warning('services:stage-renewals skipped a contract — domain not renewable', [
                    'company_id' => $tenant->id,
                    'slug' => $tenant->slug,
                    'service_contract_id' => $contract->id,
                    'domain' => $domain->fqdn,
                    'domain_status' => $domain->status->value,
                ]);

                continue;
            }

            // A contract without a renewal type would make the action throw.
            // Count + warn, never hand it to the action.
            if ($contract->invoice_type_id === null) {
                $skipped++;
                $this->warn("  · {$label}: χωρίς τύπο παραστατικού — skipped.");
                Log::warning('services:stage-renewals skipped a contract — no invoice type', [
                    'company_id' => $tenant->id,
                    'slug' => $tenant->slug,
                    'service_contract_id' => $contract->id,
                ]);

                continue;
            }

            if ($dryRun) {
                $this->line("  · would stage {$label} (due {$contract->next_due_date?->toDateString()})");
                $staged++;

                continue;
            }

            try {
                $invoice = $stage($contract, $asOf->copy());
                if ($invoice === null) {
                    // Not actually due under the lock / an open draft already
                    // exists — idempotent no-op, not an error.
                    $this->line("  · {$label}: nothing to stage (already staged/not due).");

                    continue;
                }
                $staged++;
                $this->line("  ✓ {$label} → {$invoice->invcode}");
                Log::info('services:stage-renewals staged a renewal draft', [
                    'company_id' => $tenant->id,
                    'slug' => $tenant->slug,
                    'service_contract_id' => $contract->id,
                    'invoice_id' => $invoice->id,
                    'invcode' => $invoice->invcode,
                ]);
            } catch (Throwable $e) {
                $errors++;
                $this->warn("  ✗ {$label}: {$e->getMessage()} — skipped.");
                Log::error('services:stage-renewals failed to stage a contract (skipped)', [
                    'company_id' => $tenant->id,
                    'slug' => $tenant->slug,
                    'service_contract_id' => $contract->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [$staged, $skipped, $errors];
    }
}
