<?php

namespace App\Services\MyData;

/**
 * Outcome of a {@see SupplierSyncFromMyData} run — a small, printable summary
 * the command and the Filament action both render. Pure value object.
 *
 * `createdAfms` / `gsisFailures` carry the actual AFMs so the operator can act
 * on them (e.g. open the not-enriched suppliers and fill names manually).
 */
final readonly class SupplierSyncResult
{
    /**
     * @param  list<string>  $createdAfms      AFMs of suppliers created this run
     * @param  list<string>  $gsisFailures     AFMs we created name-less because GSIS enrichment failed
     */
    public function __construct(
        public int $scannedDocs = 0,
        public int $uniqueAfms = 0,
        public int $created = 0,
        public int $skippedExisting = 0,
        public int $enrichedViaGsis = 0,
        public int $namedFromDoc = 0,
        public int $nameless = 0,
        public array $createdAfms = [],
        public array $gsisFailures = [],
    ) {}

    /** One-line Greek summary for notifications / command output. */
    public function summary(): string
    {
        return sprintf(
            'Σαρώθηκαν %d παραστατικά, %d μοναδικά ΑΦΜ· δημιουργήθηκαν %d (από ΑΑΔΕ: %d, με όνομα από doc: %d, χωρίς όνομα: %d), υπήρχαν ήδη %d.',
            $this->scannedDocs,
            $this->uniqueAfms,
            $this->created,
            $this->enrichedViaGsis,
            $this->namedFromDoc,
            $this->nameless,
            $this->skippedExisting,
        );
    }
}
