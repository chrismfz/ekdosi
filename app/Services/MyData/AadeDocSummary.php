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
        // <totalNetValue> from the AADE summary — see LocalDocSnapshot::$net.
        // REQUIRED (no default) on purpose: the comparator treats net as a
        // mandatory field, so a construction site that forgot it would silently
        // flip every row of that reconciler to contentIncomplete.
        public ?float $net,
        // §8.1 invoice type (e.g. '1.1', '14.3', '17.1') — lets the console
        // bucket an orphan as income / supplier-expense / accounting-entry
        // instead of dumping payroll into the "αδέσποτα πωλήσεων" list.
        public ?string $invoiceType = null,
    ) {}

    /**
     * A copy marked cancelled, carrying the standalone <cancelledInvoicesDoc>
     * cancellation MARK (falling back to any inline one). Centralising the rebuild
     * means a new field added to this DTO is carried through the fold automatically
     * — the hand-copied version silently dropped whatever field you forgot.
     */
    public function withCancellation(?string $cancellationMark): self
    {
        return new self(
            mark: $this->mark,
            uid: $this->uid,
            cancelled: true,
            cancelledByMark: ($cancellationMark !== null && $cancellationMark !== '')
                ? $cancellationMark
                : $this->cancelledByMark,
            series: $this->series,
            aa: $this->aa,
            issueDate: $this->issueDate,
            counterpartName: $this->counterpartName,
            counterpartVat: $this->counterpartVat,
            gross: $this->gross,
            net: $this->net,
            invoiceType: $this->invoiceType,
        );
    }
}
