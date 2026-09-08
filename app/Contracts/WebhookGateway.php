<?php

namespace App\Contracts;

use App\Models\PaymentGatewayConnection;
use App\Support\Payments\PaymentOutcome;
use Illuminate\Http\Request;

/**
 * Opt-in capability for gateways that settle via a signed provider notification
 * (`capabilities()->webhook === true`). The gateway VERIFIES the payload
 * (provider signature / vPOS digest over the raw body) and returns a normalised
 * PaymentOutcome; it MUST be safe to call twice (idempotency is enforced by the
 * caller on the intent, T2). Kept out of the base PaymentGateway interface so the
 * manual/offline gateway (operator-confirmed, no webhook) doesn't stub it — the
 * return controller checks `instanceof WebhookGateway`.
 */
interface WebhookGateway
{
    public function handleWebhook(Request $request, PaymentGatewayConnection $connection): PaymentOutcome;
}
