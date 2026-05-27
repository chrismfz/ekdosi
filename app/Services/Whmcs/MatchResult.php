<?php

namespace App\Services\Whmcs;

use App\Models\Customer;

/**
 * Result of WhmcsCustomerMatcher::match(). Captures the matched
 * customer (if any) + the reason, so the UI can render confidence.
 *
 * Reasons:
 *   - linked    customers.whmcs_client_id direct link (operator-set)
 *   - afm       tax-id exact match
 *   - email     email exact match (case-insensitive)
 *   - name      company-name / fullname exact match (lowest confidence)
 *   - unmatched no candidate found in our customers table
 */
final readonly class MatchResult
{
    public function __construct(
        public ?Customer $customer,
        public string $reason,
        public int $whmcsClientId,
    ) {
    }

    public function isMatched(): bool
    {
        return $this->customer !== null;
    }

    /**
     * Human-readable confidence label for UI output. "linked" and
     * "afm" are operator-confidence-high; "email" is medium; "name"
     * is low (could be a different business with the same name);
     * "unmatched" speaks for itself.
     */
    public function confidenceLabel(): string
    {
        return match ($this->reason) {
            'linked'    => 'linked (operator-confirmed)',
            'afm'       => 'AFM match',
            'email'     => 'email match',
            'name'      => 'name match (needs confirmation)',
            'unmatched' => 'no candidate',
            default     => $this->reason,
        };
    }
}
