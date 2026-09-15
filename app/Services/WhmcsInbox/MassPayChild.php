<?php

namespace App\Services\WhmcsInbox;

/**
 * One child invoice of a WHMCS mass-pay, fetched fresh (GetInvoice) so we have
 * its REAL line items — WHMCS zeroes the child's `total` when it rolls into the
 * mass-pay, so the items (net amount + `taxed`) are the only source of truth.
 */
final class MassPayChild
{
    /**
     * @param  int  $whmcsInvoiceId  the child's own WHMCS invoice id
     * @param  array<int, array<string,mixed>>  $lines  the child's real service line items (net)
     * @param  float  $net  Σ of the line nets
     * @param  float  $gross  net + VAT (the child's true gross)
     * @param  float  $referenceGross  the mass-pay's stated gross for this child (its reference line amount)
     * @param  ?int  $whmcsUserId  the child's WHMCS client id (for the third-party check)
     */
    public function __construct(
        public readonly int $whmcsInvoiceId,
        public readonly array $lines,
        public readonly float $net,
        public readonly float $gross,
        public readonly float $referenceGross,
        public readonly ?int $whmcsUserId,
        public readonly bool $sameParty,
        public readonly array $payload = [],
    ) {}

    /**
     * Does the child's own gross (net + VAT from its items) match what the mass-pay
     * says it settled for this child (± 1 cent for rounding)? A mismatch means the
     * child's items don't add up to the payment — surface it, don't file blindly.
     */
    public function reconciles(): bool
    {
        return abs($this->gross - $this->referenceGross) <= 0.01;
    }
}
