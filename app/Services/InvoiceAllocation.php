<?php

namespace App\Services;

use App\Models\InvoiceType;

/**
 * Result of InvoiceNumberer::allocate(). The IssueInvoice action will
 * write `code` and `invcode` onto the new Invoice row, mirroring the
 * legacy INVOICE_BI1 trigger's outputs:
 *
 *   - $code    = INVTYPE.INVCOUNT at allocation time (= the ΑΑ)
 *   - $invcode = INVTYPE_ID || INVCOUNT (e.g. "APY423")
 *
 * `invoiceType` is the refreshed model (its `invcount` now points at the
 * NEXT allocation, not this one).
 */
final readonly class InvoiceAllocation
{
    public function __construct(
        public int $code,
        public string $invcode,
        public InvoiceType $invoiceType,
    ) {}
}
