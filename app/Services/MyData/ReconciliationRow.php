<?php

namespace App\Services\MyData;

/**
 * One row in the live-reconciliation worklist. The same shape serves
 * all four buckets; which fields are populated depends on the bucket:
 *
 *   - matched / stateMismatch / missingAtAade: both local + AADE fields
 *     where known (missingAtAade has no AADE side — aadeState is null).
 *   - missingLocally: only the AADE fields; invoiceId/invcode are null.
 *
 * Shared by BOTH the sales reconciler (local side = an Invoice, `invoiceId`)
 * and the expenses reconciler (local side = an Expense, `expenseId`). The two
 * id fields are mutually exclusive per row and default null, so the one shared
 * worklist table partial can render either side.
 *
 * `problem` is the operator-facing Greek explanation for discrepancy
 * rows (null for matched rows).
 */
final readonly class ReconciliationRow
{
    public function __construct(
        public string $mark,
        public ?string $uid = null,
        public ?int $invoiceId = null,
        public ?int $expenseId = null,
        public ?string $invcode = null,
        public ?string $issuedAt = null,
        public ?string $counterpartName = null,
        public ?float $gross = null,
        public ?string $localState = null,   // invoices.mydata_state
        public ?string $localStatus = null,  // invoices.local_status
        public ?string $aadeState = null,    // 'VALID' | 'CANCELLED' | null
        public ?string $cancelledByMark = null,
        public ?string $problem = null,
    ) {}
}
