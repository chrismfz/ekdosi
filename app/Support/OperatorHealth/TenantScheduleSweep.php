<?php

namespace App\Support\OperatorHealth;

use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * OPS-13: run a per-tenant artisan command across a tenant set with per-tenant
 * ISOLATION. A plain `->each(fn ($c) => Artisan::call(...))` lets ONE tenant's
 * uncaught exception abort the whole sweep (later tenants never run) AND skip
 * its health recording — leaving a stale «ok» that still reads as healthy.
 *
 * Here each tenant is isolated: an uncaught throw is reported AND handed to
 * $onError (which records a per-tenant failure so it surfaces), and the sweep
 * carries on to the next tenant. Normal non-zero exits are already self-recorded
 * by the commands themselves — this only catches the uncaught-exception case.
 *
 * Extracted from routes/console.php so the isolation guarantee is unit-testable
 * (the whole point of OPS-13); the scheduler closures just delegate here.
 */
class TenantScheduleSweep
{
    /**
     * @param  iterable<object{slug: string}>  $tenants
     * @param  callable(object, Throwable): void  $onError
     * @return int count of tenants whose command threw (0 = clean sweep)
     */
    public function run(iterable $tenants, string $command, callable $onError): int
    {
        $failed = 0;

        foreach ($tenants as $tenant) {
            try {
                Artisan::call($command, ['--tenant' => $tenant->slug]);
            } catch (Throwable $e) {
                report($e);
                $onError($tenant, $e);
                $failed++;
            }
        }

        return $failed;
    }
}
