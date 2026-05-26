<?php

namespace App\Services;

use App\Models\Company;
use App\Models\InvoiceType;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Allocates the next per-(company, invoice_type) sequential number (ΑΑ)
 * and the human-readable invoice code, atomically.
 *
 * Replaces two pieces of legacy Firebird logic that are NOT yet ported:
 *
 *   1. The INVOICE_BI1 trigger, which set NEW.INVCODE = INVTYPE_ID || INVCOUNT
 *      (see legacy/ekdosi-schema.sql line 953, and the GET_INV_CODE stored
 *      procedure at line 668: "INV_CNTCODE = XINVTYPE || CNT").
 *
 *   2. The INVOICE_AI trigger, which incremented INVTYPE.INVCOUNT by 1
 *      AFTER each successful invoice insert (legacy/ekdosi-schema.sql:965).
 *      Firebird serialised this implicitly via row locks during the trigger;
 *      in Laravel we must do it ourselves with lockForUpdate() inside a
 *      transaction or two concurrent issues for the same invoice type will
 *      collide on the ΑΑ (both read the same invcount, both write the same
 *      invcode, the second insert fails the invcodes-unique constraint).
 *
 * Usage at the IssueInvoice action call site (future PR):
 *
 *     $allocation = app(InvoiceNumberer::class)
 *         ->allocate($company, 'APY');
 *     // $allocation->code      = 423
 *     // $allocation->invcode   = "APY423"
 *     // The Invoice row should be written inside the SAME transaction
 *     // that called allocate(), so a failed insert rolls back the
 *     // counter increment.
 *
 * Re INVCODE format: legacy GET_INV_CODE concatenates with no padding,
 * fiscal year, or separator. We reproduce that exactly so cutover
 * numbering continues from the same counter without a visible format
 * change for customers / AADE / WHMCS.
 */
final class InvoiceNumberer
{
    public function __construct(
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * Allocate the next ΑΑ for the given (company, invoice-type-code) pair.
     *
     * MUST be called inside a DB transaction held open by the caller until
     * the invoice row is persisted. The lockForUpdate() row lock here only
     * holds for the lifetime of the current transaction; if the caller
     * commits before writing the invoice and then fails, the counter is
     * bumped but no invoice exists for that ΑΑ (a numbering gap, which is
     * worse than a duplicate).
     */
    public function allocate(Company $company, string $invoiceTypeCode): InvoiceAllocation
    {
        if (! $this->db->transactionLevel()) {
            throw new RuntimeException(
                'InvoiceNumberer::allocate() must run inside a DB transaction. '
                . 'Wrap the IssueInvoice action in DB::transaction(...).'
            );
        }

        // Atomically read + reserve the next number under a row lock.
        // SELECT ... FOR UPDATE blocks any concurrent allocate() for the same
        // (company_id, code) pair until this transaction commits or rolls back.
        $type = InvoiceType::query()
            ->where('company_id', $company->id)
            ->where('code', $invoiceTypeCode)
            ->lockForUpdate()
            ->first();

        if (! $type) {
            throw new RuntimeException(sprintf(
                'No invoice_type with code=%s for company_id=%d (slug=%s). '
                . 'Cannot allocate ΑΑ.',
                $invoiceTypeCode,
                $company->id,
                $company->slug,
            ));
        }

        $allocatedAa = $type->invcount;
        $invcode = $type->code . $allocatedAa;

        // Bump for the next allocation. Use increment() which issues a
        // bare UPDATE rather than reading-then-writing — keeps the row
        // lock minimal and avoids touching unrelated columns.
        $type->increment('invcount');

        return new InvoiceAllocation(
            code: $allocatedAa,
            invcode: $invcode,
            invoiceType: $type->refresh(),
        );
    }
}
