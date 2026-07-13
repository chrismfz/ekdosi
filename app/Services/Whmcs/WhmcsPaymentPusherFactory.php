<?php

namespace App\Services\Whmcs;

use App\Exceptions\Whmcs\WhmcsNotConfigured;
use App\Models\Company;

/**
 * Builds the `fn(int $whmcsInvoiceId, float $amount, string $transId): void` a
 * tenant uses to mark ONE WHMCS invoice paid — via the bridge plugin
 * (`op=add_payment`) when the tenant is on `whmcs_fetch_via_bridge`, else the
 * native WHMCS API (`AddInvoicePayment`). Unlike the read-side fetcher, the
 * closure does NOT swallow errors: a push failure must surface so the pusher
 * can leave the invoice retriable (never silently "assume sent").
 */
class WhmcsPaymentPusherFactory
{
    public function __construct(
        private readonly WhmcsClientFactory $factory,
        private readonly WhmcsBridgeClientFactory $bridgeFactory,
    ) {}

    /**
     * @return (callable(int, float, string): void)|null null when the tenant has
     *                                                   no usable WHMCS configuration (caller skips it).
     */
    public function for(Company $tenant): ?callable
    {
        try {
            if ((bool) $tenant->whmcs_fetch_via_bridge) {
                $client = $this->bridgeFactory->for($tenant);

                return function (int $id, float $amount, string $transId) use ($client): void {
                    $client->addInvoicePayment($id, $amount, $transId);
                };
            }

            $client = $this->factory->for($tenant);

            return function (int $id, float $amount, string $transId) use ($client): void {
                $client->addInvoicePayment($id, $amount, $transId);
            };
        } catch (WhmcsNotConfigured) {
            return null;
        }
    }
}
