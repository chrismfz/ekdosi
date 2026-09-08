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
    /**
     * Cap for an activity description when it's stored as an `occupation`. AADE
     * ships multi-hundred-char legal activity descriptions, but every
     * `occupation` column is VARCHAR(120) (customers/invoices) — and the invoice
     * snapshot copies the customer's value, so an un-capped blob overflowed
     * customer import with SQLSTATE[22001] «Data too long for column 'occupation'».
     * Capping at the DTO source protects every consumer (customer/supplier
     * create, myDATA sync, the invoice snapshot) at once.
     */
    public const OCCUPATION_MAX_LENGTH = 120;

    public function __construct(
        public string $afm,
        public string $name,
        public string $doy,
        public string $doyCode,
        /** @var bool true if AFM is currently active (per deactivation_flag_descr) */
        public bool $active,
        /** @var string raw Greek status text from AADE, e.g. "ΕΝΕΡΓΟΣ ΑΦΜ" — surface as-is on UI */
        public string $statusDescr,
        public string $address,
        public string $city,
        public string $postcode,
        /** @var list<array{code: string, description: string, kind: string}> */
        public array $activities,
    ) {}

    /**
     * The primary activity if any. AADE convention: a registered business
     * has exactly one primary activity, marked in the response's
     * firm_act_kind_descr field.
     *
     * The kind value drifts between Latin transliteration ("KYRIA") and
     * Greek script ("ΚΥΡΙΑ" / "Κύρια") depending on which AADE endpoint
     * version and SOAP encoding the response goes through. Match case-
     * insensitively against the known forms. As a final fallback when
     * no entry self-identifies as primary (rare — seen for dormant
     * entities), return the first activity so the operator still gets
     * SOMETHING to work with.
     *
     * @return array{code: string, description: string, kind: string}|null
     */
    public function primaryActivity(): ?array
    {
        // Latin (legacy/sandbox endpoint), Greek with accent (current
        // production), Greek without accent (older variants), English
        // (rare). mb_strtolower + trim catches whitespace and case
        // variants. We don't bother normalising Greek combining marks
        // because precomposed forms are what AADE ships in practice.
        $primaryForms = ['kyria', 'κύρια', 'κυρια', 'primary'];

        foreach ($this->activities as $a) {
            $kind = mb_strtolower(trim($a['kind']));
            if (in_array($kind, $primaryForms, true)) {
                return $this->withCappedDescription($a);
            }
        }

        // Final fallback: no entry self-identifies as primary. Return
        // the first activity so the operator still gets SOMETHING; if
        // it's wrong they can manually override the kad_primary field
        // on the form.
        return isset($this->activities[0]) ? $this->withCappedDescription($this->activities[0]) : null;
    }

    /**
     * Cap the activity `description` to what an `occupation` column can hold (see
     * OCCUPATION_MAX_LENGTH). Multibyte-safe — Greek text is counted in
     * characters, matching the VARCHAR(120) char-length semantics in utf8mb4.
     *
     * @param  array{code: string, description: string, kind: string}  $activity
     * @return array{code: string, description: string, kind: string}
     */
    private function withCappedDescription(array $activity): array
    {
        $activity['description'] = rtrim(mb_substr((string) $activity['description'], 0, self::OCCUPATION_MAX_LENGTH));

        return $activity;
    }
}
