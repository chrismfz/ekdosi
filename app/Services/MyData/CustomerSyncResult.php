<?php

namespace App\Services\MyData;

/**
 * Outcome of a {@see CustomerSyncFromMyData} run — the customer twin of
 * SupplierSyncResult. Pure value object the command + the Filament action render.
 *
 * `createdAfms` / `gsisFailures` carry the actual AFMs so the operator can act
 * on them (open the not-enriched customers and fill names manually).
 */
final readonly class CustomerSyncResult
{
    /**
     * @param  list<string>  $createdAfms  AFMs of customers created this run
     * @param  list<string>  $gsisFailures  AFMs created name-less because GSIS enrichment failed
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
            'Σαρώθηκαν %d παραστατικά πωλήσεων, %d μοναδικά ΑΦΜ πελατών· δημιουργήθηκαν %d (από ΑΑΔΕ: %d, με όνομα από doc: %d, χωρίς όνομα: %d), υπήρχαν ήδη %d.',
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
