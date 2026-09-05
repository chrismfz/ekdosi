<?php

namespace App\Support\Payments;

/**
 * The normalised result of parsing + verifying a gateway's inbound notification
 * (WebhookGateway::handleWebhook). The gateway does the provider-specific work —
 * digest/HMAC verification and field extraction — and returns THIS; the generic
 * webhook controller then decides whether to settle. `verified=false` (bad
 * signature/digest) is fail-closed: the controller never settles it.
 *
 * `reference` is OUR anchor (the vPOS `orderid` = the PaymentIntent id, as a
 * string) so the controller can find the intent. `amount`/`currency` are the
 * provider's stated figures, cross-checked against the intent before settling
 * (T3 amount-tampering). `providerTxnId` is the acquirer's transaction id, kept
 * for the money trail + dedup.
 */
final readonly class PaymentOutcome
{
    public const STATUS_SETTLED = 'settled';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_PENDING = 'pending';

    public const STATUS_UNKNOWN = 'unknown';

    public function __construct(
        public bool $verified,
        public string $status,
        public ?string $reference = null,
        public ?float $amount = null,
        public ?string $currency = null,
        public ?string $providerTxnId = null,
        public ?string $message = null,
    ) {}

    /** A verified, captured payment — the only state that writes money. */
    public function isSettled(): bool
    {
        return $this->verified && $this->status === self::STATUS_SETTLED;
    }

    public static function unverified(?string $message = null): self
    {
        return new self(verified: false, status: self::STATUS_UNKNOWN, message: $message);
    }
}
