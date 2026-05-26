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
 *   submit(): persists a new MyDataMark row with action='INSERT' (or
 *   action='SKIPPED' for NullSubmitter so the audit trail still
 *   records the decision not to file). Updates the invoice's mirror
 *   columns (mydata_*) in the same DB transaction via forceFill — the
 *   replacement for the legacy MARK_AI0 trigger. Returns the MyDataMark
 *   on submission attempt, null if the submitter is configured to be a
 *   no-op for this tenant.
 *
 *   cancel(): symmetric. Persists action='CANCEL', flips invoice's
 *   mydata_state to 'CANCELLED', preserves the original MARK for audit.
 *
 *   testConnection(): for the Filament "Test connection" action.
 *   Returns true if credentials and endpoint are reachable; throws or
 *   returns false otherwise. NullSubmitter returns true (nothing to
 *   test).
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
