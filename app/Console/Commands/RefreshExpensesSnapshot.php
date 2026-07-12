<?php

namespace App\Console\Commands;

use App\Filament\Pages\MyDataConsoleExpenses;
use App\Models\Company;
use Firebed\AadeMyData\Exceptions\RateLimitExceededException;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * Read-only refresh of the expenses reconciliation snapshot (RequestDocs vs our
 * local `expenses`) for the CURRENT quarter, per myDATA-readable tenant. Writes
 * the SAME cache the «Κονσόλα myDATA — Έξοδα» page restores on mount, so opening
 * the console shows a fresh worklist and the «Άντληση» tip/badge on the Έξοδα list
 * stays current — WITHOUT creating any expense rows (import stays operator-gated).
 *
 * The expense-side twin of `mydata:refresh-vat-picture`. Scheduled (default OFF,
 * toggled from «Ρυθμίσεις χρονοπρογραμματιστή»), or run manually.
 *
 * Usage:
 *   php artisan mydata:refresh-expenses                 # all myDATA-readable tenants
 *   php artisan mydata:refresh-expenses --tenant=myip   # one tenant
 */
class RefreshExpensesSnapshot extends Command
{
    protected $signature = 'mydata:refresh-expenses
        {--tenant= : Company slug or id (default: all myDATA-readable)}
        {--auto-only : Only tenants that opted in via companies.mydata_auto_fetch_expenses (used by the scheduler)}
        {--max-retries=3 : Times to retry a tenant after an AADE 429 (capped wait)}
        {--gap=2 : Seconds to wait between tenants (spacing to avoid the rate limit)}';

    protected $description = 'Cache the myDATA expenses reconciliation snapshot for the current quarter (read-only, no import).';

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
                $orphans = $this->refreshTenantWithRetry($tenant);
                $this->line("✓ {$tenant->slug}: {$orphans} αδέσποτα");
            } catch (RateLimitExceededException $e) {
                $this->warn("• {$tenant->slug}: rate-limited, skipped this run ({$e->getMessage()})");
            } catch (RuntimeException $e) {
                // Guard messages (mode off / missing creds) — expected, skip.
                $this->warn("• {$tenant->slug}: {$e->getMessage()}");
            } catch (Throwable $e) {
                $hadError = true;
                $this->error("✗ {$tenant->slug}: {$e->getMessage()}");
            }

            if ($gap > 0 && $i < $last) {
                $this->sleep($gap);
            }
        }

        return $hadError ? self::FAILURE : self::SUCCESS;
    }

    /** @return int orphan count from the refreshed snapshot */
    private function refreshTenantWithRetry(Company $tenant): int
    {
        $maxRetries = max(0, (int) $this->option('max-retries'));

        for ($attempt = 0; ; $attempt++) {
            try {
                $now = now();
                $result = MyDataConsoleExpenses::refreshSnapshot(
                    $tenant,
                    $now->copy()->startOfQuarter(),
                    $now->copy(),
                );

                return count($result->missingLocally);
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

    /**
     * @return Collection<int, Company>
     */
    private function resolveTenants()
    {
        if ($arg = $this->option('tenant')) {
            $tenant = Company::findBySlugOrId($arg);

            return $tenant ? collect([$tenant]) : collect();
        }

        $tenants = Company::myDataReadable();

        // The scheduler passes --auto-only so the AUTOMATIC refresh touches only
        // tenants that opted in (companies.mydata_auto_fetch_expenses). A manual
        // run without the flag still refreshes every myDATA-readable tenant.
        if ($this->option('auto-only')) {
            $tenants = $tenants->filter(fn (Company $c): bool => (bool) $c->mydata_auto_fetch_expenses)->values();
        }

        return $tenants;
    }
}
