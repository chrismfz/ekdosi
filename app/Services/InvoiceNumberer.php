<?php

namespace App\Services;

use App\Models\Company;
use App\Models\InvoiceType;
use Illuminate\Database\ConnectionInterface;
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
 *     // $allocation->code    = 423             (the ΑΑ)
 *     // $allocation->series  = "APY"           (the type code)
 *     // $allocation->invcode = "APY423"        (formatted)
 *
 * Re INVCODE format: legacy GET_INV_CODE concatenates with no padding,
 * fiscal year, or separator. We reproduce that exactly so cutover
 * numbering continues from the same counter without a visible format
 * change for customers / AADE / WHMCS.
 *
 * ──── DESIGN INVARIANTS (do not break without revisiting concurrency) ────
 *
 * (A) Counter bump-then-insert vs legacy bump-after-insert.
 *     The legacy INVOICE_AI trigger fired AFTER successful insert, so a
 *     failed INSERT could not leave a bumped counter. We bump BEFORE the
 *     caller's INSERT — safe ONLY because we require the caller to hold
 *     a single transaction over the whole sequence (bump + INSERT), so
 *     a failed INSERT rolls back both. **Never refactor allocate() to
 *     commit the bump in its own transaction.** A failed downstream
 *     INSERT then leaves a permanent ΑΑ gap that can't be reconstructed.
 *
 * (B) myDATA submission inside the same transaction = serialised issuance.
 *     The row lock acquired here is held until the caller commits. If
 *     the IssueInvoice action submits to AADE myDATA inside the same
 *     transaction (the obvious shape), one slow AADE response blocks
 *     all other issuance for that (company, invoice-type) — concurrent
 *     allocate() calls queue on the row lock for up to
 *     innodb_lock_wait_timeout (50s default) then throw "Lock wait
 *     timeout". Under bulk-issue load (WHMCS bridge draining a backlog)
 *     this is the throughput ceiling. If/when that becomes a problem,
 *     options: (i) submit to myDATA OUTSIDE the transaction and use a
 *     two-phase mark-as-pending/finalize flow; (ii) shard the lock by a
 *     "shard" column on invoice_types and round-robin. Both increase
 *     complexity; defer until measured load justifies it.
 *
 * (C) The DTO's $invoiceType is the model that was locked for SELECT,
 *     with $invoiceType->invcount manually synced to the post-increment
 *     value. The caller can read mydata_type / income_class fields off
 *     it for the myDATA payload, but **must not** issue updates against
 *     it expecting the FOR UPDATE lock to still cover them — the lock
 *     covers the original SELECT; subsequent UPDATEs need their own
 *     locking strategy.
 *
 * ──── ΑΑ GAP POLICY (MON-4 — explicit, do not change without a decision) ────
 *
 * The ΑΑ is allocated when the DRAFT is created (CreateInvoice), NOT when it
 * is finalised/filed. Two consequences, both accepted by design:
 *
 *   - Deleting a draft (EditInvoice, draft-only) leaves a PERMANENT gap in the
 *     per-series sequence. The number is never recycled — recycling would risk
 *     a duplicate ΑΑ on a filed (legally binding) document, which is far worse
 *     than a gap. myDATA identifies a document by its AADE MARK, not by a
 *     gapless ΑΑ, so a gap is legally fine; the legacy Firebird app behaved
 *     identically (a failed INSERT / deleted row left the same gap).
 *
 *   - `issued_at` is captured at draft creation alongside the ΑΑ (form default =
 *     now), so the two are aligned at capture. A draft that lingers before
 *     finalisation keeps its creation-time date; finalisation does not renumber
 *     or re-date it. Operators can edit `issued_at` on the draft form.
 *
 * The alternative — allocate the ΑΑ only at finalisation — would eliminate
 * delete-gaps but a draft would then have no invcode (every PDF/preview/WHMCS
 * surface assumes one), a large blast radius for a legally-immaterial gap. Not
 * done. Revisit only if an operator's accountant requires gapless ΑΑ per series.
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
     * the invoice row is persisted. See invariant (A) on the class for
     * why this matters.
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

        // Bump for the next allocation via a RAW UPDATE — NOT Eloquent's
        // ->increment(), which fires updating/updated events and touches
        // updated_at. On a hot allocation path this would spam audit logs
        // (once spatie/laravel-activitylog is wired up) and create false
        // signals for any consumer using updated_at to detect "operator
        // edited this invoice type". The row lock acquired above still
        // covers this UPDATE.
        $this->db->table('invoice_types')
            ->where('id', $type->id)
            ->update(['invcount' => $this->db->raw('invcount + 1')]);

        // Sync the in-memory model to match the post-update value so the
        // caller doesn't have to re-query. The original $type was locked
        // for SELECT; the model still reflects every other column correctly
        // — we only mutated invcount, mirroring what the DB now has.
        $type->invcount = $allocatedAa + 1;

        return new InvoiceAllocation(
            code: $allocatedAa,
            series: $type->code,
            invcode: $invcode,
            invoiceType: $type,
        );
    }
}
