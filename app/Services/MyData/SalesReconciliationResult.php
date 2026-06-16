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
 *   - duplicateLocal   : two+ local invoices share one MARK. ⚠ Local
 *                        data-integrity fault (each colliding row listed).
 *
 * @param  list<ReconciliationRow>  $matched
 * @param  list<ReconciliationRow>  $stateMismatch
 * @param  list<ReconciliationRow>  $missingAtAade
 * @param  list<ReconciliationRow>  $missingLocally
 * @param  list<ReconciliationRow>  $duplicateLocal
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
        public array $duplicateLocal = [],
    ) {}

    /**
     * `missingAtAade` rows that are IMPORTED legacy invoices (legacy_id set) —
     * they hold a PRODUCTION MARK, so a sandbox query legitimately won't return
     * them. Informational, NOT a real discrepancy.
     *
     * @return list<ReconciliationRow>
     */
    public function importedMissingAtAade(): array
    {
        return array_values(array_filter($this->missingAtAade, fn (ReconciliationRow $r) => $r->legacyId !== null));
    }

    /**
     * `missingAtAade` rows that are NATIVE (legacy_id null) — we filed them in
     * this app yet AADE doesn't return the MARK. The genuinely-worrying bucket.
     *
     * @return list<ReconciliationRow>
     */
    public function unacknowledgedMissingAtAade(): array
    {
        return array_values(array_filter($this->missingAtAade, fn (ReconciliationRow $r) => $r->legacyId === null));
    }

    /**
     * Real discrepancies needing attention — EXCLUDES imported legacy MARKs that a
     * sandbox connection can't see (the «203» noise), so the headline count is honest.
     */
    public function discrepancyCount(): int
    {
        return count($this->stateMismatch)
            + count($this->unacknowledgedMissingAtAade())
            + count($this->missingLocally)
            + count($this->duplicateLocal);
    }

    public function hasDiscrepancies(): bool
    {
        return $this->discrepancyCount() > 0;
    }
}
