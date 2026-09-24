<?php

namespace App\Support\Pdf;

use App\Models\Company;
use App\Models\Invoice;

/**
 * DOC-2/DOC-3 (AUDIT): the PDF state banner as a function of BOTH orthogonal
 * statuses (local_status × mydata_state) AND the tenant's e-invoicing channel
 * — the template used to key on mydata_state alone, which produced two legal
 * hazards and one permanent mislabel:
 *
 *   - locally-cancelled + VALID printed as a fully valid certified invoice
 *     (no ΑΚΥΡΩΘΕΝ banner — a customer could receive a clean copy of a
 *     document the business voided);
 *   - locally-cancelled + null printed «ΠΡΟΧΕΙΡΟ» (fails safe, wrong label);
 *   - issued-but-not-filed printed «ΠΡΟΧΕΙΡΟ — ΔΕΝ ΕΧΕΙ ΥΠΟΒΛΗΘΕΙ ΣΤΗ myDATA»
 *     forever — including EVERY legally issued invoice of a non-myDATA tenant
 *     (einvoice_provider none/ee-peppol or mydata_mode Off), where myDATA is
 *     not even a concept.
 *
 * Kinds:
 *   cancelled       — voided locally OR at AADE. Wins over everything. When
 *                     the AADE side is still VALID, note=cancel_pending_mydata
 *                     so the paper says the AADE cancellation is pending.
 *   informal        — a document of an informal (non-fiscal) series, draft or
 *                     issued: «ΑΤΥΠΟ — δεν αποτελεί φορολογικό στοιχείο». Never
 *                     «pending myDATA» (it is never filed).
 *   draft           — local_status draft, never filed: not issued yet
 *                     (provider-agnostic wording — no myDATA reference).
 *   pending_mydata  — ISSUED (active) but no AADE state yet, on a tenant that
 *                     actually files to AADE: a real invoice whose submission
 *                     is pending — NOT a draft.
 *   none            — nothing to warn about (e.g. VALID+active, or an issued
 *                     invoice on a non-filing tenant).
 *
 * Deliberately a static helper called FROM the Blade template so every render
 * path (renderer, mail attachment, public route, tests that view() directly)
 * gets the same logic without threading a new view variable everywhere.
 */
class InvoiceBannerState
{
    /** @return array{kind: string, note: ?string} */
    public static function for(Invoice $invoice): array
    {
        $local = (string) $invoice->local_status;
        $aade = $invoice->mydata_state;

        if ($local === 'cancelled' || $aade === 'CANCELLED') {
            return [
                'kind' => 'cancelled',
                // Voided locally while AADE still says VALID → the operator
                // still owes AADE a cancel; say so instead of hiding it.
                'note' => ($local === 'cancelled' && $aade === 'VALID') ? 'cancel_pending_mydata' : null,
            ];
        }

        if ($invoice->isInformal()) {
            return ['kind' => 'informal', 'note' => null];
        }

        if ($local === 'draft' && $aade === null) {
            return ['kind' => 'draft', 'note' => null];
        }

        if ($aade === null && $local === 'active' && self::filesToAade($invoice->company)) {
            return ['kind' => 'pending_mydata', 'note' => null];
        }

        return ['kind' => 'none', 'note' => null];
    }

    /**
     * Does this tenant's channel actually submit electronically (direct
     * myDATA OR a live ΥΠΑΗΕΣ provider)? This is THE predicate the submit
     * action's visibility uses, so the "pending submission" banner appears on
     * exactly the invoices that CAN still be filed — and never on a non-filing
     * tenant (none / ee-peppol / mydata mode Off / provider mode off), for
     * which "no MARK" is the normal, permanent state of a legal invoice.
     *
     * Delegates to Company::submitsElectronically() rather than re-deriving
     * the rule — a hand-rolled copy previously flagged gr-provider tenants in
     * mode=off as filing (they don't), reintroducing the DOC-3 bug.
     */
    private static function filesToAade(?Company $tenant): bool
    {
        return (bool) $tenant?->submitsElectronically();
    }
}
