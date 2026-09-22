<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Delivery\InboundDeliveryFetcher;
use App\Support\OperatorHealth\HealthRecorder;
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

    public function handle(HealthRecorder $recorder): int
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

        // Only the SCHEDULED sweep (all readable tenants, default window, real fetch)
        // owns the consecutive-failure streak that ops:health reads. An ad-hoc
        // `--tenant` inspection, a `--dry-run`, or a `--from/--to` backfill must not
        // reset (hide) or inflate that signal.
        $trackHealth = ! $dryRun
            && ! $this->option('tenant')
            && ! $this->option('from')
            && ! $this->option('to');

        foreach ($tenants->values() as $i => $tenant) {
            try {
                $result = $this->fetcherFor($tenant)->fetch($from, $to, $dryRun);
                $prefix = $dryRun ? '[dry-run] ' : '';
                $this->line("✓ {$tenant->slug}: {$prefix}{$result->summary()}");
                // A clean scheduled fetch resets the consecutive-failure counter.
                if ($trackHealth) {
                    $recorder->recordDeliveryInboundFetch($tenant, true);
                }
            } catch (RuntimeException $e) {
                // Guard messages (mode off / missing creds) — expected config states,
                // not errors: skip quietly. We deliberately DON'T touch the failure
                // streak here. Treating this path as a "success reset" would mask a
                // genuine fault, and counting it as a failure would page for a tenant
                // that is simply not configured (a blank credential slot fails
                // canReadMyData() → the tenant isn't in myDataReadable() and isn't
                // polled at all). The one residual case is a populated-but-undecryptable
                // key (an app-wide APP_KEY rotation) — a global catastrophe surfaced
                // everywhere else, not this per-tenant poll's job. Real AADE-auth
                // failures throw MyDataAuthenticationException → the Throwable branch
                // below → they DO escalate.
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
                // Only the scheduled sweep advances the streak (an ad-hoc --tenant /
                // dry-run run must not inflate it toward a false «persistent»).
                $consecutive = $trackHealth
                    ? $recorder->recordDeliveryInboundFetch($tenant, false, [
                        'last_error' => get_class($e).': '.$e->getMessage(),
                    ])
                    : 0;
                $context = [
                    'company' => $tenant->slug,
                    'exception' => get_class($e),
                    'at' => $e->getFile().':'.$e->getLine(),
                    'message' => $e->getMessage(),
                    'consecutive_failures' => $consecutive,
                ];

                $persistent = $trackHealth && $consecutive >= HealthRecorder::DELIVERY_INBOUND_PERSISTENT_FAILURES;
                if ($persistent && $consecutive === HealthRecorder::DELIVERY_INBOUND_PERSISTENT_FAILURES) {
                    // Escalate to error ONCE, at the crossing: inbound ΔΑ has now
                    // effectively stopped staging (usually bad/expired creds). The
                    // ops:health «persistent» row carries the ONGOING signal, so we
                    // don't re-page every 6h for a known-persistent tenant. The run
                    // still exits 0 (ops:health is the surface, not the OS-cron alert).
                    Log::error('delivery:fetch-inbound: tenant fetch has failed '.$consecutive.' times in a row (persistent — inbound ΔΑ not staging)', $context);
                    $this->warn("• {$tenant->slug}: {$e->getMessage()} (ΕΠΙΜΟΝΗ αποτυχία ×{$consecutive} — έλεγξε creds/σύνδεση ΑΑΔΕ)");
                } elseif ($persistent) {
                    // Already-persistent (beyond the crossing): keep it at warning to
                    // avoid error-log spam; ops:health still shows it as persistent.
                    Log::warning('delivery:fetch-inbound: tenant fetch still failing (persistent — see ops:health)', $context);
                    $this->warn("• {$tenant->slug}: {$e->getMessage()} (ΕΠΙΜΟΝΗ αποτυχία ×{$consecutive} — έλεγξε creds/σύνδεση ΑΑΔΕ)");
                } else {
                    Log::warning('delivery:fetch-inbound: tenant fetch failed (transient; staged nothing this run)', $context);
                    $this->warn("• {$tenant->slug}: {$e->getMessage()} (καταγράφηκε — θα ξαναδοκιμαστεί στην επόμενη εκτέλεση)");
                }
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
