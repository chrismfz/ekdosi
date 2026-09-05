<?php

namespace App\Contracts;

use App\Models\PaymentGatewayConnection;
use App\Models\PaymentIntent;
use App\Support\Payments\ConnectionTestResult;
use App\Support\Payments\PaymentGatewayCapabilities;
use App\Support\Payments\PaymentInitiation;
use Filament\Forms\Components\Component;

/**
 * A payment gateway adapter — one class per way of collecting money (manual bank
 * deposit, Stripe, PayPal, Eurobank, IRIS, card-POS …). See
 * docs/payment-gateways-design.md.
 *
 * Scope grows with real consumers (as BillingSource did): B0a = identity +
 * capabilities + config; B0b adds `initiate()` now that the portal «Πλήρωσε» flow
 * exists to exercise it. `handleWebhook()` / `refund()` follow in B1 with the
 * first online gateway (their shapes need a real provider, not the offline one).
 *
 * Adding a gateway = one class implementing this + one line in
 * config/ekdosi.php → payments.gateways. Per-tenant selection/creds live in the
 * `payment_gateway_connections` table (mirrors billing_connections).
 */
interface PaymentGateway
{
    /** Stable machine key; matches the registry + the connection row's `gateway`. */
    public function key(): string;

    /** Default operator-facing name (a connection's `label` overrides it for the customer). */
    public function displayName(): string;

    public function capabilities(): PaymentGatewayCapabilities;

    /**
     * The per-connection settings this gateway needs, as Filament form components
     * (rendered inside the «Τρόποι πληρωμής» resource under the `config` state path).
     * Secrets must be password-type + write-only. Return [] for none.
     *
     * @return array<int, Component>
     */
    public function configFields(): array;

    /**
     * Begin collecting the intent's amount out-of-band. Returns WHERE to send the
     * customer (a redirect URL for hosted gateways; offline bank details +
     * instructions for `manual`) — never a success flag. Settlement is confirmed
     * later (operator confirmation for manual; a signed webhook for online, B1).
     * Reads per-connection creds/settings from $connection->config.
     */
    public function initiate(PaymentIntent $intent, PaymentGatewayConnection $connection): PaymentInitiation;

    /** Smoke-test the connection's creds + reachability (the admin «Test connection» action). */
    public function testConnection(PaymentGatewayConnection $connection): ConnectionTestResult;
}
