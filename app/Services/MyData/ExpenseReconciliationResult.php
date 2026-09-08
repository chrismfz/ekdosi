<?php

namespace App\Services\MyData;

/**
 * Outcome of a live myDATA EXPENSES reconciliation: documents OTHERS filed
 * against us (RequestDocs — our εισροές) cross-checked against our local
 * `expenses` for the same date window. The expense-side twin of
 * SalesReconciliationResult.
 *
 * Five buckets, mirrored meaning (the actionable one is `missingLocally`):
 *   - matched          : MARK present both sides, states agree (no action).
 *   - stateMismatch    : MARK present both sides, AADE-cancelled ≠
 *                        locally-cancelled. ⚠ `problem` says which direction.
 *   - missingAtAade    : we hold an expense MARK that AADE does NOT return.
 *                        ⚠ A local expense AADE no longer/never reports.
 *   - missingLocally   : AADE holds a doc filed against us with NO local
 *                        expense → the actionable "καταχώριση/εισαγωγή
 *                        εξόδου" (a supplier filed against us and we haven't
 *                        recorded it). The expense analog of an "αδέσποτο".
 *   - duplicateLocal   : two+ local expenses share one MARK. ⚠ Local
 *                        data-integrity fault (each colliding row listed).
 *
 * @param  list<ReconciliationRow>  $matched
 * @param  list<ReconciliationRow>  $stateMismatch
 * @param  list<ReconciliationRow>  $missingAtAade
 * @param  list<ReconciliationRow>  $missingLocally
 * @param  list<ReconciliationRow>  $duplicateLocal
 */
final readonly class ExpenseReconciliationResult
{
    public function __construct(
        public string $from,           // dd/MM/yyyy (the window queried)
        public string $to,             // dd/MM/yyyy
        public int $aadeTotal,         // expense docs AADE returned in the window
        public int $localTotal,        // local expenses with a MARK in the window
        public array $matched,
        public array $stateMismatch,
        public array $missingAtAade,
        public array $missingLocally,
        public array $duplicateLocal = [],
        // MARK present both sides, states agree, but a legally-relevant field
        // (gross / type / series-ΑΑ / date / counterpart AFM) DIFFERS (MYD-017).
        // `problem` lists which; separate from stateMismatch.
        public array $contentMismatch = [],
        // AADE carries a field our LOCAL expense lacks (incomplete/unverified) —
        // not a conflict, but never "matched" either (MYD-017).
        public array $contentIncomplete = [],
    ) {}

    public function discrepancyCount(): int
    {
        return count($this->stateMismatch)
            + count($this->contentMismatch)
            + count($this->contentIncomplete)
            + count($this->missingAtAade)
            + count($this->missingLocally)
            + count($this->duplicateLocal);
    }

    public function hasDiscrepancies(): bool
    {
        return $this->discrepancyCount() > 0;
    }
}
