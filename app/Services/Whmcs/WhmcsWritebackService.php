<?php

namespace App\Services\Whmcs;

use App\Exceptions\Whmcs\WhmcsNotConfigured;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\PendingWhmcsInvoice;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single place that pushes a filed MARK back to WHMCS
 * (the bridge's mod_ekdosi_invoice_marks table, NOT the legacy
 * tblinvoices.invoiced flag) via the ekdosi_bridge plugin and records
 * the outcome on the pending row's whmcs_writeback_* columns.
 *
 * Extracted from WhmcsInvoiceFiler::writebackInvoicedFlag so BOTH issue
 * paths share one implementation:
 *
 *   1. Direct file()  — WhmcsInvoiceFiler already flips the pending row to
 *      status=filed itself (Phase 3) and then calls pushMark() (Phase 4).
 *   2. Draft-first lifecycle — a draft created by WhmcsInvoiceFiler::createDraft
 *      (status=drafted, carrying invoices.whmcs_pending_id) is later issued
 *      through the normal lifecycle; MyDataSubmitter calls
 *      syncFiledFromLifecycle() on the VALID persist, which flips the pending
 *      row drafted→filed AND pushes the MARK.
 *
 * Non-fatal on EVERY failure: the AADE filing is already committed and is the
 * legal truth; a write-back failure only loses the downstream WHMCS bookkeeping
 * (recorded as whmcs_writeback_state='failed' for a future retry-sweep).
 */
class WhmcsWritebackService
{
    public function __construct(
        private readonly WhmcsBridgeClientFactory $bridgeFactory,
    ) {}

    /**
     * Entry point for the draft-first LIFECYCLE path. Given an invoice that
     * just reached myDATA VALID and carries whmcs_pending_id, flip its linked
     * pending row drafted→filed and push the MARK back to WHMCS.
     *
     * Safe to call unconditionally after any VALID persist: it no-ops when the
     * invoice has no whmcs_pending_id (manually-created invoices), when the
     * pending row is gone, when there's no MARK, or when the row isn't in the
     * single-draft 'drafted' state (e.g. a multi-party SPLIT row — whose
     * one-WHMCS-invoice→many-MARKs write-back is a separate, deferred design).
     *
     * Never throws: a write-back hiccup must not mask a successful AADE filing.
     */
    public function syncFiledFromLifecycle(Invoice $invoice, ?string $mark): void
    {
        try {
            if ($invoice->whmcs_pending_id === null) {
                return;   // not a WHMCS-sourced invoice
            }
            if ($mark === null || $mark === '') {
                return;   // off-mode / no MARK to push
            }

            $tenant = $invoice->company;
            if ($tenant === null) {
                return;
            }

            $pending = PendingWhmcsInvoice::query()
                ->where('company_id', $invoice->company_id)
                ->whereKey($invoice->whmcs_pending_id)
                ->first();

            if ($pending === null) {
                return;
            }

            // Only the single-draft path. A multi-party SPLIT row maps one
            // WHMCS invoice to many ekdosi invoices/MARKs — the bridge keys its
            // mark store by WHMCS invoice id (one MARK per invoice), so that
            // write-back needs its own design (tracked follow-up). Filed/other
            // states: nothing to do.
            if ($pending->status !== PendingWhmcsInvoice::STATUS_DRAFTED) {
                if ($pending->status === PendingWhmcsInvoice::STATUS_SPLIT) {
                    Log::info('WHMCS write-back skipped: split row (one WHMCS invoice → many MARKs is a separate design)', [
                        'pending_id' => $pending->id,
                        'invoice_id' => $invoice->id,
                        'mydata_mark' => $mark,
                    ]);
                }

                return;
            }

            // Flip drafted→filed (the legal-audit transition). The observer
            // permits this because the row's ORIGINAL status is 'drafted', not
            // 'filed'. After this save the row is audit-frozen except the
            // whmcs_writeback_* columns, which pushMark() updates next.
            $pending->update([
                'status' => PendingWhmcsInvoice::STATUS_FILED,
                'filed_at' => now(),
                'mydata_mark' => $mark,
                'whmcs_writeback_state' => PendingWhmcsInvoice::WRITEBACK_PENDING,
                'notes' => 'Εκδόθηκε & υποβλήθηκε στο myDATA ως '.$invoice->invcode.' (MARK '.$mark.').',
            ]);

            $this->pushMark($tenant, $pending->fresh(), $invoice, $mark);
        } catch (Throwable $e) {
            // Defensive: even an unexpected failure in the flip/lookup must not
            // bubble into the submit() choke-point and mask the VALID filing.
            Log::error('WHMCS lifecycle write-back failed (AADE filing already complete)', [
                'invoice_id' => $invoice->id,
                'whmcs_pending_id' => $invoice->whmcs_pending_id,
                'mydata_mark' => $mark,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Push the MARK to the bridge's mod_ekdosi_invoice_marks table (NOT the
     * legacy tblinvoices.invoiced flag) via the bridge plugin and record the
     * outcome on the pending row's whmcs_writeback_* columns. The pending row
     * is expected to already be status=filed; only the write-back bookkeeping
     * columns are touched (allowed past the audit freeze).
     *
     * Shared by WhmcsInvoiceFiler::file() (Phase 4) and the lifecycle path.
     */
    public function pushMark(
        Company $tenant,
        PendingWhmcsInvoice $pending,
        Invoice $invoice,
        string $mark,
    ): void {
        try {
            $client = $this->bridgeFactory->for($tenant);
        } catch (WhmcsNotConfigured $e) {
            Log::info('WHMCS write-back skipped: bridge plugin not configured', [
                'pending_id' => $pending->id,
                'whmcs_invoice_id' => $pending->whmcs_invoice_id,
                'mydata_mark' => $mark,
                'reason' => $e->getMessage(),
            ]);
            $pending->update([
                'whmcs_writeback_state' => PendingWhmcsInvoice::WRITEBACK_SKIPPED,
                'whmcs_writeback_error' => null,
            ]);

            return;
        }

        try {
            $client->setInvoiced($pending->whmcs_invoice_id, $mark, $invoice->invcode);
            Log::info('WHMCS write-back succeeded', [
                'pending_id' => $pending->id,
                'whmcs_invoice_id' => $pending->whmcs_invoice_id,
                'mydata_mark' => $mark,
                'ekdosi_invoice' => $invoice->invcode,
            ]);
            $pending->update([
                'whmcs_writeback_state' => PendingWhmcsInvoice::WRITEBACK_SUCCEEDED,
                'whmcs_writeback_error' => null,
            ]);
        } catch (Throwable $e) {
            Log::error('WHMCS write-back failed (AADE filing already complete)', [
                'pending_id' => $pending->id,
                'whmcs_invoice_id' => $pending->whmcs_invoice_id,
                'mydata_mark' => $mark,
                'ekdosi_invoice' => $invoice->invcode,
                'error' => $e->getMessage(),
                'next_step' => 'Re-trigger the write-back from the Ekdosi Bridge admin page '
                    .'for WHMCS invoice '.$pending->whmcs_invoice_id
                    .' (MARK '.$mark.') — it stores the MARK in mod_ekdosi_invoice_marks, '
                    .'never in the legacy tblinvoices.invoiced flag.',
            ]);
            $pending->update([
                'whmcs_writeback_state' => PendingWhmcsInvoice::WRITEBACK_FAILED,
                'whmcs_writeback_error' => $e->getMessage(),
            ]);
        }
    }
}
