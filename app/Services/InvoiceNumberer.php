<?php

namespace App\Services;

use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Support\MyData\Codes;
use App\Support\ProvisionalCode;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
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
 * ──── ΑΑ GAP POLICY — GAPLESS-AT-SEND (reverses MON-4, accountant-required) ────
 *
 * The ΑΑ is allocated when a document is TRANSMITTED to myDATA/provider (see
 * {@see assign()}), NOT at draft creation. Until then a draft or a finalized-but-
 * unsent document carries a PROVISIONAL identity ({@see ProvisionalCode},
 * «ΠΡΟΣ-ΤΠΥ-{id}», `code` NULL) and consumes no number — so the sequence the
 * ΑΑΔΕ sees is always continuous, whatever is drafted, abandoned or cancelled.
 * (A strict accountant reads a gap in the transmitted series as a hidden/deleted
 * document; the legacy allocate-at-draft policy this replaces produced exactly
 * such gaps.) Non-AADE tenants have no transmission event, so they allocate at
 * FINALISATION instead (the finalize action).
 *
 * A definitively-rejected send returns its reserved number to the pool
 * ({@see release()}, decrement-if-top), so a rejection during setup leaves no
 * gap either. An AMBIGUOUS failure (timeout that may have filed) KEEPS the
 * number — the in-doubt recovery adopts the real MARK by (series, ΑΑ), and
 * releasing a number the provider actually used would risk a duplicate.
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
     *
     * $allowMovementType — this numberer is shared by the monetary invoice flow
     * AND the Delivery Notes flow. Monetary callers use the default (false) so a
     * movement-only 9.x type is rejected (MYD-003); the delivery-note creators
     * pass true because a Δελτίο Αποστολής legitimately carries a 9.x type.
     */
    public function allocate(Company $company, string $invoiceTypeCode, bool $allowMovementType = false): InvoiceAllocation
    {
        if (! $this->db->transactionLevel()) {
            throw new RuntimeException(
                'InvoiceNumberer::allocate() must run inside a DB transaction. '
                .'Wrap the IssueInvoice action in DB::transaction(...).'
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
                .'Cannot allocate ΑΑ.',
                $invoiceTypeCode,
                $company->id,
                $company->slug,
            ));
        }

        // Defence-in-depth for MYD-003: a movement-only 9.x Δελτίο Αποστολής is
        // NOT a monetary document — it must never be issued through the invoice
        // flow (it belongs to DeliveryNoteSubmitter). Every MONETARY creator
        // (CreateInvoice, IssueCreditNote, ConvertQuoteToInvoice, StageService-
        // Renewal, WhmcsInvoiceFiler) funnels through here with the default
        // $allowMovementType=false, so this single guard closes every monetary
        // path even if a UI picker forgot to filter. The delivery-note flow
        // shares this numberer and legitimately allocates a 9.x ΑΑ — it opts out
        // with $allowMovementType=true. Thrown BEFORE the counter bump below, so
        // a rejected type leaves no ΑΑ gap.
        if (! $allowMovementType && Codes::isMovementOnlyType($type->mydata_type)) {
            throw new RuntimeException(sprintf(
                'Invoice-type code=%s (myDATA %s) is a movement-only Δελτίο '
                .'Αποστολής and cannot be issued as a monetary invoice. '
                .'Use the Delivery Notes flow instead.',
                $type->code,
                $type->mydata_type,
            ));
        }

        $allocatedAa = $type->invcount;
        $invcode = $type->code.$allocatedAa;

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

    /**
     * Allocate the real ΑΑ onto a not-yet-numbered invoice at transmission time
     * (gapless-at-send). Writes `code`/`invcode`/`series` and FREEZES the series
     * here — the `creating` model hook cannot, because at draft creation the row
     * carries only a provisional invcode + null code.
     *
     * Idempotent: a retry after an ambiguous send keeps the number it already
     * reserved (never re-allocates a second one). Wraps its own short transaction —
     * the caller must NOT hold it open across the outbound HTTP call (the row lock
     * inside allocate() would block all other issuance).
     *
     * RETURNS whether it actually allocated a number NOW (true), or found one already
     * present and no-op'd (false). The caller MUST use this to decide whether a later
     * {@see release()} is undoing ITS OWN reservation: a document can reach a submitter
     * ALREADY numbered — a legacy-imported row, or one finalized locally at a
     * non-transmitting tenant then submitted after go-live — and blindly releasing that
     * would strip the ΑΑ off a document this attempt never numbered (see release()).
     */
    public function assign(Invoice $invoice, bool $allowMovementType = false): bool
    {
        return $this->reserve($invoice, $invoice->company, (string) $invoice->invoiceType->code, $allowMovementType);
    }

    /**
     * Return a reserved ΑΑ to the pool after a DEFINITIVE rejection, reverting the
     * document to its provisional identity so the next attempt re-allocates. The
     * counter is decremented ONLY when this was the last number handed out
     * (`invcount === code + 1`); if a concurrent issuance already took the next
     * number, leaving a gap is far safer than reusing one (renumbering a document
     * that another request may already have FILED under the next number would risk
     * a duplicate ΑΑ — the one thing worse than a gap).
     *
     * CALL ONLY when {@see assign()} for the same attempt returned true (this attempt
     * reserved the number). A document that arrived ALREADY numbered (assign no-op'd →
     * false) is NOT ours to revert: releasing it strips the ΑΑ off, and renumbers, a
     * document that was validly issued earlier — a legacy-imported row, or one finalized
     * at a mode='off'/'none' tenant then submitted once the tenant went live. The
     * submitters gate every release() on the assign() return value for exactly this.
     *
     * LIMITATION (honest): this is gapless under SERIAL issuance — which the common
     * flows are (a queue worker; the per-invoice single-flight lock). It is NOT
     * gapless if two documents of the SAME series are submitted CONCURRENTLY (two
     * FPM requests) and the earlier-numbered one is then rejected: its number is
     * no longer the top, so a rare gap remains. At these tenants' volumes
     * (~70 docs/month, few operators) that race is negligible; a fully-gapless
     * guarantee under concurrency is a BACKLOG item (it needs safe renumbering).
     *
     * NEVER call on an ambiguous failure — see the class ΑΑ GAP POLICY.
     */
    public function release(Invoice $invoice): void
    {
        $this->revert($invoice, $invoice->invoice_type_id, $invoice->invoiceType?->code);
    }

    /**
     * Delivery-note twin of {@see assign()} — reserve the real ΑΑ on a not-yet-numbered
     * Δελτίο Αποστολής at transmission time (gapless-at-send, Phase 2). Reads the
     * delivery type relation/FK and always opts into the 9.x movement type (a Δελτίο
     * Αποστολής legitimately carries one, MYD-003). Same idempotency + return contract
     * as assign().
     */
    public function assignDelivery(DeliveryNote $note): bool
    {
        return $this->reserve($note, $note->company, (string) $note->deliveryType->code, allowMovementType: true);
    }

    /**
     * Delivery-note twin of {@see release()} — same decrement-if-top rule, same
     * SERIAL-issuance limitation, and the SAME "only your own reservation" contract:
     * call only when assignDelivery() returned true for this attempt.
     */
    public function releaseDelivery(DeliveryNote $note): void
    {
        $this->revert($note, $note->delivery_type_id, $note->deliveryType?->code);
    }

    /**
     * Shared reserve core for {@see assign()} / {@see assignDelivery()}. `$doc` is an
     * Invoice or a DeliveryNote — both carry `code`/`invcode`/`series` and are numbered
     * off the same `invoice_types` counter; only the type relation/FK differs, passed in.
     * Returns true iff it allocated a number now (false = already numbered, no-op).
     */
    private function reserve(Model $doc, Company $company, string $typeCode, bool $allowMovementType): bool
    {
        if ($doc->code !== null) {
            return false;
        }

        $this->db->transaction(function () use ($doc, $company, $typeCode, $allowMovementType): void {
            $allocation = $this->allocate($company, $typeCode, $allowMovementType);
            $doc->forceFill([
                'code' => $allocation->code,
                'invcode' => $allocation->invcode,
                'series' => $allocation->series,
            ])->save();
        });

        return true;
    }

    /**
     * Shared revert core for {@see release()} / {@see releaseDelivery()}. Decrements the
     * counter only when the reverted number was the top one handed out, and puts the
     * document back to its provisional identity. See release() for the "only your own
     * reservation" contract the callers enforce.
     */
    private function revert(Model $doc, int|string|null $typeId, ?string $typeCode): void
    {
        if ($doc->code === null) {
            return;
        }

        $this->db->transaction(function () use ($doc, $typeId, $typeCode): void {
            $type = InvoiceType::query()
                ->whereKey($typeId)
                ->lockForUpdate()
                ->first();

            if ($type !== null && (int) $type->invcount === (int) $doc->code + 1) {
                $this->db->table('invoice_types')
                    ->where('id', $type->id)
                    ->update(['invcount' => $this->db->raw('invcount - 1')]);
            }

            $doc->forceFill([
                'code' => null,
                'series' => null,
                'invcode' => ProvisionalCode::make($typeCode, $doc->getKey()),
            ])->save();
        });
    }
}
