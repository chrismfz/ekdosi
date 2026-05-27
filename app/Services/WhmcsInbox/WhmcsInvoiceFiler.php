<?php

namespace App\Services\WhmcsInbox;

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
use Throwable;

/**
 * Stage B-2: orchestrates the "File at AADE" flow for a pending_whmcs_invoices
 * row. Mirrors the CreateInvoice page's chainSubmit pattern:
 *   1. Build Invoice + InvoiceLine[] inside a DB transaction (with
 *      InvoiceNumberer holding a row lock for the ΑΑ allocation)
 *   2. Submit to AADE OUTSIDE the transaction (HTTP round-trip while
 *      holding row locks blocks every other writer for seconds, and
 *      if the AADE call succeeds but the outer tx rolls back we have
 *      a filed MARK with no local invoice - the orphan-MARK case the
 *      legacy MARK_AI0 trigger replacement was designed to prevent)
 *   3. Update the pending row with status=filed + MARK
 *   4. LOG the would-be WHMCS write-back (Stage B-3 ships the actual
 *      WhmcsClient::updateInvoiced() call via the Ekdosi-Bridge plugin)
 *
 * Tenant scoping: pulls $tenant->id explicitly everywhere. PendingWhmcsInvoice
 * has no global scope per CLAUDE.md tracking.
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
     * Build the invoice locally (transactional), submit to AADE
     * (outside tx), update the pending row, return the FileResult.
     *
     * Throws on:
     *   - Cross-tenant inputs (mapper does the check)
     *   - AADE submit failure (the invoice is already persisted - the
     *     operator can retry via the View page's "Submit to myDATA"
     *     action)
     *   - PendingWhmcsInvoice audit-frozen (observer enforces)
     */
    public function file(
        Company $tenant,
        PendingWhmcsInvoice $pending,
        Customer $customer,
        InvoiceType $invoiceType,
        ?int $filedByUserId = null,
    ): FileResult {
        // Refuse to re-file an already-filed row (the observer would
        // throw if we tried to mutate it anyway; surface the friendly
        // error before doing any work).
        if ($pending->status === PendingWhmcsInvoice::STATUS_FILED) {
            throw new \LogicException(
                'PendingWhmcsInvoice #'.$pending->id.' is already filed (MARK: '
                .($pending->mydata_mark ?? '?').'). Cannot re-file.'
            );
        }

        $mapped = $this->mapper->map($tenant, $pending, $customer, $invoiceType);

        // Step 1: persist Invoice + Lines transactionally.
        $invoice = DB::transaction(function () use ($tenant, $invoiceType, $mapped) {
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

            // Recompute the totals from the persisted lines (the
            // mapper computed them but the InvoiceLine::saving hook
            // may have adjusted, so trust the DB).
            return ($this->recompute)($invoice->fresh('lines'));
        });

        // Step 2: submit to AADE OUTSIDE the transaction. If this
        // throws, the local invoice is already persisted - operator
        // retries via the View page. The pending row stays in
        // pending_review (not flipped to filed) so the inbox still
        // shows it as actionable.
        try {
            $submitter = $this->submitterFactory->for($tenant);
            $mark = $submitter->submit($invoice);
        } catch (Throwable $e) {
            Log::error('WHMCS inbox: invoice persisted locally but AADE submit failed', [
                'pending_id'        => $pending->id,
                'invoice_id'        => $invoice->id,
                'whmcs_invoice_id'  => $pending->whmcs_invoice_id,
                'error'             => $e->getMessage(),
            ]);
            throw $e;
        }

        // Step 3: link the pending row to the now-filed invoice.
        $pending->update([
            'status'           => PendingWhmcsInvoice::STATUS_FILED,
            'filed_at'         => now(),
            'filed_by_user_id' => $filedByUserId,
            'mydata_mark'      => $mark->mark,
            'notes'            => 'Filed at AADE as invoice #'.$invoice->invcode
                .' (MARK '.$mark->mark.').',
        ]);

        // Step 4: log the WHMCS write-back deferred to Stage B-3.
        // When the Ekdosi-Bridge plugin ships, this becomes a real
        // HTTP call: WhmcsClient::updateInvoiced($whmcsId, $mark).
        Log::info('WHMCS write-back deferred (Stage B-3 plugin not yet shipped)', [
            'pending_id'       => $pending->id,
            'whmcs_invoice_id' => $pending->whmcs_invoice_id,
            'mydata_mark'      => $mark->mark,
            'ekdosi_invoice'   => $invoice->invcode,
            'note'             => 'tblinvoices.invoiced should be set to '.$mark->mark
                .' for WHMCS invoice '.$pending->whmcs_invoice_id
                .'; do this manually on the WHMCS side during testing.',
        ]);

        return new FileResult(
            invoice: $invoice->fresh('lines'),
            mark: $mark->mark,
            pending: $pending->fresh(),
        );
    }

    /**
     * Build the preview WITHOUT persisting. Used by the modal so the
     * operator sees exactly what would be filed before they commit.
     * Calls the mapper and wraps the result in a FilePreview.
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
}
