<?php

namespace App\Services\Whmcs;

use App\Exceptions\Whmcs\WhmcsNotConfigured;
use App\Models\Company;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Stage B-3: factory for the bridge client (companion to
 * WhmcsClientFactory). Resolves bridge URL + webhook secret from
 * the tenant config.
 *
 * Throws WhmcsNotConfigured when:
 *   - The tenant has no whmcs_api_url (no integration at all)
 *   - The api URL doesn't follow the {root}/includes/api.php
 *     convention so we can't derive the plugin path
 *   - The tenant has no whmcs_webhook_secret (without it we can't
 *     HMAC-sign outbound write-backs; the plugin would 401 us)
 *
 * The webhook secret is shared between the inbound webhook
 * (Stage B-1's WhmcsInvoicePaidController) and outbound bridge
 * calls (this client). Bidirectional trust on one key keeps
 * tenant config minimal.
 */
class WhmcsBridgeClientFactory
{
    public function __construct(private readonly HttpFactory $http)
    {
    }

    public function for(Company $tenant): WhmcsBridgeClient
    {
        $bridgeUrl = $tenant->whmcsBridgeUrl();
        if ($bridgeUrl === null) {
            throw new WhmcsNotConfigured(
                "Tenant {$tenant->name} has no derivable WHMCS bridge URL. ".
                'whmcs_api_url must be set AND end with /includes/api.php so '.
                'the bridge plugin path can be derived.'
            );
        }

        $secret = (string) ($tenant->whmcs_webhook_secret ?? '');
        if ($secret === '') {
            throw new WhmcsNotConfigured(
                "Tenant {$tenant->name} has no whmcs_webhook_secret configured. ".
                'The bridge plugin authenticates outbound write-backs via HMAC '.
                'using this secret; set it in Company → WHMCS tab.'
            );
        }

        return new WhmcsBridgeClient(
            http: $this->http,
            bridgeUrl: $bridgeUrl,
            webhookSecret: $secret,
        );
    }
}
