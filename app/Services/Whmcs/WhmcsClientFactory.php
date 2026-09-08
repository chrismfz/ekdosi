<?php

namespace App\Services\Whmcs;

use App\Exceptions\Whmcs\WhmcsNotConfigured;
use App\Models\Company;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Resolves a WhmcsClient configured for a specific tenant. Pattern
 * mirrors EInvoiceSubmitterFactory + TenantMailerFactory — every
 * per-tenant external service goes through a factory so the config-
 * resolution rules are in ONE place, not scattered across callers.
 *
 * Throws WhmcsNotConfigured when the tenant has no integration
 * configured. Callers (artisan command, Filament actions) catch this
 * and present a "configure WHMCS first" message — different from
 * auth/network failures, which mean the integration IS configured
 * but broken.
 */
class WhmcsClientFactory
{
    public function __construct(private readonly HttpFactory $http)
    {
    }

    public function for(Company $tenant): WhmcsClient
    {
        if (! $tenant->hasWhmcsIntegration()) {
            throw new WhmcsNotConfigured(
                "Tenant {$tenant->name} has no WHMCS integration configured. ".
                'Fill in Company → WHMCS tab (URL + identifier + secret) first.'
            );
        }

        return new WhmcsClient(
            http: $this->http,
            apiUrl: (string) $tenant->whmcs_api_url,
            identifier: (string) $tenant->whmcs_api_identifier,
            // The encrypted-cast accessor handles decryption transparently.
            secret: (string) $tenant->whmcs_api_secret,
        );
    }
}
