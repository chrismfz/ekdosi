<?php

namespace App\Services\MyData;

/**
 * Outcome of a live myDATA sales reconciliation: our locally-filed
 * invoices cross-checked against what AADE actually holds
 * (RequestTransmittedDocs) for the same date window.
 *
 * Four buckets:
 *   - matched          : MARK present both sides, states agree (no action).
 *   - stateMismatch    : MARK present both sides, AADE-cancelled ≠
 *                        locally-cancelled. ⚠ Needs operator attention;
 *                        `problem` says which direction.
 *   - missingAtAade    : we hold a MARK that AADE does NOT return.
 *                        ⚠ Serious — we believe it's filed, AADE disagrees.
 *   - missingLocally   : AADE returns a MARK we have no local invoice for
 *                        (filed from another machine / lost local record).
 *
 * @param  list<ReconciliationRow>  $matched
 * @param  list<ReconciliationRow>  $stateMismatch
 * @param  list<ReconciliationRow>  $missingAtAade
 * @param  list<ReconciliationRow>  $missingLocally
 */
final readonly class SalesReconciliationResult
{
    public function __construct(
        public string $from,           // dd/MM/yyyy (the window queried)
        public string $to,             // dd/MM/yyyy
        public int $aadeTotal,         // docs AADE returned in the window
        public int $localTotal,        // local filed invoices in the window
        public array $matched,
        public array $stateMismatch,
        public array $missingAtAade,
        public array $missingLocally,
    ) {}

    public function discrepancyCount(): int
    {
        return count($this->stateMismatch)
            + count($this->missingAtAade)
            + count($this->missingLocally);
    }

    public function hasDiscrepancies(): bool
    {
        return $this->discrepancyCount() > 0;
    }
}
