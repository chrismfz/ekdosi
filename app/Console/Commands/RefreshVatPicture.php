<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\MyData\MyDataVatAggregator;
use App\Support\MyData\VatPictureCache;
use Firebed\AadeMyData\Exceptions\RateLimitExceededException;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
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
    protected $signature = 'mydata:refresh-vat-picture
        {--tenant= : Company slug or id (default: all gr-mydata)}
        {--max-retries=3 : Times to retry a tenant after an AADE 429 (capped wait)}
        {--gap=2 : Seconds to wait between tenants (spacing to avoid the rate limit)}';

    protected $description = 'Cache the myDATA VAT picture (εκροές−εισροές) for the current month + quarter.';

    /** Hard cap on how long we'll honour a single AADE "try again in N" hint. */
    private const MAX_BACKOFF_SECONDS = 180;

    public function handle(): int
    {
        $tenants = $this->resolveTenants();
        if ($tenants->isEmpty()) {
            $this->warn('No matching myDATA-readable tenant (gr-mydata or gr-provider).');

            return self::SUCCESS;
        }

        $hadError = false;
        $gap = max(0, (int) $this->option('gap'));
        $last = $tenants->count() - 1;

        foreach ($tenants->values() as $i => $tenant) {
            try {
                $this->refreshTenantWithRetry($tenant);
                $this->line("✓ {$tenant->slug}");
            } catch (RateLimitExceededException $e) {
                // Out of retries — not a hard failure (transient), just report.
                $this->warn("• {$tenant->slug}: rate-limited, skipped this run ({$e->getMessage()})");
            } catch (RuntimeException $e) {
                // Guard messages (mode off / missing creds) — expected, skip.
                $this->warn("• {$tenant->slug}: {$e->getMessage()}");
            } catch (Throwable $e) {
                $hadError = true;
                $this->error("✗ {$tenant->slug}: {$e->getMessage()}");
            }

            // Space out tenants so we don't trip the rate limit on the next one.
            if ($gap > 0 && $i < $last) {
                $this->sleep($gap);
            }
        }

        return $hadError ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Refresh one tenant, retrying on AADE 429 by honouring the "try again in N
     * seconds" hint (capped). Rethrows the last RateLimitExceededException when
     * the retry budget is exhausted.
     */
    private function refreshTenantWithRetry(Company $tenant): void
    {
        $maxRetries = max(0, (int) $this->option('max-retries'));

        for ($attempt = 0; ; $attempt++) {
            try {
                $this->refreshTenant($tenant);

                return;
            } catch (RateLimitExceededException $e) {
                if ($attempt >= $maxRetries) {
                    throw $e;
                }
                $wait = $this->backoffSeconds($e->getMessage(), $attempt);
                $this->line("  … {$tenant->slug}: 429, waiting {$wait}s (retry ".($attempt + 1)."/{$maxRetries})");
                $this->sleep($wait);
            }
        }
    }

    /**
     * How long to wait before a retry: the AADE-suggested seconds when present
     * (capped at MAX_BACKOFF_SECONDS), else exponential backoff 5·2^n.
     */
    private function backoffSeconds(string $message, int $attempt): int
    {
        if (preg_match('/(\d+)\s*second/i', $message, $m)) {
            return min((int) $m[1] + 1, self::MAX_BACKOFF_SECONDS);
        }

        return min(5 * (2 ** $attempt), self::MAX_BACKOFF_SECONDS);
    }

    /** Wrapped so tests can run without real sleeping. */
    protected function sleep(int $seconds): void
    {
        if ($seconds > 0 && ! app()->runningUnitTests()) {
            sleep($seconds);
        }
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
     * @return Collection<int, Company>
     */
    private function resolveTenants()
    {
        if ($arg = $this->option('tenant')) {
            $tenant = Company::findBySlugOrId($arg);

            return $tenant ? collect([$tenant]) : collect();
        }

        // Both direct-myDATA and provider tenants read their own AADE picture
        // (a provider only changes who SUBMITS). Shared gate so the scheduler set
        // matches the dashboard widget exactly.
        return Company::myDataReadable();
    }
}
