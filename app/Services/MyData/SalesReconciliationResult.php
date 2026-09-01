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
        // MARK present both sides, states agree, but a legally-relevant field
        // (gross / type / series-ΑΑ / date / counterpart AFM) DIFFERS — a false
        // "matched" under the old MARK/state-only rule (MYD-017). `problem` lists
        // which fields differ; kept SEPARATE from stateMismatch (different repair).
        public array $contentMismatch = [],
        // MARK present both sides, states agree, no conflict — but AADE carries a
        // field our LOCAL record lacks (incomplete/unverified import). Not a
        // conflict, so kept out of contentMismatch, but never "matched" either (MYD-017).
        public array $contentIncomplete = [],
        // Whether the reconcile ran against the SANDBOX channel. ONLY then is an
        // imported (production-MARK) «missing at AADE» noise; in PRODUCTION an
        // imported MARK was filed to the SAME channel, so its absence is a REAL
        // discrepancy and must count.
        public bool $sandbox = false,
    ) {}

    /**
     * `missingAtAade` rows that are IMPORTED legacy invoices (legacy_id set).
     *
     * @return list<ReconciliationRow>
     */
    public function importedMissingAtAade(): array
    {
        return array_values(array_filter($this->missingAtAade, fn (ReconciliationRow $r) => $r->legacyId !== null));
    }

    /**
     * The EXPECTED-noise subset: imported MARKs that a SANDBOX connection can't
     * return (the «203»). Empty in production — there an imported MARK should be
     * present, so its absence is a real concern, not noise.
     *
     * @return list<ReconciliationRow>
     */
    public function noiseMissingAtAade(): array
    {
        return $this->sandbox ? $this->importedMissingAtAade() : [];
    }

    /**
     * `missingAtAade` rows that genuinely need attention — everything except the
     * sandbox imported-noise (so: all of them in production; native-only in sandbox).
     *
     * @return list<ReconciliationRow>
     */
    public function realMissingAtAade(): array
    {
        if (! $this->sandbox) {
            return $this->missingAtAade;
        }

        return array_values(array_filter($this->missingAtAade, fn (ReconciliationRow $r) => $r->legacyId === null));
    }

    /**
     * Real discrepancies needing attention — excludes ONLY the sandbox
     * imported-noise, so the headline count is honest in both modes.
     */
    public function discrepancyCount(): int
    {
        return count($this->stateMismatch)
            + count($this->contentMismatch)
            + count($this->contentIncomplete)
            + count($this->realMissingAtAade())
            + count($this->missingLocally)
            + count($this->duplicateLocal);
    }

    public function hasDiscrepancies(): bool
    {
        return $this->discrepancyCount() > 0;
    }
}
