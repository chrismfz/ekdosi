<?php

namespace App\Contracts;

use App\Models\PaymentGatewayConnection;
use App\Support\Payments\ConnectionTestResult;
use App\Support\Payments\PaymentGatewayCapabilities;
use Filament\Forms\Components\Component;

/**
 * A payment gateway adapter — one class per way of collecting money (manual bank
 * deposit, Stripe, PayPal, Eurobank, IRIS, card-POS …). See
 * docs/payment-gateways-design.md.
 *
 * B0 scope (mirrors how BillingSource scoped its Phase 0): IDENTITY + CAPABILITIES
 * + CONFIG only. The charge lifecycle (initiate / handleWebhook / refund) lands in
 * B0b/B1 with the real portal flow — the second consumer needed to get the
 * abstraction right, rather than baking one provider's shape into it now.
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

    /** Smoke-test the connection's creds + reachability (the admin «Test connection» action). */
    public function testConnection(PaymentGatewayConnection $connection): ConnectionTestResult;
}
