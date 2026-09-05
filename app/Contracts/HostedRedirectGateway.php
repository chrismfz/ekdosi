<?php

namespace App\Contracts;

use App\Models\PaymentGatewayConnection;
use App\Models\PaymentIntent;
use App\Support\Payments\HostedRedirectForm;

/**
 * Opt-in capability for gateways whose `flow` is 'redirect': the customer is sent
 * to the acquirer's hosted page by auto-submitting a signed POST form (vPOS and
 * friends need POST, not a plain GET redirect). Kept OUT of the base
 * PaymentGateway interface so offline/manual gateways don't stub it — the portal
 * redirect page checks `instanceof HostedRedirectGateway`, mirroring how
 * WebhookGateway gates the return path.
 *
 * Built fresh at redirect time (not in initiate()) so the signed form is never
 * persisted and stays stable across a browser refresh.
 */
interface HostedRedirectGateway
{
    public function redirectForm(PaymentIntent $intent, PaymentGatewayConnection $connection): HostedRedirectForm;
}
