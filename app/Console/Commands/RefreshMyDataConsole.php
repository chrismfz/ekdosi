<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\MyData\MyDataConsoleRefresh;
use App\Services\MyData\RefreshStep;
use App\Support\Tenancy\CompanyContext;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Warms every «Κονσόλα myDATA» snapshot (Πωλήσεις / Έξοδα / Ε3 / Εικόνα ΦΠΑ) for
 * the CURRENT quarter, per myDATA-readable tenant — the scheduled counterpart of
 * the console's «Ανανέωση όλων» button. The console pages read the cached
 * snapshot on mount, so this keeps them fresh without the operator clicking.
 *
 * Reuses MyDataConsoleRefresh::refreshAll(), which already runs the four AADE
 * pulls SEQUENTIALLY (rate-limit friendly) and is resilient per step — a 429 /
 * missing-creds on one tab is recorded as a warn and the others still run. So a
 * partial result is normal, not a failure: we only return FAILURE when a step
 * errors HARD (connection fault), so the scheduler health surfaces it.
 *
 * READ-ONLY: seeds caches only, creates no rows. The window mirrors the console's
 * own default (current quarter → today) so the operator sees the same span.
 *
 * Usage:
 *   php artisan mydata:refresh-console                 # all myDATA-readable tenants
 *   php artisan mydata:refresh-console --tenant=myip   # one tenant
 */
class RefreshMyDataConsole extends Command
{
    protected $signature = 'mydata:refresh-console
        {--tenant= : Company slug or id (default: all myDATA-readable)}
        {--gap=3 : Seconds to wait between tenants (spacing to avoid the rate limit)}';

    protected $description = 'Warm the Κονσόλα myDATA snapshots (Πωλήσεις/Έξοδα/Ε3/Εικόνα ΦΠΑ) for the current quarter per tenant.';

    /** Test seam: a MockHandler threaded into the refresh service (no network). */
    public static ?MockHandler $testHandler = null;

    public function handle(): int
    {
        $tenants = $this->resolveTenants();
        if ($tenants->isEmpty()) {
            $this->warn('No matching myDATA-readable tenant (gr-mydata or gr-provider).');

            return self::SUCCESS;
        }

        // Mirror the console's default window (current quarter → today) so the
        // cached span matches what the operator would pick.
        $from = now()->startOfQuarter();
        $to = now();

        $service = new MyDataConsoleRefresh(static::$testHandler);
        $hadError = false;
        $gap = max(0, (int) $this->option('gap'));
        $last = $tenants->count() - 1;

        foreach ($tenants->values() as $i => $tenant) {
            try {
                // actAs declares tenant intent for any ambient-scoped read inside
                // the snapshot builders (per CLAUDE.md CLI/queue rule), even though
                // refreshAll() threads the explicit $tenant throughout.
                $steps = app(CompanyContext::class)->actAs($tenant, fn (): array => $service->refreshAll($tenant, $from->copy(), $to->copy()));

                if ($this->stepsHardErrored($steps)) {
                    $hadError = true;
                }
                $this->line($this->summarise($tenant, $steps));
            } catch (Throwable $e) {
                // refreshAll() swallows per-step failures, so reaching here is an
                // unexpected fault (e.g. credential resolution) → report, continue.
                $hadError = true;
                $this->error("✗ {$tenant->slug}: {$e->getMessage()}");
            }

            // Space out tenants so we don't trip the AADE rate limit on the next.
            if ($gap > 0 && $i < $last) {
                $this->sleep($gap);
            }
        }

        return $hadError ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<RefreshStep>  $steps
     */
    private function stepsHardErrored(array $steps): bool
    {
        foreach ($steps as $step) {
            if ($step->status === 'error') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<RefreshStep>  $steps
     */
    private function summarise(Company $tenant, array $steps): string
    {
        $ok = $warn = $err = 0;
        $notes = [];
        foreach ($steps as $step) {
            match ($step->status) {
                'ok' => $ok++,
                'warn' => $warn++,
                default => $err++,
            };
            if ($step->status !== 'ok') {
                $notes[] = $step->label.($step->note ? " ({$step->note})" : '');
            }
        }

        $glyph = $err > 0 ? '✗' : ($warn > 0 ? '•' : '✓');
        $line = "{$glyph} {$tenant->slug}: {$ok} ok";
        if ($warn > 0) {
            $line .= ", {$warn} warn";
        }
        if ($err > 0) {
            $line .= ", {$err} error";
        }
        if ($notes !== []) {
            $line .= ' — '.implode('; ', $notes);
        }

        return $line;
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
    private function resolveTenants(): Collection
    {
        if ($arg = $this->option('tenant')) {
            $tenant = Company::findBySlugOrId($arg);

            return $tenant ? collect([$tenant]) : collect();
        }

        // Both direct-myDATA and provider tenants read their own AADE console.
        return Company::myDataReadable();
    }
}
