<?php

namespace App\Console\Commands;

use App\Enums\ServiceContractStatus;
use App\Models\Company;
use App\Models\ServiceContract;
use App\Services\Services\ServiceDunning;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PR-D — recurring-services dunning sweep.
 *
 *   php artisan services:run-dunning [--tenant=SLUG] [--dry-run]
 *
 * For every Active/Suspended contract in each tenant, asks ServiceDunning to
 * suspend (overdue past the suspend threshold), terminate (overdue past the
 * terminate threshold) or unsuspend (a suspended contract whose renewal was
 * paid). DESTRUCTIVE, so it's double-guarded:
 *   - the scheduler flag is ON by default ("plugged in"), BUT
 *   - the REAL control is per-product `dunning_enabled` (default OFF) — a fresh
 *     deploy acts on NOTHING until an operator opts a product in.
 *
 * Safety / robustness (mirrors services:stage-renewals):
 *   - Tenant-safe: every query is explicitly scoped by company_id (no
 *     BelongsToTenant in CLI; the global CompanyScope no-ops here).
 *   - Per-contract try/catch: a single bad contract is logged + skipped, the
 *     batch continues.
 *   - --dry-run is GENUINELY read-only: it calls ServiceDunning::wouldDo()
 *     (no persist, no provisioning call), only reports.
 *   - Loud audit: Log::info per real action (who=System, contract id, action).
 *
 * Inert until the scheduler + a queue worker are live (see CLAUDE.md Env-prep).
 *
 * Exit codes:
 *   0 success (incl. nothing to do)
 *   2 invalid usage (--tenant slug unknown)
 */
class RunServiceDunning extends Command
{
    protected $signature = 'services:run-dunning
        {--tenant= : Limit to one Company slug (default: every tenant).}
        {--dry-run : Report what WOULD happen; change nothing.}';

    protected $description = 'Recurring services: auto suspend/terminate contracts whose renewals are overdue (or unsuspend a paid one), per tenant. Gated by the per-product dunning_enabled toggle (default OFF).';

    public function handle(ServiceDunning $dunning): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $slug = (string) $this->option('tenant');

        $tenants = $this->resolveTenants($slug);
        if ($tenants === null) {
            return self::INVALID;
        }

        if ($tenants->isEmpty()) {
            $this->info('No tenants to process. Nothing to do.');

            return self::SUCCESS;
        }

        $asOf = Carbon::today();

        $tally = ['suspended' => 0, 'terminated' => 0, 'unsuspended' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($tenants as $tenant) {
            $this->processTenant($tenant, $dunning, $asOf, $dryRun, $tally);
        }

        $this->newLine();
        $verb = $dryRun ? 'would' : 'did';
        $this->info("Done ({$verb} act). "
            ."suspended={$tally['suspended']}, terminated={$tally['terminated']}, "
            ."unsuspended={$tally['unsuspended']}, no-op={$tally['skipped']}"
            .($tally['errors'] > 0 ? ", errors={$tally['errors']} (see log)" : '')
            .'.');

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Company>|null null on an unknown --tenant slug.
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
     * @param  array<string,int>  $tally  (mutated by reference)
     */
    private function processTenant(Company $tenant, ServiceDunning $dunning, Carbon $asOf, bool $dryRun, array &$tally): void
    {
        $this->info("Tenant: {$tenant->name} (slug={$tenant->slug})");

        // Only Active/Suspended contracts can dun (pending/cancelled/terminated
        // are out of scope). Explicit company_id — no BelongsToTenant in CLI.
        $contracts = ServiceContract::query()
            ->where('company_id', $tenant->id)
            ->whereIn('status', [
                ServiceContractStatus::Active->value,
                ServiceContractStatus::Suspended->value,
            ])
            ->with(['product', 'customer:id,name'])
            ->orderBy('id')
            ->get();

        if ($contracts->isEmpty()) {
            $this->line('  No active/suspended contracts.');

            return;
        }

        foreach ($contracts as $contract) {
            $label = "#{$contract->id} {$contract->customer?->name} — ".($contract->description ?: 'υπηρεσία');

            try {
                $action = $dryRun
                    ? $dunning->wouldDo($contract, $asOf)
                    : $dunning->evaluate($contract, $asOf);

                if ($action === null) {
                    $tally['skipped']++;

                    continue;
                }

                $tally[$action] = ($tally[$action] ?? 0) + 1;
                $prefix = $dryRun ? '  · would' : '  ✓';
                $this->line("{$prefix} {$action}: {$label}");
            } catch (Throwable $e) {
                $tally['errors']++;
                $this->warn("  ✗ {$label}: {$e->getMessage()} — skipped.");
                Log::error('services:run-dunning failed on a contract (skipped)', [
                    'company_id' => $tenant->id,
                    'slug' => $tenant->slug,
                    'service_contract_id' => $contract->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
