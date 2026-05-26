<?php

namespace App\DTOs;

/**
 * Result of an AADE / GSIS RgWsPublic2 VAT lookup. Readonly value
 * object — once constructed it's a frozen snapshot of the registry
 * state at fetch time (subject to caching, see AadeRegistryLookup).
 *
 * `activities` shape: an array of associative arrays with `code`
 * (8-digit KAD), `description` (Greek text), and `kind` ("KYRIA" or
 * "DEYTEREVOUSA"). Operator-facing display logic should treat the
 * KYRIA entry as the canonical "primary activity".
 *
 * Population happens in AadeRegistryLookup::parse() from the SOAP
 * response's $result->rg_ws_public2_result_rtType->basic_rec path.
 * Field names below mirror the legacy uploaded example so the parse
 * is a 1:1 mapping.
 */
final readonly class AadeRegistryRecord
{
    public function __construct(
        public string $afm,
        public string $name,
        public string $doy,
        public string $doyCode,
        /** @var bool true if AFM is currently active (deactivation_flag != 1) */
        public bool $active,
        public string $address,
        public string $city,
        public string $postcode,
        /** @var list<array{code: string, description: string, kind: string}> */
        public array $activities,
    ) {}

    /**
     * The primary (KYRIA) activity if any. AADE convention: a registered
     * business has exactly one ΚΥΡΙΑ activity. Returns null only on
     * pathological data (registry rows with no primary at all — has been
     * seen for dormant entities).
     *
     * @return array{code: string, description: string, kind: string}|null
     */
    public function primaryActivity(): ?array
    {
        foreach ($this->activities as $a) {
            if ($a['kind'] === 'KYRIA') {
                return $a;
            }
        }
        return null;
    }
}
