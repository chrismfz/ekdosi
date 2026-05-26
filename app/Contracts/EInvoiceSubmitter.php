<?php

namespace App\Contracts;

use App\Models\Invoice;
use App\Models\MyDataMark;

/**
 * Contract for submitting invoices to an electronic invoicing platform.
 *
 * Implementations:
 *   - App\Services\NullSubmitter       — no-op (off mode, none provider)
 *   - App\Services\MyDataSubmitter     — Greek myDATA via firebed (PR #25)
 *   - App\Services\PeppolSubmitter     — Estonian PEPPOL via RIK (future)
 *
 * The factory App\Services\EInvoiceSubmitterFactory resolves the right
 * implementation per-tenant by inspecting Company->einvoice_provider
 * and Company->mydata_mode.
 *
 * Contract semantics:
 *
 *   submit(): always returns a MyDataMark — implementations never
 *   return null. The row's `mydata_action` distinguishes outcomes:
 *     - 'INSERT'  — real AADE filing succeeded; `mark` holds the
 *                   AADE-issued MARK
 *     - 'SKIPPED' — NullSubmitter recorded a deliberate non-filing
 *                   (mode=off / provider=none); `mark` is null
 *
 *   The invoice's `mydata_*` mirror columns (the cache of the latest
 *   submission state) are updated ONLY by real-submitter
 *   implementations (MyDataSubmitter etc.) — the replacement for the
 *   legacy MARK_AI0 trigger. NullSubmitter deliberately LEAVES them
 *   null so the UI can distinguish "we filed and got VALID" from
 *   "we chose not to file" by reading the latest MyDataMark's
 *   action, not by mistaking mirror-column state for filing status.
 *
 *   cancel(): symmetric. `action='CANCEL'` for real submitters
 *   (flips invoice's `mydata_state` to 'CANCELLED', preserves the
 *   original MARK for audit). `action='SKIPPED_CANCEL'` for
 *   NullSubmitter (no mirror-column changes — see above).
 *
 *   testConnection(): for the Filament "Test connection" action.
 *   Real submitters POST a minimal query to verify credentials +
 *   endpoint reachability. NullSubmitter returns true (it has
 *   nothing to connect to).
 *
 * Return type note: the `?` on the return types is for forward
 * compatibility with future submitters that might genuinely have no
 * audit row to return (e.g. a hypothetical batched submitter that
 * defers persisting). Current implementations all return a non-null
 * MyDataMark.
 */
interface EInvoiceSubmitter
{
    /**
     * Submit a freshly-issued invoice for filing.
     *
     * @throws \App\Exceptions\Aade\AadeRegistryException on credential
     *         or AADE failures (real submitters only)
     */
    public function submit(Invoice $invoice): ?MyDataMark;

    /**
     * Cancel a previously-submitted invoice. `$reason` is included in
     * the AADE payload where the protocol supports it; otherwise stored
     * in the local mydata_marks row for audit.
     */
    public function cancel(Invoice $invoice, string $reason = ''): ?MyDataMark;

    /**
     * Smoke-test the submission path. Real submitters POST a minimal
     * query (e.g. RequestTransmittedDocs for myDATA) to verify
     * credentials + endpoint reachability. NullSubmitter returns true
     * (trivially "works"). Used by Filament's "Test connection"
     * action on the Company form.
     */
    public function testConnection(): bool;
}
