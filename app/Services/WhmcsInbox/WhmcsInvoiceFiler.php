<?php

namespace App\Services\WhmcsInbox;

use App\Enums\MyDataMode;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\PendingWhmcsInvoice;
use App\Models\VatCategory;
use App\Services\EInvoiceSubmitterFactory;
use App\Services\RecomputeInvoiceTotals;
use App\Services\Whmcs\WhmcsWritebackService;
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
        private RecomputeInvoiceTotals $recompute,
        private EInvoiceSubmitterFactory $submitterFactory,
        private WhmcsWritebackService $writeback,
        private WhmcsReceiptRecorder $receiptRecorder,
    ) {}

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
        ?string $auditNote = null,
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
        // WH-1/WH-4: non-EUR or negative (promo/credit) lines → HOLD.
        WhmcsFilingGuard::assertPayloadFilable($mapped, $pending);
        // WH-2/WH-5: recomputed gross must reconcile with the WHMCS total, and
        // the WHMCS tax rate must match the rate we apply (catches the
        // tax-inclusive case the gross-check alone misses). Whole-invoice.
        WhmcsFilingGuard::assertTotalsReconcile($mapped, $pending);
        WhmcsFilingGuard::assertVatRateReconciles($mapped, $pending);

        // Phase 1: transactional persist with lockForUpdate on the
        // pending row + atomic invoice_id linking.
        $invoice = DB::transaction(function () use ($tenant, $pending, $mapped) {
            // Re-load the pending row under a write lock. If the row
            // changed since the caller fetched it (status flipped to
            // filed, invoice_id was set by a concurrent op), we'll
            // see the new state and refuse rather than racing.
            $locked = PendingWhmcsInvoice::query()
                ->whereKey($pending->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCanBeFiled($locked);

            // Gapless-at-send: create the invoice provisional (no ΑΑ); the real number
            // is allocated by the submit below (this path files immediately).
            $invoice = Invoice::create($mapped['header']);

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
                'pending_id' => $pending->id,
                'invoice_id' => $invoice->id,
                'invcode' => $invoice->invcode,
                'whmcs_invoice_id' => $pending->whmcs_invoice_id,
                'error' => $e->getMessage(),
                'next_step' => 'Operator should open invoice #'.$invoice->id
                    .' and use the "Submit to myDATA" action to retry the AADE filing '
                    .'(retry on the SAME Invoice avoids ΑΑ-counter waste).',
            ]);
            throw $e;
        }

        // Explicit null+empty check rather than ! empty() — empty()
        // treats the string '0' as falsy, which could in theory
        // misclassify a real MARK as off-mode if the submitter ever
        // returned that shape. AADE MARKs today are 15-digit positive
        // integers but defensive coding has prevented exactly this
        // class of falsy-string regression elsewhere.
        $hasMark = $mark->mark !== null && $mark->mark !== '';

        // Phase 3: link the pending row to the AADE result. This is
        // the legal-audit transition (status=filed + filed_at +
        // mydata_mark), committed atomically with the AADE result and
        // BEFORE any WHMCS write-back. Once this commits, the
        // PendingWhmcsInvoiceObserver freezes the row against further
        // mutations — EXCEPT the whmcs_writeback_* bookkeeping columns,
        // which the write-back phase updates.
        //
        // whmcs_writeback_state starts at 'pending' when there's a
        // MARK to push (Phase 4 flips it to succeeded/failed/skipped);
        // null for off-mode tenants (nothing to push).
        $pendingFresh = $pending->fresh();
        $pendingFresh->update([
            'status' => PendingWhmcsInvoice::STATUS_FILED,
            'filed_at' => now(),
            'filed_by_user_id' => $filedByUserId,
            'mydata_mark' => $hasMark ? $mark->mark : null,
            'whmcs_writeback_state' => $hasMark ? PendingWhmcsInvoice::WRITEBACK_PENDING : null,
            'notes' => ($hasMark
                ? 'Filed at AADE as invoice #'.$invoice->invcode.' (MARK '.$mark->mark.').'
                : 'Recorded locally (off-mode — not filed at AADE) as invoice #'.$invoice->invcode.'.')
                // Optional audit suffix (e.g. the άμεση-τιμολόγηση auto-issue
                // reason). Appended here because the row is frozen against
                // mutation once status flips to 'filed' below — the filer
                // is the single writer of this notes column.
                .($auditNote !== null && $auditNote !== '' ? ' '.$auditNote : ''),
        ]);

        // Phase 4: write the MARK back to WHMCS via the ekdosi_bridge
        // plugin, which upserts it into its OWN mod_ekdosi_invoice_marks
        // table keyed by WHMCS invoice id. It deliberately does NOT touch
        // tblinvoices.invoiced — that is the legacy ekdosi app's SMALLINT
        // flag, and stuffing a 15-digit MARK there (the old behaviour)
        // broke the legacy app. The MARK lives in the bridge's VARCHAR
        // column; tblinvoices.invoiced stays read-only legacy state.
        //
        // Runs AFTER the Phase 3 status=filed commit, updating ONLY
        // the whmcs_writeback_* columns (allowed past the audit
        // freeze). Failure here is non-fatal on EVERY path: the AADE
        // filing is already recorded, the local Invoice is committed.
        // A write-back failure leaves whmcs_writeback_state at
        // 'failed' (with the error captured) so a future retry-sweep
        // command can re-run it. Skipped (state='skipped') when the
        // tenant has no bridge plugin configured.
        if ($hasMark) {
            $this->writeback->pushMark($tenant, $pendingFresh, $invoice, $mark->mark);
        }

        // Money-trail: if WHMCS already collected the money (gateway/vPOS), record
        // the matching receipt on the now-issued invoice. Best-effort and AFTER the
        // filing is committed — a failure here must never undo a filed invoice.
        try {
            $this->receiptRecorder->recordIfPaid($pendingFresh->fresh(), $invoice);
        } catch (Throwable $e) {
            Log::warning('WHMCS inbox: invoice filed but the WHMCS receipt could not be recorded', [
                'pending_id' => $pending->id,
                'invoice_id' => $invoice->id,
                'whmcs_invoice_id' => $pending->whmcs_invoice_id,
                'error' => $e->getMessage(),
            ]);
        }

        return new FileResult(
            invoice: $invoice->fresh('lines'),
            mark: $mark->mark,
            pending: $pendingFresh->fresh(),
        );
    }

    /**
     * Draft-first flow: create an editable DRAFT invoice from a WHMCS pending
     * row — allocate the ΑΑ, persist the invoice + lines, link the pending row
     * (status='drafted', invoice_id) — but DO NOT submit to AADE. The operator
     * reviews/fixes the line text (e.g. strip a domain a customer asked to
     * hide) and issues it through the normal invoice lifecycle (Οριστικοποίηση
     * → Υποβολή στο myDATA). Mirrors the per-party create in
     * WhmcsInvoiceSplitter; safe inside the request (no AADE HTTP, no outer-tx
     * restriction).
     *
     * When the draft is later issued via the lifecycle (Οριστικοποίηση →
     * Υποβολή), MyDataSubmitter's VALID persist calls
     * WhmcsWritebackService::syncFiledFromLifecycle — which flips this pending
     * row drafted→filed and pushes the MARK back to WHMCS. (Multi-party SPLIT
     * drafts remain a separate design: one WHMCS invoice → many MARKs, but the
     * bridge keys its mark store by WHMCS invoice id — one MARK per invoice.)
     */
    public function createDraft(
        Company $tenant,
        PendingWhmcsInvoice $pending,
        Customer $customer,
        InvoiceType $invoiceType,
        ?int $createdByUserId = null,
    ): Invoice {
        $mapped = $this->mapper->map($tenant, $pending, $customer, $invoiceType);
        // WH-1/WH-4: a non-EUR or negative-line draft would be wrong from the
        // start (foreign amounts as EUR / a line AADE later rejects) and the
        // operator can't fix it in the editable form — HOLD it, don't draft it.
        //
        // NOTE the deliberate asymmetry with file(): the totals/rate reconcile
        // guards (WH-2/WH-5) are NOT applied here. createDraft is the manual,
        // operator-reviewed path — the preview modal already warns on a gross
        // gap, and a rate mismatch IS fixable in the draft (edit the per-line
        // VAT before issuing). Hard-blocking it would remove the legitimate
        // create-then-fix workflow that draft-first exists for. The unattended
        // paths (file() / whmcs:auto-issue) keep the full reconcile guards.
        WhmcsFilingGuard::assertPayloadFilable($mapped, $pending);

        return DB::transaction(function () use ($tenant, $pending, $mapped, $createdByUserId) {
            $locked = PendingWhmcsInvoice::query()
                ->whereKey($pending->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCanBeFiled($locked);

            // Gapless-at-send: the inbox draft carries a provisional identity (no ΑΑ);
            // the real number is allocated when the operator issues it.
            $header = $mapped['header'];
            $header['whmcs_pending_id'] = $locked->id;
            $header['local_status'] = 'draft';

            $invoice = Invoice::create($header);
            foreach ($mapped['lines'] as $lineData) {
                InvoiceLine::create(array_merge($lineData, [
                    'company_id' => $tenant->id,
                    'invoice_id' => $invoice->id,
                ]));
            }

            $locked->update([
                'invoice_id' => $invoice->id,
                'status' => PendingWhmcsInvoice::STATUS_DRAFTED,
                'filed_by_user_id' => $createdByUserId,
                'notes' => 'Δημιουργήθηκε προσχέδιο '.$invoice->invcode
                    .' — έλεγξε/διόρθωσε και έκδωσέ το από τα Παραστατικά.',
            ]);

            return ($this->recompute)($invoice->fresh('lines'));
        });
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
        // WH-3: during the dual-run the legacy ekdosi app may have already
        // filed this WHMCS invoice (tblinvoices.invoiced != 0, mirrored here as
        // legacy_invoiced). Issuing it again in ekdosi would double-declare the
        // income at AADE. Refuse on EVERY path (manual file/draft/split AND the
        // unattended auto-issue — which also excludes these in candidates()).
        if ($locked->invoicedInLegacy()) {
            throw new LogicException(
                'Το WHMCS #'.$locked->whmcs_invoice_id.' έχει ήδη τιμολογηθεί από το παλιό (legacy) '
                .'ekdosi (tblinvoices.invoiced='.$locked->legacy_invoiced.'). Έκδοση και εδώ θα '
                .'διπλοδηλώσει το έσοδο στην ΑΑΔΕ. Αν το legacy flag είναι λάθος, καθάρισέ το πρώτα.'
            );
        }
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
                .'consuming another ΑΑ counter slot). The invoice_id FK is restrictOnDelete '
                .'(see migration 2026_05_28_000010), so force-deleting the orphan invoice '
                .'will fail loudly with an FK violation until this pending row is rejected '
                .'or re-staged — this is the protection against the double-MARK chain where '
                .'a force-delete would silently null invoice_id and let the operator allocate '
                .'a brand-new ΑΑ for the same WHMCS invoice.'
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

        // G4: a 0% line is now fileable IF the tenant has exactly one 0%-rate
        // VatCategory carrying an exemption reason (§8.3) — the submitter emits
        // vatCategory=7 + that reason. Refuse only when it's unconfigured or
        // ambiguous (mirrors MyDataSubmitter::resolveVatExemptionCategory),
        // BEFORE consuming an ΑΑ on a doomed submit.
        $exemptions = VatCategory::query()
            ->where('company_id', $tenant->id)
            ->where('rate', 0)
            ->whereNotNull('vat_exemption_category')
            ->distinct()
            ->pluck('vat_exemption_category');
        if ($exemptions->count() === 1) {
            return;   // resolvable — let the submitter file it
        }

        $sample = implode('", "', array_slice($zero, 0, 3));
        $more = count($zero) > 3 ? ' (+'.(count($zero) - 3).' more)' : '';
        $reason = $exemptions->count() > 1
            ? 'multiple 0%-rate VAT categories define different exemption reasons (ambiguous — keep one).'
            : 'no 0%-rate VAT category has a vat_exemption_category set (Setup → VAT Categories).';
        throw new LogicException(
            'WHMCS invoice #'.$pending->whmcs_invoice_id.' has '.count($zero).' untaxed line(s) '
            .'("'.$sample.'"'.$more.') but '.$reason.' AADE requires an exemption reason (§8.3) '
            .'for 0%/exempt lines; set it before filing, reject this row and issue manually, or hold it.'
        );
    }
}
