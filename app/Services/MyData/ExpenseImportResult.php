<?php

namespace App\Services\MyData;

/**
 * Outcome of an {@see ExpenseImporter} run — the printable summary the console
 * import action renders.
 *
 * `createdMarks` carries the MARKs of expenses created this run; `skippedMarks`
 * the ones already on file (idempotency). `notFoundMarks` are requested MARKs
 * the AADE window didn't actually return (stale worklist / wrong window).
 */
final readonly class ExpenseImportResult
{
    /**
     * @param  list<string>  $createdMarks
     * @param  list<string>  $skippedMarks
     * @param  list<string>  $notFoundMarks
     */
    public function __construct(
        public int $scannedDocs = 0,
        public int $created = 0,
        public int $skippedExisting = 0,
        public int $suppliersCreated = 0,
        public array $createdMarks = [],
        public array $skippedMarks = [],
        public array $notFoundMarks = [],
    ) {}

    public function summary(): string
    {
        return sprintf(
            'Καταχωρήθηκαν %d έξοδα (νέοι προμηθευτές: %d), υπήρχαν ήδη %d.',
            $this->created,
            $this->suppliersCreated,
            $this->skippedExisting,
        );
    }
}
