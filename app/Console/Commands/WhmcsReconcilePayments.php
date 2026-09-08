<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Whmcs\WhmcsInvoiceFetcher;
use App\Services\Whmcs\WhmcsPaymentReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * WHMCS bridge — payment-sync DETECTION (read-only).
 *
 * Recomputes each tenant's worklist of open «επί πιστώσει» invoices that WHMCS
 * now reports Paid, caches it for the dashboard widget + console page, and
 * bell-notifies new items. It writes NO money — the operator closes each
 * receivable with the per-invoice «Έχει πληρωθεί;» / «Καταγραφή πληρωμής»
 * action (which re-checks WHMCS live). Poll-based; safe to run anytime.
 */
class WhmcsReconcilePayments extends Command
{
    protected $signature = 'whmcs:reconcile-payments {--tenant= : Limit to one Company slug (default: every WHMCS-configured tenant).}';

    protected $description = 'WHMCS bridge (read-only): detect open credit-term invoices now paid in WHMCS and cache the worklist for the dashboard/console.';

    public function handle(
        WhmcsInvoiceFetcher $fetcher,
        WhmcsPaymentReconciler $reconciler,
    ): int {
        $slug = (string) $this->option('tenant');

        $tenants = $this->resolveTenants($slug);
        if ($tenants === null) {
            $this->error("No tenant with slug='{$slug}'.");

            return self::INVALID;
        }
        if ($tenants->isEmpty()) {
            $this->info('No WHMCS-configured tenants. Nothing to do.');

            return self::SUCCESS;
        }

        $totalInbound = 0;

        foreach ($tenants as $tenant) {
            $fetch = $fetcher->for($tenant);
            if ($fetch === null) {
                $this->warn("{$tenant->slug}: WHMCS not configured — skipped.");

                continue;
            }

            try {
                $inbound = $reconciler->reconcile($tenant, $fetch);
            } catch (Throwable $e) {
                $this->warn("{$tenant->slug}: {$e->getMessage()}");

                continue;
            }

            if ($inbound !== []) {
                $this->info("{$tenant->slug}: ".count($inbound).' invoice(s) paid at WHMCS awaiting recording.');
            }
            $totalInbound += count($inbound);
        }

        $this->newLine();
        $this->info("Done. {$totalInbound} invoice(s) paid at WHMCS across all tenants.");

        return self::SUCCESS;
    }

    /**
     * All WHMCS-configured tenants, or the single --tenant slug. Returns null
     * only when an explicit --tenant slug is unknown (caller → exit 2).
     *
     * @return Collection<int, Company>|null
     */
    private function resolveTenants(string $slug): ?Collection
    {
        if ($slug !== '') {
            $tenant = Company::query()->where('slug', $slug)->first();
            if ($tenant === null) {
                return null;
            }

            return collect($tenant->hasWhmcsIntegration() ? [$tenant] : []);
        }

        return Company::query()
            ->get()
            ->filter(fn (Company $c) => $c->hasWhmcsIntegration())
            ->values();
    }
}
