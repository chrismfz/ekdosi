<?php

namespace App\Services\Whmcs;

use App\Exceptions\Whmcs\WhmcsNotConfigured;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\PendingWhmcsInvoice;
use Illuminate\Support\Facades\Log;
use RuntimeException;
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
     * Entry point for the CANCEL path: ekdosi just cancelled an invoice at AADE.
     * Re-push the SAME MARK with state='cancelled' so the WHMCS badge shows
     * «ΑΚΥΡΩΜΕΝΟ» instead of a stale "valid" MARK. Same MARK keeps inbound.php's
     * idempotent guard happy (it only refuses a DIFFERENT mark).
     *
     * No-ops for non-WHMCS invoices, a missing pending row, a split row, or when
     * there's no MARK to flag. Never throws — a write-back hiccup must not mask
     * the completed AADE cancellation.
     */
    public function syncCancelledFromLifecycle(Invoice $invoice): void
    {
        try {
            $tenant = $invoice->company;
            if ($tenant === null) {
                return;
            }

            // Resolve the pending row by EITHER link: the draft path sets
            // invoice.whmcs_pending_id (forward), the inbox file() path sets
            // pending.invoice_id (reverse). A cancel can hit either origin, so
            // both must be covered — otherwise a filer-path invoice keeps a stale
            // green «Στο AADE» badge after an AADE cancellation. Two DETERMINISTIC
            // lookups (forward first) rather than one OR, so a stale duplicate
            // that also points here can't make ->first() pick an arbitrary row.
            $pending = null;
            if ($invoice->whmcs_pending_id !== null) {
                $pending = PendingWhmcsInvoice::query()
                    ->where('company_id', $invoice->company_id)
                    ->whereKey($invoice->whmcs_pending_id)
                    ->first();
            }
            if ($pending === null) {
                $pending = PendingWhmcsInvoice::query()
                    ->where('company_id', $invoice->company_id)
                    ->where('invoice_id', $invoice->id)
                    ->orderBy('id')
                    ->first();
            }

            if ($pending === null || $pending->whmcs_invoice_id === null) {
                return;
            }
            if ($pending->status === PendingWhmcsInvoice::STATUS_SPLIT) {
                return;   // one WHMCS invoice → many MARKs: separate design
            }

            $mark = (string) ($invoice->mydata_mark ?? $pending->mydata_mark ?? '');
            if ($mark === '') {
                return;   // never filed at AADE → nothing to flag cancelled
            }

            $this->pushMark($tenant, $pending, $invoice, $mark, 'cancelled');
        } catch (Throwable $e) {
            Log::error('WHMCS cancel write-back failed (AADE cancellation already complete)', [
                'invoice_id' => $invoice->id,
                'whmcs_pending_id' => $invoice->whmcs_pending_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * WH-7: RETRY a write-back that previously failed (or is stuck 'pending').
     * The AADE filing is already done; this just re-attempts the WHMCS
     * bookkeeping that the badge/ledger depend on. Reuses pushMark(), so it only
     * touches the two audit-freeze-whitelisted columns and re-derives the
     * push state (cancelled vs active) from the invoice's current AADE state.
     *
     * Backs both the per-row Filament action and the whmcs:retry-writebacks
     * batch command. Throws a RuntimeException (operator-facing) when the row
     * isn't retryable — a split row, a non-failed/pending state, no linked
     * invoice, or no MARK to push; the caller surfaces the message.
     *
     * @return string the resulting whmcs_writeback_state after the attempt
     */
    public function retryWriteback(PendingWhmcsInvoice $pending): string
    {
        if ($pending->status === PendingWhmcsInvoice::STATUS_SPLIT) {
            throw new RuntimeException(
                'Το WHMCS #'.$pending->whmcs_invoice_id.' διαχωρίστηκε σε πολλά παραστατικά — '
                .'η επιστροφή ΜΑΡΚ για split τιμολόγια είναι ξεχωριστός σχεδιασμός (δεν υποστηρίζεται ακόμη).'
            );
        }

        if (! in_array($pending->whmcs_writeback_state, [
            PendingWhmcsInvoice::WRITEBACK_FAILED,
            PendingWhmcsInvoice::WRITEBACK_PENDING,
        ], true)) {
            throw new RuntimeException(
                'Το WHMCS #'.$pending->whmcs_invoice_id.' δεν έχει αποτυχημένη/εκκρεμή επιστροφή ΜΑΡΚ '
                .'(κατάσταση: '.($pending->whmcs_writeback_state ?? '—').') — δεν χρειάζεται επανάληψη.'
            );
        }

        $tenant = $pending->company;
        if ($tenant === null) {
            throw new RuntimeException('Το WHMCS #'.$pending->whmcs_invoice_id.' δεν έχει εταιρία.');
        }

        $invoice = $this->resolveInvoiceFor($pending);
        if ($invoice === null) {
            throw new RuntimeException(
                'Το WHMCS #'.$pending->whmcs_invoice_id.' δεν έχει συνδεδεμένο παραστατικό ekdosi — '
                .'δεν υπάρχει ΜΑΡΚ για επιστροφή.'
            );
        }

        $mark = (string) ($invoice->mydata_mark ?? $pending->mydata_mark ?? '');
        if ($mark === '') {
            throw new RuntimeException(
                'Το WHMCS #'.$pending->whmcs_invoice_id.' δεν έχει ΜΑΡΚ ακόμη (δεν υποβλήθηκε στο myDATA) — '
                .'δεν υπάρχει τίποτα να επιστραφεί.'
            );
        }

        // Refuse (keeping the row FAILED, so it stays retryable) if the bridge
        // isn't configured RIGHT NOW: otherwise pushMark would downgrade FAILED →
        // SKIPPED, and neither retry surface targets SKIPPED — the MARK would be
        // stranded once the bridge came back. Fix the bridge first, then retry.
        try {
            $this->bridgeFactory->for($tenant);
        } catch (WhmcsNotConfigured $e) {
            throw new RuntimeException(
                'Η γέφυρα WHMCS δεν είναι ρυθμισμένη για το #'.$pending->whmcs_invoice_id.' '
                .'('.$e->getMessage().') — ρύθμισε τη γέφυρα και ξαναπροσπάθησε.'
            );
        }

        // Re-derive the push state from the invoice's CURRENT AADE state — a
        // cancelled invoice must flag «ΑΚΥΡΩΜΕΝΟ», not re-assert a live MARK.
        $state = $invoice->mydata_state === 'CANCELLED' ? 'cancelled' : 'active';

        $this->pushMark($tenant, $pending, $invoice, $mark, $state);

        return (string) $pending->fresh()->whmcs_writeback_state;
    }

    /**
     * Resolve the ekdosi invoice a (non-split) pending row's MARK belongs to,
     * covering BOTH links: forward (pending.invoice_id, the file() path) and
     * reverse (invoices.whmcs_pending_id, the draft-first lifecycle path).
     */
    private function resolveInvoiceFor(PendingWhmcsInvoice $pending): ?Invoice
    {
        if ($pending->invoice_id !== null) {
            $invoice = $pending->invoice()->first();
            if ($invoice !== null) {
                return $invoice;
            }
        }

        return Invoice::query()
            ->where('company_id', $pending->company_id)
            ->where('whmcs_pending_id', $pending->id)
            ->orderBy('id')
            ->first();
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
        string $state = 'active',
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

        // The official παραστατικό PDF lives on ekdosi; hand the bridge a signed
        // public URL so it can link it (no file copy). Best-effort — a URL build
        // failure must not block the MARK write-back.
        $pdfUrl = null;
        try {
            $pdfUrl = $invoice->publicPdfUrl();
        } catch (Throwable $e) {
            // leave null — the MARK/state write-back still proceeds
        }

        try {
            $client->setInvoiced($pending->whmcs_invoice_id, $mark, $invoice->invcode, $state, $pdfUrl);
            Log::info('WHMCS write-back succeeded', [
                'pending_id' => $pending->id,
                'whmcs_invoice_id' => $pending->whmcs_invoice_id,
                'mydata_mark' => $mark,
                'ekdosi_invoice' => $invoice->invcode,
                'state' => $state,
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
                'next_step' => 'Retry from ekdosi: the WHMCS inbox row for invoice '
                    .$pending->whmcs_invoice_id.' now shows «Επιστροφή ΜΑΡΚ: Απέτυχε» — '
                    .'use its «Επανάληψη επιστροφής ΜΑΡΚ» action, or run '
                    .'`php artisan whmcs:retry-writebacks --tenant='.($tenant->slug ?? '?').'`. '
                    .'The bridge stores the MARK in mod_ekdosi_invoice_marks (never the legacy '
                    .'tblinvoices.invoiced flag).',
            ]);
            $pending->update([
                'whmcs_writeback_state' => PendingWhmcsInvoice::WRITEBACK_FAILED,
                'whmcs_writeback_error' => $e->getMessage(),
            ]);
        }
    }
}
