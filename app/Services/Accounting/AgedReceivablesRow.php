<?php

namespace App\Services\Accounting;

/**
 * One customer's outstanding receivable, split into ageing buckets. Buckets come
 * from the SAME FIFO logic the Καρτέλα uses (CustomerLedgerBuilder::computeAging),
 * so a row here matches that customer's Καρτέλα exactly.
 */
final readonly class AgedReceivablesRow
{
    public function __construct(
        public int $customerId,
        public string $customerName,
        public ?string $afm,
        public float $b0_30,
        public float $b31_60,
        public float $b61_90,
        public float $b90plus,
        public float $total,
        public ?int $oldestDays,
        // #5 dunning Φάση A: per-customer collection state (nullable).
        public ?string $collectionAssignee = null,
        public ?string $collectionNextStepAt = null,
        public ?string $collectionNextStepNote = null,
        public ?string $collectionLastContactAt = null,
    ) {}
}
