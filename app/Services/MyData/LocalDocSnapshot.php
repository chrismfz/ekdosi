<?php

namespace App\Services\MyData;

/**
 * The content of ONE local document (an Invoice or an Expense), flattened to the
 * fields a live reconciliation compares against the AADE summary for the same
 * MARK (MYD-017). Each reconciler builds this from its own model so the pure
 * ReconciliationContentComparator never needs to know which side it came from.
 *
 * All fields are the FROZEN/recorded local values — the comparator only reads
 * them, it never rewrites a filed document.
 */
final readonly class LocalDocSnapshot
{
    public function __construct(
        public ?float $gross,
        public ?string $series,
        public ?string $aa,
        public ?string $issueDate,        // Y-m-d
        public ?string $counterpartVat,   // frozen AFM (issuer/customer)
        public ?string $invoiceType,      // §8.1 code, e.g. '1.1'
    ) {}
}
