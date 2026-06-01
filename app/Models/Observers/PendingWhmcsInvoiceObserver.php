<?php

namespace App\Models\Observers;

use App\Models\PendingWhmcsInvoice;
use LogicException;

/**
 * PR #31 (Stage B-1): strict legal-audit lock against mutating filed
 * rows from ANY caller, not just the ingestor.
 *
 * The ingestor's audit-freeze (PendingWhmcsInvoice::isAuditFrozen())
 * is policy: it decides whether a re-ingest refreshes the payload
 * from WHMCS. Useful for keeping pending rows current.
 *
 * THIS observer is the invariant: status=filed rows reference a
 * payload that was sent to AADE and produced a real MARK. The local
 * payload MUST match what AADE has on file, forever, for reconciliation
 * and legal-audit purposes. Any code path that mutates a filed row's
 * payload (a tinker session, a Stage B-2 "refresh from WHMCS" action,
 * a future reconciliation job, an artisan command someone writes
 * three months from now) silently diverges our DB from AADE's
 * authoritative record.
 *
 * The only writes allowed on a filed row are timestamp touches
 * (updated_at bumps) so the ingestor can record "WHMCS pinged us
 * again about this after we filed it" as a signal in the inbox.
 *
 * Throws a LogicException - this is a developer bug, not a
 * recoverable runtime condition. The right reaction to a
 * "tried to mutate filed row" error is to fix the calling code
 * (cancel + re-issue a new pending row if WHMCS-side state really
 * has changed), not to retry.
 */
class PendingWhmcsInvoiceObserver
{
    public function saving(PendingWhmcsInvoice $row): void
    {
        // getOriginal() reflects the values AS LOADED from the DB,
        // BEFORE any in-memory mutation. So "was the row filed when
        // we read it?" is the right question, not "is it filed now?"
        // (which would let us miss a status->filed transition that
        // ALSO mutates payload in the same save).
        if ($row->getOriginal('status') !== PendingWhmcsInvoice::STATUS_FILED) {
            return;
        }

        // Allow updated_at AND the WHMCS write-back bookkeeping
        // columns to change. Every other dirty attribute is a
        // violation. We compare against $dirty's keys (not a
        // hardcoded "frozen attributes" list) so a future schema
        // addition is automatically covered - the safer default.
        //
        // The write-back columns are carved out deliberately: the
        // legal-audit truth is the payload + status + filed_at +
        // mydata_mark snapshot, NOT whether the downstream WHMCS mark
        // store (mod_ekdosi_invoice_marks) got updated. Stage B-3 runs the
        // write-back AFTER the status=filed transition and records
        // its outcome (succeeded/failed/pending) on these columns;
        // a future retry-sweep command can re-run a failed write-back
        // and flip the state without touching the frozen audit
        // snapshot.
        $dirty = $row->getDirty();
        unset(
            $dirty['updated_at'],
            $dirty['whmcs_writeback_state'],
            $dirty['whmcs_writeback_error'],
        );

        if (empty($dirty)) {
            return;
        }

        throw new LogicException(sprintf(
            'PendingWhmcsInvoice #%d is audit-frozen (status=filed, MARK=%s); cannot mutate %s. '
            .'The payload at filing time is the legal-audit truth against AADE. '
            .'If WHMCS-side state has materially changed, create a new pending row '
            .'instead of mutating this one.',
            $row->id,
            $row->mydata_mark ?? '<missing>',
            implode(', ', array_keys($dirty)),
        ));
    }
}
