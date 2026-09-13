<?php

namespace App\Services\Delivery;

/**
 * Outcome of an {@see InboundDeliveryFetcher} run — the printable summary the
 * `delivery:fetch-inbound` command and the «Λήψη νέων» inbox action render.
 *
 * `scannedDocs` = movement-bearing docs seen in the window; `created`/`updated`
 * = staged rows inserted / refreshed; `skippedNonMovement` = docs in the window
 * that were NOT ψηφιακή-διακίνηση (plain invoices/expenses — not our concern).
 */
final readonly class InboundFetchResult
{
    /**
     * @param  list<string>  $createdMarks
     */
    public function __construct(
        public int $scannedDocs = 0,
        public int $created = 0,
        public int $updated = 0,
        public int $skippedNonMovement = 0,
        public array $createdMarks = [],
    ) {}

    public function summary(): string
    {
        return sprintf(
            'Εισερχόμενα διακίνησης: %d νέα, %d ενημερώθηκαν (σαρώθηκαν %d παραστατικά κίνησης).',
            $this->created,
            $this->updated,
            $this->scannedDocs,
        );
    }
}
