<?php

namespace App\Support\Payments;

/**
 * Where to send the customer to complete a payment — the result of
 * PaymentGateway::initiate(). Carries a redirect URL (hosted gateways, B1) OR
 * offline bank details + instructions (`manual`). NEVER a success flag:
 * settlement is confirmed separately (operator confirmation / signed webhook).
 */
final readonly class PaymentInitiation
{
    public function __construct(
        public string $flow,               // mirrors the gateway's capabilities flow
        public ?string $redirectUrl = null,   // hosted gateways send the customer here (B1)
        public ?string $instructions = null,  // offline: what to do
        public ?string $bankDetails = null,   // offline: where to pay
    ) {}

    public static function offline(?string $bankDetails, ?string $instructions): self
    {
        return new self(flow: 'offline', instructions: $instructions, bankDetails: $bankDetails);
    }
}
