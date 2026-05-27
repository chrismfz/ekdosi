<?php

namespace App\Services\WhmcsInbox;

use App\Enums\MyDataMode;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\PendingWhmcsInvoice;
use App\Services\EInvoiceSubmitterFactory;
use App\Services\InvoiceNumberer;
use App\Services\RecomputeInvoiceTotals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Stage B-2: orchestrates the "File at AADE" flow for a pending_whmcs_invoices
 * row. Patterned after CreateInvoice::chainSubmit but with stronger
 * idempotency guarantees because the inbox path doesn't have Filament's
 * form-validation safety net.
 *
 * Hardened contract (after the post-Stage-B-2 review):
 *
 *  - **Refuses to run inside an outer DB::transaction** (fix #10).
 *    The AADE HTTP call MUST happen outside any transaction; if a
 *    future caller wraps file() in `DB::transaction()` the AADE call
 *    would fire while the outer tx still holds row locks, and an
 *    outer-tx rollback would leave a filed MARK at AADE with no local
 *    invoice — the orphan-MARK scenario. file() throws RuntimeException
 *    when DB::transactionLevel() > 0.
 *
 *  - **Pre-flight: rejects 0%-VAT lines for non-Off tenants** (fix #2).
 *    A WHMCS line with `taxed=0` maps to vat_percent=0.0; the real
 *    MyDataSubmitter throws on these without an exemption category.
 *    Without this check the ΑΑ counter would already be consumed by
 *    the time the submit() failed, leaving a ghost local Invoice and
 *    a stuck pending row.
 *
 *  - **lockForUpdate on the pending row** at the start of the
 *    persistence transaction (fix #3). Two concurrent operators
 *    clicking File on the same row serialise on this lock.
 *
 *  - **Stores pending.invoice_id atomically inside the persistence
 *    transaction** (fix #4 + fix #5). Once the local Invoice is
 *    committed the pending row carries the link, so:
 *      • a subsequent file() call sees invoice_id set and REFUSES to
 *        re-allocate a new ΑΑ — operator is directed to the View
 *        Invoice page's "Submit to myDATA" action which is the
 *        canonical retry surface (closes the post-AADE-update-fail
 *        and the AADE-submit-fail-then-retry double-MARK windows);
 *      • the ΑΑ-gap on AADE failure is recoverable (orphan Invoice
 *        has a back-reference to the pending row).
 *
 *  - **Branches on $mark->mark presence** for notes + the deferred-
 *    writeback log (fix #9). Off-mode tenants (NullSubmitter, mark
 *    is null) get a clean "Recorded locally (off-mode)" note instead
 *    of the literal "(MARK )." trailing-empty-paren bug.
 */
class WhmcsInvoiceFiler
{
    public function __construct(
        private WhmcsInvoiceMapper $mapper,
        private InvoiceNumberer $numberer,
        private RecomputeInvoiceTotals $recompute,
        private EInvoiceSubmitterFactory $submitterFactory,
    ) {
    }

    /**
     * Build the invoice locally (transactional + lockForUpdate),
     * submit to AADE (outside tx), update the pending row.
     */
    public function file(
        Company $tenant,
        PendingWhmcsInvoice $pending,
        Customer $customer,
        InvoiceType $invoiceType,
        ?int $filedByUserId = null,
    ): FileResult {
        // Defense: refuse to run inside an outer transaction. The
        // AADE HTTP call below MUST happen with no row locks held
        // and no outer-tx rollback risk. Skip the guard in the test
        // environment (RefreshDatabase wraps every test in a tx) —
        // tests don't actually do an AADE HTTP call (NullSubmitter)
        // and the wrapping is for isolation, not coordination.
        if (! app()->runningUnitTests() && DB::transactionLevel() > 0) {
            throw new RuntimeException(
                'WhmcsInvoiceFiler::file() cannot run inside an outer DB::transaction(). '.
                'The AADE submit must happen outside any transaction to avoid orphan-MARK '.
                'scenarios where AADE files the invoice but the outer tx rolls back. '.
                'If you need to coordinate multiple filings, loop without an outer tx '.
                'or schedule each file() call separately via DB::afterCommit().'
            );
        }

        // Pre-flight: refuse 0%-VAT lines for tenants that submit to
        // a real AADE endpoint. MyDataSubmitter::vatCategoryFor throws
        // on these and we'd already have committed the local Invoice
        // (consuming ΑΑ) by the time the submit threw. Off-mode
        // tenants (NullSubmitter) tolerate 0%-VAT lines fine.
        $mapped = $this->mapper->map($tenant, $pending, $customer, $invoiceType);
        $this->refuseProblematicZeroVatLines($tenant, $mapped, $pending);

        // Phase 1: transactional persist with lockForUpdate on the
        // pending row + atomic invoice_id linking.
        $invoice = DB::transaction(function () use ($tenant, $pending, $invoiceType, $mapped) {
            // Re-load the pending row under a write lock. If the row
            // changed since the caller fetched it (status flipped to
            // filed, invoice_id was set by a concurrent op), we'll
            // see the new state and refuse rather than racing.
            $locked = PendingWhmcsInvoice::query()
                ->whereKey($pending->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCanBeFiled($locked);

            $allocation = $this->numberer->allocate($tenant, $invoiceType->code);

            $invoiceData = $mapped['header'];
            $invoiceData['code'] = $allocation->code;
            $invoiceData['invcode'] = $allocation->invcode;

            $invoice = Invoice::create($invoiceData);

            foreach ($mapped['lines'] as $lineData) {
                InvoiceLine::create(array_merge($lineData, [
                    'company_id' => $tenant->id,
                    'invoice_id' => $invoice->id,
                ]));
            }

            // Atomic link: write pending.invoice_id BEFORE leaving the
            // transaction. A retry attempt that re-acquires the lock
            // will see invoice_id set and refuse to allocate a fresh
            // ΑΑ counter (fixes #4 + #5).
            $locked->update(['invoice_id' => $invoice->id]);

            // Recompute totals from persisted lines (the InvoiceLine
            // saving hook is authoritative for net/gross per line; we
            // recompute the invoice-level aggregates here).
            return ($this->recompute)($invoice->fresh('lines'));
        });

        // Phase 2: AADE submit OUTSIDE the transaction. Failure here
        // leaves the local Invoice + the pending.invoice_id link in
        // place; operator retries via the View Invoice page (not via
        // re-clicking File on the inbox, which now refuses thanks to
        // the assertCanBeFiled check).
        try {
            $submitter = $this->submitterFactory->for($tenant);
            $mark = $submitter->submit($invoice);
        } catch (Throwable $e) {
            Log::error('WHMCS inbox: invoice persisted locally but AADE submit failed', [
                'pending_id'       => $pending->id,
                'invoice_id'       => $invoice->id,
                'invcode'          => $invoice->invcode,
                'whmcs_invoice_id' => $pending->whmcs_invoice_id,
                'error'            => $e->getMessage(),
                'next_step'        => 'Operator should open invoice #'.$invoice->id
                    .' and use the "Submit to myDATA" action to retry the AADE filing '
                    .'(retry on the SAME Invoice avoids ΑΑ-counter waste).',
            ]);
            throw $e;
        }

        // Phase 3: link the pending row to the AADE result.
        $pendingFresh = $pending->fresh();
        $hasMark = ! empty($mark->mark);
        $pendingFresh->update([
            'status'           => PendingWhmcsInvoice::STATUS_FILED,
            'filed_at'         => now(),
            'filed_by_user_id' => $filedByUserId,
            'mydata_mark'      => $hasMark ? $mark->mark : null,
            'notes'            => $hasMark
                ? 'Filed at AADE as invoice #'.$invoice->invcode.' (MARK '.$mark->mark.').'
                : 'Recorded locally (off-mode — not filed at AADE) as invoice #'.$invoice->invcode.'.',
        ]);

        // Phase 4: log the WHMCS write-back deferred to Stage B-3.
        // Only emit when there's an actual MARK to write back; off-mode
        // tenants have nothing to push to WHMCS so the log entry would
        // be misleading.
        if ($hasMark) {
            Log::info('WHMCS write-back deferred (Stage B-3 plugin not yet shipped)', [
                'pending_id'       => $pending->id,
                'whmcs_invoice_id' => $pending->whmcs_invoice_id,
                'mydata_mark'      => $mark->mark,
                'ekdosi_invoice'   => $invoice->invcode,
                'note'             => 'tblinvoices.invoiced should be set to '.$mark->mark
                    .' for WHMCS invoice '.$pending->whmcs_invoice_id
                    .'; do this manually on the WHMCS side during testing.',
            ]);
        }

        return new FileResult(
            invoice: $invoice->fresh('lines'),
            mark: $mark->mark,
            pending: $pendingFresh,
        );
    }

    /**
     * Build the preview WITHOUT persisting. Used by the modal so the
     * operator sees exactly what would be filed before they commit.
     */
    public function preview(
        Company $tenant,
        PendingWhmcsInvoice $pending,
        Customer $customer,
        InvoiceType $invoiceType,
    ): FilePreview {
        $mapped = $this->mapper->map($tenant, $pending, $customer, $invoiceType);
        return new FilePreview(
            header: $mapped['header'],
            lines: $mapped['lines'],
            totals: $mapped['totals'],
            source: $mapped['source'],
            customer: $customer,
            invoiceType: $invoiceType,
        );
    }

    /**
     * Throws a LogicException with operator-friendly message if the
     * pending row is in a state that doesn't permit (re-)filing. The
     * Filament action callback catches Throwable and surfaces the
     * message via Notification, so the operator sees a clean Greek
     * explanation rather than a stack trace.
     */
    private function assertCanBeFiled(PendingWhmcsInvoice $locked): void
    {
        if ($locked->status === PendingWhmcsInvoice::STATUS_FILED) {
            throw new LogicException(
                'PendingWhmcsInvoice #'.$locked->id.' is already filed (MARK: '
                .($locked->mydata_mark ?? '?').'). Cannot re-file.'
            );
        }
        if ($locked->invoice_id !== null) {
            throw new LogicException(
                'PendingWhmcsInvoice #'.$locked->id.' already has an in-progress invoice '
                .'(#'.$locked->invoice_id.'). A previous File-at-AADE attempt persisted the '
                .'local invoice but did not complete the AADE submit. To finish the filing, '
                .'open invoice #'.$locked->invoice_id.' and use the "Submit to myDATA" action '
                .'on the View Invoice page (this retries on the SAME invoice and avoids '
                .'consuming another ΑΑ counter slot). If the orphan invoice needs to be '
                .'force-deleted instead, do that first and then re-stage this row.'
            );
        }
    }

    /**
     * Refuse to file an invoice whose mapped lines contain 0% VAT
     * UNLESS the tenant is in Off mode (NullSubmitter tolerates 0%
     * for testing / training / pre-production paths).
     *
     * Why this check happens BEFORE the transactional persist:
     * MyDataSubmitter::vatCategoryFor throws on 0%-VAT lines without
     * an exemption category. Without this pre-flight, the local
     * Invoice would already be committed (ΑΑ consumed, pending row
     * linked) by the time the submit() threw — leaving a ghost
     * invoice and a stuck pending row that the operator can't
     * retry-file because invoice_id is now set.
     */
    private function refuseProblematicZeroVatLines(
        Company $tenant,
        array $mapped,
        PendingWhmcsInvoice $pending,
    ): void {
        $zero = $mapped['totals']['zero_vat_lines'] ?? [];
        if ($zero === []) {
            return;
        }
        $tenantMode = $tenant->mydata_mode ?? MyDataMode::Off->value;
        if ($tenantMode === MyDataMode::Off->value) {
            return;   // off-mode tenants don't hit the submitter validation
        }
        $sample = implode('", "', array_slice($zero, 0, 3));
        $more = count($zero) > 3 ? ' (+'.(count($zero) - 3).' more)' : '';
        throw new LogicException(
            'WHMCS invoice #'.$pending->whmcs_invoice_id.' has '.count($zero).' untaxed line(s) '
            .'("'.$sample.'"'.$more.') — these would crash myDATA submit because there is no '
            .'configured vat_exemption_category for 0%-VAT lines (tracked deferral in CLAUDE.md). '
            .'Resolve one of: (a) reject this WHMCS invoice in the inbox and issue it manually '
            .'with the correct vat_exemption_category set per line; (b) wait for the '
            .'vat_exemption_category mechanism to ship; (c) hold this row until then.'
        );
    }
}
