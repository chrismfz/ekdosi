<?php

namespace App\Services\MyData;

/**
 * Outcome of a {@see SupplierNameBackfiller} run — the printable summary the
 * `suppliers:backfill-names` command and the Προμηθευτές list button share.
 *
 * `processed` = ΑΦΜ-only GR suppliers examined THIS run (bounded by the caller's
 * limit); `enriched` = how many got a name from GSIS; `failures` = the ΑΦΜ we
 * could not resolve (not found / creds / unreachable) — left nameless for manual
 * fill. `hitLimit` tells the UI whether more nameless rows likely remain.
 */
final readonly class SupplierBackfillResult
{
    /**
     * @param  list<string>  $failures  ΑΦΜ left nameless (GSIS could not resolve)
     */
    public function __construct(
        public int $processed = 0,
        public int $enriched = 0,
        public array $failures = [],
        public bool $hitLimit = false,
    ) {}

    /** One-line Greek summary for notifications / command output. */
    public function summary(): string
    {
        $s = sprintf('Συμπληρώθηκαν %d/%d προμηθευτές από το μητρώο ΑΑΔΕ.', $this->enriched, $this->processed);

        if ($this->failures !== []) {
            $s .= ' Χωρίς όνομα (δεν βρέθηκαν): '.count($this->failures).'.';
        }

        if ($this->hitLimit) {
            $s .= ' Ίσως υπάρχουν κι άλλοι — τρέξτε ξανά.';
        }

        return $s;
    }
}
