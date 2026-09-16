<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Delivery\InboundDeliveryFetcher;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Slice 4a — stage inbound ψηφιακή-διακίνηση documents (goods others are sending
 * US) into «Εισερχόμενα Διακίνησης». See docs/delivery-inbound-design.md.
 *
 * READ-ONLY: reuses the `RequestDocs` feed (docs filed against us), keeps only the
 * movement-bearing ones, and upserts them into `inbound_delivery_notes`. It NEVER
 * rejects/confirms/mutates a legal state at AADE — those are the operator-gated
 * inbox actions (Slice 4b/4c).
 *
 * Scheduled (default ON, `EKDOSI_SCHEDULE_DELIVERY_FETCH_INBOUND`), or run
 * manually. Same tenant-resolution + spacing as `mydata:refresh-expenses`.
 *
 * Resilience: a per-tenant fetch failure is a transient AADE hiccup on a
 * read-only, self-healing 6-hourly poll — it is LOGGED (warning, with the real
 * exception) but NEVER fails the run, so the scheduler's failure alert doesn't
 * fire for a blip that the next run clears.
 *
 * Usage:
 *   php artisan delivery:fetch-inbound                         # all myDATA-readable tenants
 *   php artisan delivery:fetch-inbound --tenant=myip --from=2026-09-01 --to=2026-09-30
 *   php artisan delivery:fetch-inbound --tenant=myip --dry-run  # preview, stage nothing
 */
class DeliveryFetchInbound extends Command
{
    protected $signature = 'delivery:fetch-inbound
        {--tenant= : Company slug or id (default: all myDATA-readable)}
        {--from= : Window start (Y-m-d). Default: one month ago}
        {--to= : Window end (Y-m-d). Default: today}
        {--dry-run : Count what WOULD be staged without writing}
        {--gap=2 : Seconds to wait between tenants (rate-limit spacing)}';

    protected $description = 'READ-ONLY: stage inbound delivery-movement docs (RequestDocs) into «Εισερχόμενα Διακίνησης».';

    public function handle(): int
    {
        $tenants = $this->resolveTenants();
        if ($tenants->isEmpty()) {
            $this->warn('No matching myDATA-readable tenant (gr-mydata or gr-provider).');

            return self::SUCCESS;
        }

        $from = $this->option('from') ? Carbon::parse($this->option('from'))->startOfDay() : null;
        $to = $this->option('to') ? Carbon::parse($this->option('to'))->endOfDay() : null;
        $dryRun = (bool) $this->option('dry-run');
        $gap = max(0, (int) $this->option('gap'));
        $last = $tenants->count() - 1;

        foreach ($tenants->values() as $i => $tenant) {
            try {
                $result = $this->fetcherFor($tenant)->fetch($from, $to, $dryRun);
                $prefix = $dryRun ? '[dry-run] ' : '';
                $this->line("✓ {$tenant->slug}: {$prefix}{$result->summary()}");
            } catch (RuntimeException $e) {
                // Guard messages (mode off / missing creds) — expected config
                // states, not errors. Skip quietly; nothing to log or alert on.
                $this->warn("• {$tenant->slug}: {$e->getMessage()}");
            } catch (Throwable $e) {
                // A per-tenant fetch failure — almost always a transient AADE
                // hiccup (firebed's MyDataConnection/Timeout/InvalidResponse all
                // extend \Exception, so they land HERE, not the RuntimeException
                // guard above). This poll is READ-ONLY, idempotent and runs every
                // 6h, so the failure self-heals on the next run: we must NOT fail
                // the whole scheduled task (its onFailure hook records a `failed`
                // run and the OS-cron alerts on the non-zero exit — the midnight
                // false alarm this fixes). We LOG it at warning level with the
                // real exception (the old stdout-only $this->error() left
                // laravel.log empty, so the cause was undiagnosable) and keep it
                // visible on a manual run, but the command still exits 0. A
                // persistent problem then shows up as a warning every run rather
                // than a page.
                Log::warning('delivery:fetch-inbound: tenant fetch failed (transient; staged nothing this run)', [
                    'company' => $tenant->slug,
                    'exception' => get_class($e),
                    'at' => $e->getFile().':'.$e->getLine(),
                    'message' => $e->getMessage(),
                ]);
                $this->warn("• {$tenant->slug}: {$e->getMessage()} (καταγράφηκε — θα ξαναδοκιμαστεί στην επόμενη εκτέλεση)");
            }

            if ($gap > 0 && $i < $last) {
                $this->sleep($gap);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Build the fetcher for one tenant. A tiny seam so a test can inject a
     * fetcher that throws (the transient-failure path) without a live AADE call.
     */
    protected function fetcherFor(Company $tenant): InboundDeliveryFetcher
    {
        return new InboundDeliveryFetcher($tenant);
    }

    /**
     * @return Collection<int, Company>
     */
    private function resolveTenants(): Collection
    {
        if ($arg = $this->option('tenant')) {
            $tenant = Company::findBySlugOrId($arg);
            if (! $tenant) {
                $this->error("Tenant '{$arg}' not found.");

                return collect();
            }

            return collect([$tenant]);
        }

        return Company::myDataReadable();
    }

    /** Wrapped so tests can run without real sleeping. */
    protected function sleep(int $seconds): void
    {
        if ($seconds > 0 && ! app()->runningUnitTests()) {
            sleep($seconds);
        }
    }
}
