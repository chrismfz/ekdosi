<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\MyData\MyDataVatAggregator;
use App\Support\MyData\VatPictureCache;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Refreshes the cached "Εικόνα από myDATA" VAT picture (output εκροές vs input
 * εισροές, summed from the actual AADE docs) for the CURRENT month + quarter,
 * per gr-mydata tenant. The dashboard widget reads the cache only — this is the
 * heavy AADE pull, run on a scheduler (every few hours) or manually.
 *
 * Usage:
 *   php artisan mydata:refresh-vat-picture                 # all gr-mydata tenants
 *   php artisan mydata:refresh-vat-picture --tenant=myip   # one tenant
 */
class RefreshVatPicture extends Command
{
    protected $signature = 'mydata:refresh-vat-picture {--tenant= : Company slug or id (default: all gr-mydata)}';

    protected $description = 'Cache the myDATA VAT picture (εκροές−εισροές) for the current month + quarter.';

    public function handle(): int
    {
        $tenants = $this->resolveTenants();
        if ($tenants->isEmpty()) {
            $this->warn('No matching gr-mydata tenant.');

            return self::SUCCESS;
        }

        $hadError = false;

        foreach ($tenants as $tenant) {
            try {
                $this->refreshTenant($tenant);
                $this->line("✓ {$tenant->slug}");
            } catch (RuntimeException $e) {
                // Guard messages (mode off / missing creds) — expected, skip.
                $this->warn("• {$tenant->slug}: {$e->getMessage()}");
            } catch (Throwable $e) {
                $hadError = true;
                $this->error("✗ {$tenant->slug}: {$e->getMessage()}");
            }
        }

        return $hadError ? self::FAILURE : self::SUCCESS;
    }

    private function refreshTenant(Company $tenant): void
    {
        $aggregator = new MyDataVatAggregator($tenant);
        $now = now();

        VatPictureCache::put($tenant, 'month',
            $aggregator->forPeriod($now->copy()->startOfMonth(), $now->copy()->endOfMonth()));

        VatPictureCache::put($tenant, 'quarter',
            $aggregator->forPeriod($now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()));
    }

    /**
     * @return \Illuminate\Support\Collection<int, Company>
     */
    private function resolveTenants()
    {
        if ($arg = $this->option('tenant')) {
            $tenant = Company::findBySlugOrId($arg);

            return $tenant ? collect([$tenant]) : collect();
        }

        return Company::query()
            ->where('einvoice_provider', 'gr-mydata')
            ->where('mydata_mode', '!=', 'off')
            ->get();
    }
}
