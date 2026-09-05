<?php

namespace App\Support\Payments;

/**
 * What a payment gateway can do — read by the UI and (from B0b) the charge flow
 * so nothing ever branches on the gateway KEY. See docs/payment-gateways-design.md §3a.
 *
 * `flow` is how the customer pays:
 *   - 'redirect'        hosted page (Stripe Checkout / PayPal / Eurobank)  [B1+]
 *   - 'request_to_pay'  QR / request (IRIS)                                [B1+]
 *   - 'terminal'        physical card POS (operator-side)                  [B3+]
 *   - 'offline'         bank deposit — no online charge, operator confirms [B0]
 *   - 'none'            the Null gateway; cannot charge
 */
final readonly class PaymentGatewayCapabilities
{
    /** @param list<string> $currencies */
    public function __construct(
        public string $flow,
        public bool $webhook,      // settles via a signed provider webhook (vs operator-confirmed)
        public bool $refund,       // supports an online refund
        public bool $prepaid,      // can fund on-account credit (top-up), not just pay one invoice
        public array $currencies = ['EUR'],
    ) {}

    public function chargeable(): bool
    {
        return $this->flow !== 'none';
    }
}
