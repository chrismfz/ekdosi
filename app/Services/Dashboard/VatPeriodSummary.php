<?php

namespace App\Services\Dashboard;

/**
 * ΦΠΑ position for a period: output side (εκροών, from our invoices) vs input
 * side (εισροών, from local expenses), and the net we'd owe the εφορία.
 *
 *   netVat = outputVat − inputVat
 *     > 0  → προς απόδοση (we owe it)
 *     < 0  → πιστωτικό υπόλοιπο (carried forward)
 *
 * LOCAL figures (computed from ekdosi's own data). The authoritative AADE
 * cross-check (RequestVatInfo) is a documented follow-up — never present the
 * local number as gospel once that lands.
 */
readonly class VatPeriodSummary
{
    public function __construct(
        public string $label,        // e.g. "Τρίμηνο 1 2026" / "01/2026"
        public float $outputNet,
        public float $outputVat,
        public float $outputGross,
        public int $outputCount,
        public float $inputNet,
        public float $inputVat,
        public float $inputGross,
        public int $inputCount,
    ) {}

    /** Net VAT toward the state: output − input. Positive = προς απόδοση. */
    public function netVat(): float
    {
        return round($this->outputVat - $this->inputVat, 2);
    }

    /** True when we owe VAT (net positive); false when in credit. */
    public function isPayable(): bool
    {
        return $this->netVat() > 0.005;
    }
}
