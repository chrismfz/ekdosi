<?php

namespace App\DTOs;

/**
 * Typed result of a VIES (EU VAT Information Exchange System) check.
 *
 * Source: the EU REST endpoint
 *   POST https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number
 *   body { countryCode, vatNumber }   (vatNumber WITHOUT the country prefix)
 *
 * `name`/`address` are returned for some member states (e.g. AT) but withheld
 * by others for privacy (e.g. DE returns valid=true with name/address '---').
 * So `valid` is always meaningful; the identity fields are best-effort and may
 * be empty even on a valid number.
 */
final readonly class ViesResult
{
    public function __construct(
        public string $countryCode,
        public string $vatNumber,
        public bool $valid,
        public string $name,
        public string $address,
        public ?string $requestDate = null,
    ) {}

    /** The full VAT id as commonly written, e.g. "ATU18522105". */
    public function fullVatId(): string
    {
        return $this->countryCode.$this->vatNumber;
    }

    /** True when VIES returned usable identity fields (not withheld/'---'). */
    public function hasIdentity(): bool
    {
        $n = trim($this->name);

        return $n !== '' && $n !== '---';
    }
}
