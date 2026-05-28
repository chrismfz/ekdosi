<?php

namespace App\Services\MyData;

/**
 * A flattened summary of ONE document AADE returned for the tenant via
 * RequestTransmittedDocs — only the fields the reconciler needs to
 * match against our local invoices and to render a worklist row.
 *
 * `mark` is always a string and is compared as a string everywhere —
 * AADE MARKs are 15+ digit numbers that overflow 32-bit ints, so we
 * never cast them (same lesson locked in by the MyDataSubmitter cancel
 * path).
 *
 * `cancelled` is true when AADE considers the document cancelled —
 * either the invoice element carried a <cancelledByMark> inline, OR the
 * MARK appeared in the response's <cancelledInvoicesDoc> list. The
 * reconciler folds both signals into this one flag during fetch.
 */
final readonly class AadeDocSummary
{
    public function __construct(
        public string $mark,
        public ?string $uid,
        public bool $cancelled,
        public ?string $cancelledByMark,
        public ?string $series,
        public ?string $aa,
        public ?string $issueDate,
        public ?string $counterpartName,
        public ?string $counterpartVat,
        public ?float $gross,
    ) {}
}
