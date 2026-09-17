<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\Dashboard\DashboardMetricsCache;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Pre-builds the cached «Αναφορές» metric slices (DashboardMetricsCache) per
 * tenant, for the CURRENT + PREVIOUS year, so the nine report widgets read a
 * warm cache instead of each re-running its aggregates on page open (~25s cold).
 *
 * The house pattern (same as `mydata:refresh-vat-picture`): the widgets only
 * READ a fresh-enough cache; this scheduler task keeps it warm. Pure DB,
 * READ-ONLY — creates no rows. Force-rebuilds each slice so a run picks up
 * newly-issued documents, overwriting in place (no version bump → no cold
 * window on the web while it rebuilds).
 *
 * Usage:
 *   php artisan dashboard:warm-metrics                # all tenants
 *   php artisan dashboard:warm-metrics --tenant=myip  # one tenant
 */
class WarmDashboardMetrics extends Command
{
    protected $signature = 'dashboard:warm-metrics
        {--tenant= : Company slug or id (default: all)}';

    protected $description = 'Warm the cached «Αναφορές» dashboard metrics (current + previous year) per tenant.';

    public function handle(): int
    {
        $tenants = $this->resolveTenants();
        if ($tenants->isEmpty()) {
            $this->warn('No matching tenant.');

            return self::SUCCESS;
        }

        $currentYear = (int) Carbon::now()->year;
        $years = [$currentYear, $currentYear - 1];
        $hadError = false;

        foreach ($tenants as $tenant) {
            try {
                $cache = DashboardMetricsCache::for($tenant);
                foreach ($years as $year) {
                    $cache->warm($year);
                }
                // Now-anchored, not year-scoped — warm once per tenant.
                $cache->projectNextYear(historyYears: 3, fresh: true);

                $this->line("✓ {$tenant->slug}");
            } catch (Throwable $e) {
                $hadError = true;
                $this->error("✗ {$tenant->slug}: {$e->getMessage()}");
            }
        }

        return $hadError ? self::FAILURE : self::SUCCESS;
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

        return Company::query()->get();
    }
}
