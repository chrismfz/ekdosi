<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Whmcs\WhmcsInvoiceFetcher;
use App\Services\Whmcs\WhmcsPaymentSyncer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * WHMCS bridge — INBOUND payment sync (WHMCS → ekdosi).
 *
 * For a filed, WHMCS-linked ekdosi invoice that is still an OPEN receivable
 * (issued επί πιστώσει — the «τιμολόγιο πρώτα, πληρωμή μετά» case), ask WHMCS
 * whether it has since been paid; if so, record an ekdosi Payment that settles
 * the balance. Money-write in EKDOSI only — never touches the customer's WHMCS.
 *
 * Poll-based (not webhook): the plugin has no InvoicePaid hook, and polling is
 * robust to missed events + needs no plugin redeploy. Safe to run anytime; the
 * «only-if-open» + transaction_id dedup make it idempotent (see WhmcsPaymentSyncer).
 */
class WhmcsSyncPayments extends Command
{
    protected $signature = 'whmcs:sync-payments {--tenant= : Limit to one Company slug (default: every WHMCS-configured tenant).}';

    protected $description = 'WHMCS bridge (inbound): record an ekdosi Payment for a filed, still-open WHMCS-linked invoice that has since been paid in WHMCS.';

    public function handle(
        WhmcsInvoiceFetcher $fetcher,
        WhmcsPaymentSyncer $syncer,
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

        $totalRecorded = 0;
        $totalAmount = 0.0;

        foreach ($tenants as $tenant) {
            $fetch = $fetcher->for($tenant);
            if ($fetch === null) {
                $this->warn("{$tenant->slug}: WHMCS not configured — skipped.");

                continue;
            }

            try {
                $result = $syncer->syncTenant($tenant, $fetch);
            } catch (Throwable $e) {
                $this->warn("{$tenant->slug}: {$e->getMessage()}");

                continue;
            }

            if ($result->recorded > 0) {
                $this->info("{$tenant->slug}: recorded {$result->recorded} payment(s), Σ ".number_format($result->total, 2, ',', '.')." (checked {$result->checked}).");
            }
            $totalRecorded += $result->recorded;
            $totalAmount += $result->total;
        }

        $this->newLine();
        $this->info("Done. Recorded {$totalRecorded} payment(s), Σ ".number_format($totalAmount, 2, ',', '.').'.');

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
