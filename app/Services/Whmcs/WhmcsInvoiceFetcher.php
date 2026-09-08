<?php

namespace App\Services\Whmcs;

use App\Exceptions\Whmcs\WhmcsNotConfigured;
use App\Models\Company;
use Throwable;

/**
 * Builds the `fn(int $whmcsInvoiceId): ?array` a tenant uses to fetch one WHMCS
 * invoice payload — via the bridge plugin (`resolve.php op=invoice`) when the
 * tenant is on `whmcs_fetch_via_bridge`, else the native WHMCS API. Both return
 * the payload (with `status`/`datepaid`); the closure swallows per-invoice fetch
 * errors to null so an unreachable invoice never aborts a batch nor is guessed
 * as paid. Shared by whmcs:sync-payments + the inbox / per-invoice UI actions.
 */
class WhmcsInvoiceFetcher
{
    public function __construct(
        private readonly WhmcsClientFactory $factory,
        private readonly WhmcsBridgeClientFactory $bridgeFactory,
    ) {}

    /**
     * @return (callable(int): (array<string, mixed>|null))|null null when the tenant
     *                                                           has no usable WHMCS configuration (caller skips it).
     */
    public function for(Company $tenant): ?callable
    {
        try {
            if ((bool) $tenant->whmcs_fetch_via_bridge) {
                $client = $this->bridgeFactory->for($tenant);

                return fn (int $id): ?array => $this->safe(fn () => $client->fetchInvoice($id));
            }

            $client = $this->factory->for($tenant);

            return fn (int $id): ?array => $this->safe(fn () => $client->getInvoice($id));
        } catch (WhmcsNotConfigured) {
            return null;
        }
    }

    /**
     * @param  callable(): (array<string, mixed>|null)  $call
     * @return array<string, mixed>|null
     */
    private function safe(callable $call): ?array
    {
        try {
            $result = $call();

            return is_array($result) ? $result : null;
        } catch (Throwable) {
            return null;
        }
    }
}
