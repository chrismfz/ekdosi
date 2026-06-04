<?php

namespace App\Services;

use App\Contracts\EInvoiceSubmitter;
use App\Enums\MyDataMode;
use App\Jobs\SendInvoiceEmail;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\MyDataMark;
use App\Services\EInvoice\AadeInvoiceDocument;
use App\Services\Whmcs\WhmcsWritebackService;
use Firebed\AadeMyData\Exceptions\MyDataAuthenticationException;
use Firebed\AadeMyData\Exceptions\MyDataConnectionException;
use Firebed\AadeMyData\Exceptions\MyDataException;
use Firebed\AadeMyData\Exceptions\MyDataTimeoutException;
use Firebed\AadeMyData\Http\CancelInvoice;
use Firebed\AadeMyData\Http\MyDataRequest;
use Firebed\AadeMyData\Http\RequestTransmittedDocs;
use Firebed\AadeMyData\Http\SendInvoices;
use Firebed\AadeMyData\Models\Invoice as AadeInvoice;
use Firebed\AadeMyData\Models\Response;
use Firebed\AadeMyData\Models\ResponseDoc;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Real myDATA submitter. Wraps the firebed/aade-mydata library and
 * adapts our domain (Invoice + InvoiceLine + Company) to its
 * (AadeInvoice + InvoiceHeader + InvoiceDetails + InvoiceSummary +
 * TaxesTotals).
 *
 * The AADE payload construction itself lives in
 * App\Services\EInvoice\AadeInvoiceDocument (factored out so a future
 * provider submitter can reuse the exact same XML — see
 * docs/paroxos/implementation-plan.md). This class owns the TRANSPORT:
 * credentials, the SendInvoices/CancelInvoice round-trip, persistence
 * of the mydata_marks audit row, and the invoice mirror-column sync.
 *
 * Per-tenant credentials: firebed uses STATIC state for credentials
 * (MyDataRequest::init() sets self::$user_id etc.), which is fine for
 * the typical FPM/Apache single-request-per-process model. We
 * defensively call init() before EVERY operation so even if a
 * previous request left stale credentials in static state, ours win.
 * If we ever move to Octane / Roadrunner / parallel queue workers,
 * the static state becomes a contention point — track in CLAUDE.md.
 *
 * Replaces the legacy MARK_AI0 Firebird trigger by updating the
 * Invoice's mydata_* mirror columns inside the same DB transaction
 * as the new mydata_marks row. Either both succeed or neither does.
 *
 * Dry-run mode: builds the full XML payload, persists a
 * mydata_action='DRY_RUN' audit row with the XML, and never POSTs to
 * AADE. Lets operators preview exactly what would be submitted from
 * the Filament invoice view page (and via the artisan smoke command).
 * The Invoice's mirror columns are NOT updated on dry-run — there's
 * no real MARK to mirror.
 */
class MyDataSubmitter implements EInvoiceSubmitter
{
    public function __construct(
        private readonly Company $tenant,
        /**
         * Optional mock handler for tests. firebed's MyDataRequest
         * accepts a Guzzle MockHandler that intercepts outbound HTTP
         * — same pattern as the AadeRegistryLookup. Production code
         * never passes this.
         */
        private readonly ?MockHandler $mockHandler = null,
    ) {}

    /** Memoised per-tenant AADE payload builder (factored out for reuse). */
    private ?AadeInvoiceDocument $document = null;

    private function document(): AadeInvoiceDocument
    {
        return $this->document ??= new AadeInvoiceDocument($this->tenant);
    }

    public function submit(Invoice $invoice): MyDataMark
    {
        // Guard against re-submitting an already-filed invoice. Critical
        // for the ETL cutover path: imported legacy invoices arrive with
        // mydata_state='VALID' and a real MARK. Without this guard, an
        // operator clicking Submit on an imported invoice would file it
        // again at AADE (UID idempotency below mitigates but doesn't
        // fully prevent — defense in depth).
        if ($invoice->mydata_state === 'VALID') {
            throw new RuntimeException(
                "Invoice {$invoice->invcode} was already filed at myDATA under MARK ".
                ($invoice->mydata_mark ?: '?').'. '.
                'Use the Cancel action and re-issue if a correction is needed.'
            );
        }
        // Symmetric guard: refuse to resubmit a previously-cancelled
        // invoice. AADE's behaviour for UID-dedup against a cancelled
        // filing is undocumented — operator should issue a fresh
        // correction invoice instead.
        if ($invoice->mydata_state === 'CANCELLED') {
            throw new RuntimeException(
                "Invoice {$invoice->invcode} was previously filed and CANCELLED at myDATA. ".
                'Issue a correction invoice (new code) instead of resubmitting.'
            );
        }
        // Belt-and-suspenders catch-all. The two specific guards above
        // cover all values legacy schema ever wrote (legacy/ekdosi-schema.sql:991
        // sets MYDATA_STATE='VALID', :1000 sets 'CANCELLED'). If a future
        // code path or a corrupted import introduces ANY other non-null
        // state, refuse to file blindly rather than treat unknown as
        // "never filed". Loud-fail beats silent double-file. Per the
        // fourth-review finding — defense in depth even though the
        // specific guards are exhaustive for today's data.
        if ($invoice->mydata_state !== null && $invoice->mydata_state !== '') {
            throw new RuntimeException(
                "Invoice {$invoice->invcode} has an unrecognised mydata_state='{$invoice->mydata_state}'. ".
                'Refusing to submit — investigate the state value before retrying. '.
                'Expected null (never filed), VALID (filed), or CANCELLED.'
            );
        }

        $payload = $this->document()->build($invoice);
        $xml = $this->document()->toXml($payload);

        $this->initFirebed();

        // Hold the action instance so we can call getResponseXML() on it
        // afterwards. ResponseDoc itself does NOT support __toString;
        // the raw XML lives on the action via the HasResponseDom trait
        // (see vendor/firebed/aade-mydata/src/Http/Traits/HasResponseDom.php).
        $action = new SendInvoices;

        try {
            $response = $action->handle($payload);
        } catch (MyDataAuthenticationException $e) {
            $this->logFailure($invoice, 'auth', $e);
            throw new RuntimeException('myDATA rejected credentials. Check Company → myDATA submission tab.', 0, $e);
        } catch (MyDataTimeoutException|MyDataConnectionException $e) {
            $this->logFailure($invoice, 'transport', $e);
            throw new RuntimeException('myDATA endpoint unreachable. Try again later.', 0, $e);
        } catch (MyDataException $e) {
            $this->logFailure($invoice, 'protocol', $e);
            throw new RuntimeException('myDATA submission failed: '.$e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            $this->logFailure($invoice, 'other', $e);
            throw new RuntimeException('myDATA submission failed unexpectedly.', 0, $e);
        }

        $responseXml = $action->getResponseXML() ?? '';

        $mark = $this->persistResponse($invoice, $payload, $xml, $response, $responseXml);

        // WHMCS write-back on the draft-first LIFECYCLE path. A draft created
        // from the WHMCS inbox (WhmcsInvoiceFiler::createDraft) carries
        // invoices.whmcs_pending_id; when it's later issued through the normal
        // lifecycle and reaches VALID here, flip the linked pending row
        // drafted→filed and push the MARK back to the bridge's mark store
        // (mod_ekdosi_invoice_marks, NOT tblinvoices.invoiced). No-ops
        // for non-WHMCS invoices, off-mode (no MARK), or split rows. Never
        // throws — a write-back hiccup must not mask the successful filing.
        // (The direct WhmcsInvoiceFiler::file() path does its own write-back;
        // its invoices don't carry whmcs_pending_id, so this won't double-fire.)
        app(WhmcsWritebackService::class)->syncFiledFromLifecycle($invoice, $mark->mark);

        // PR #27: dispatch the customer-mail job after a successful
        // VALID filing IF the tenant has opted in via
        // auto_email_on_mydata_accept. The job re-fetches the invoice,
        // renders a fresh PDF, and routes to customer.email + the
        // tenant's audit BCC. Skipped silently when:
        //   - tenant flag is false
        //   - customer has no email (job handler logs + returns)
        //   - this submitter was reached via DRY_RUN (not this path)
        $this->dispatchAutoEmailIfEnabled($invoice);

        return $mark;
    }

    /**
     * Best-effort dispatch of the customer-mail job. Silent on every
     * tenant-opted-out path; logs (doesn't throw) if the dispatcher
     * itself fails — we don't want a queue-connection hiccup to mask
     * a successful AADE filing from the operator. The mail can always
     * be re-sent via the ViewInvoice "Resend email" action.
     *
     * NOTE on DB::afterCommit: Laravel's transaction manager fires the
     * callback IMMEDIATELY when there's no active transaction (verified
     * at vendor/laravel/framework/.../DatabaseTransactionsManager.php
     * :205). So in the IssueInvoice (CreateInvoice) path — which wraps
     * the whole flow in Filament's outer transaction — the dispatch
     * defers until that outer commit. But in the ViewInvoice "Submit
     * to myDATA" path, there's no outer transaction, so the dispatch
     * runs synchronously here. Either way, persistResponse() has
     * already committed its own inner transaction by this point, so
     * the invoice + mark row are durable. This is correct behaviour,
     * not a defense — it's why we use afterCommit defensively even
     * though it's a no-op in the common case.
     */
    private function dispatchAutoEmailIfEnabled(Invoice $invoice): void
    {
        if (! ($invoice->company?->auto_email_on_mydata_accept ?? false)) {
            return;
        }

        // G6: respect the per-customer opt-out (default true).
        if (! $invoice->customerAcceptsAutoEmail()) {
            return;
        }

        $invoiceId = $invoice->getKey();
        DB::afterCommit(function () use ($invoiceId): void {
            try {
                $fresh = Invoice::query()->whereKey($invoiceId)->first();
                if ($fresh) {
                    SendInvoiceEmail::dispatch($fresh);
                }
            } catch (Throwable $e) {
                Log::warning('SendInvoiceEmail auto-dispatch failed (filing succeeded)', [
                    'invoice_id' => $invoiceId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Build the would-be submission XML and persist a DRY_RUN audit row
     * WITHOUT contacting AADE. Safe on any mode (off / sandbox /
     * production). Lets operators inspect what the real submitter
     * would send.
     *
     * Deliberately a SEPARATE PUBLIC METHOD from submit() — the prior
     * design (`submit($invoice, $dryRun = false)`) was a footgun: any
     * caller that forgot to pass the named argument would file for
     * real. Code review correctly flagged this. Now grep-able: every
     * caller of submit() definitely submits; every caller of
     * previewXml() definitely doesn't.
     */
    public function previewXml(Invoice $invoice): MyDataMark
    {
        $payload = $this->document()->build($invoice);
        $xml = $this->document()->toXml($payload);

        return $this->recordDryRun($invoice, $xml);
    }

    public function cancel(Invoice $invoice, string $reason = ''): MyDataMark
    {
        // Guard: refuse to double-cancel. Once mydata_state='CANCELLED'
        // we don't want a second CANCEL call to AADE (which would
        // either be rejected or produce a duplicate CANCEL audit row).
        if ($invoice->mydata_state === 'CANCELLED') {
            throw new RuntimeException(
                "Invoice {$invoice->invcode} is already cancelled at myDATA (state=CANCELLED). ".
                'Refusing to double-cancel.'
            );
        }

        // Read the actual MARK from the audit history — NOT from the
        // mirror column. Reasoning: if a previous submit succeeded at
        // AADE but the DB write failed (and was later retried, producing
        // a second MARK), the mirror reflects only the LATEST mark
        // while the older one is still active at AADE. We cancel the
        // most recent INSERT mark (because that's what the AADE-side
        // dedup logic should have collapsed onto via the UID), but
        // surface a warning if multiple INSERT marks exist for the
        // same invoice — that's a hint of an earlier orphan.
        $inserts = MyDataMark::query()
            ->where('invoice_id', $invoice->id)
            ->where('mydata_action', 'INSERT')
            ->whereNotNull('mark')
            ->orderByDesc('id')
            ->get();

        if ($inserts->isEmpty()) {
            throw new RuntimeException(
                "Cannot cancel invoice {$invoice->invcode} — no INSERT MARK on file. ".
                'The invoice was never submitted to myDATA.'
            );
        }

        if ($inserts->count() > 1) {
            Log::warning('myDATA cancel: multiple INSERT MARKs found — cancelling latest only', [
                'invoice_id' => $invoice->id,
                'invcode' => $invoice->invcode,
                'marks' => $inserts->pluck('mark')->all(),
                'note' => 'Earlier MARKs may be orphan filings at AADE. Manual reconciliation required.',
            ]);
        }

        // Stays as string — AADE MARKs are 15+ digit numerics that
        // overflow 32-bit int. CancelInvoice::handle() signature is
        // `string $mark` per firebed's docblock — no need to coerce.
        $markToCancel = (string) $inserts->first()->mark;

        $this->initFirebed();

        // Hold the action so we can extract its raw response XML for
        // the audit row (HasResponseDom trait on the action — NOT
        // (string) on the ResponseDoc, which would crash).
        $action = new CancelInvoice;

        try {
            $action->handle($markToCancel);
        } catch (Throwable $e) {
            $this->logFailure($invoice, 'cancel', $e);
            throw new RuntimeException('myDATA cancellation failed: '.$e->getMessage(), 0, $e);
        }

        $responseXml = $action->getResponseXML() ?? '';

        $mark = DB::transaction(function () use ($invoice, $responseXml, $reason, $markToCancel) {
            $mark = MyDataMark::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'mark' => $markToCancel,
                'mydata_action' => 'CANCEL',
                'request' => $reason !== '' ? "Cancel reason: {$reason}" : null,
                'response' => $responseXml,
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ]);

            // MARK_AI0 trigger replacement: flip the invoice's
            // mirror columns to reflect cancellation. The MARK is
            // preserved for audit but state becomes CANCELLED. Sync the
            // local status here too (single choke-point).
            $invoice->forceFill([
                'mydata_state' => 'CANCELLED',
                'local_status' => 'cancelled',
            ])->save();

            return $mark;
        });

        // Reflect the cancellation on the WHMCS side (best-effort, never throws)
        // so the bridge badge shows «ΑΚΥΡΩΜΕΝΟ». OUTSIDE the transaction — it's a
        // network call and must not hold a DB lock. No-ops for non-WHMCS invoices.
        app(WhmcsWritebackService::class)->syncCancelledFromLifecycle($invoice);

        return $mark;
    }

    /**
     * Verify the tenant's credentials against AADE.
     *
     * @param  MyDataMode|null  $environment  Test a SPECIFIC environment's
     *                                        credentials (sandbox / production) regardless of the tenant's
     *                                        saved mode — lets the Company form offer a "Test" button per
     *                                        credential set. Null = the tenant's current mode.
     */
    public function testConnection(?MyDataMode $environment = null): bool
    {
        $this->initFirebed($environment);

        try {
            // RequestTransmittedDocs with a tight date range — minimal
            // query, just verifies creds + reachability. AADE returns
            // an empty doc list if nothing was filed today; we don't
            // care about content, only that the call doesn't fault.
            //
            // Format gotchas (per firebed's docblock at
            // vendor/firebed/aade-mydata/src/Http/MyDataGetRequest.php:43):
            //   - $mark is a NON-NULLABLE string — pass '', not null,
            //     else TypeError misclassified as transport failure
            //   - dateFrom / dateTo are 'dd/MM/yyyy', NOT Y-m-d. AADE
            //     rejects the wrong format with a 400 that surfaces as
            //     "myDATA unreachable" to the operator (misleading —
            //     they'd think creds are wrong).
            $action = new RequestTransmittedDocs;
            $action->handle(
                '',
                now()->subDay()->format('d/m/Y'),
                now()->format('d/m/Y'),
            );

            return true;
        } catch (MyDataAuthenticationException) {
            return false;
        } catch (Throwable $e) {
            // Non-auth errors (timeout, transport) reach the operator
            // as "couldn't reach AADE" — don't claim credentials are
            // bad when we don't know that.
            Log::warning('myDATA test connection failed (non-auth)', [
                'company_id' => $this->tenant->getKey(),
                'exception' => get_class($e),
            ]);
            throw $e;
        }
    }

    // ---- internals ------------------------------------------------------

    private function initFirebed(?MyDataMode $environment = null): void
    {
        // The environment drives BOTH the credential slot AND the AADE
        // endpoint. An explicit override (from the per-environment "Test"
        // buttons) wins over the tenant's saved mode.
        $mode = $environment ?? $this->tenant->mydata_mode_enum;

        [$aadeId, $subKey] = $this->tenant->mydataCredentials($mode);  // key decrypted by Eloquent cast

        if (empty($aadeId) || empty($subKey)) {
            throw new RuntimeException(
                'myDATA credentials are not configured for this tenant ('.
                $mode->value.' environment).'
            );
        }

        $env = $mode === MyDataMode::Production ? 'prod' : 'dev';

        MyDataRequest::init($aadeId, $subKey, $env);

        // Always set the handler — passing null explicitly RESETS
        // firebed's static $handler. Without this, once any test in
        // the process sets a MockHandler, every subsequent submitter
        // in the same process (queue worker, Octane, test suite)
        // inherits the leftover handler and silently intercepts real
        // submissions. Always-reset is the safe default.
        MyDataRequest::setHandler($this->mockHandler);
    }

    private function recordDryRun(Invoice $invoice, string $xml): MyDataMark
    {
        return DB::transaction(fn () => MyDataMark::create([
            'company_id' => $invoice->company_id,
            'invoice_id' => $invoice->id,
            'mark' => null,
            'mydata_action' => 'DRY_RUN',
            'request' => $xml,
            'response' => null,
            'mark_date' => now()->toDateString(),
            'mark_time' => now()->toTimeString(),
        ]));
    }

    /**
     * Persist a forensic REJECTED audit row (request + response XML, no MARK)
     * when AADE refuses a submission — so the operator sees WHAT was sent and
     * WHY it was refused from the invoice's myDATA «Ιστορικό», instead of the
     * round-trip vanishing on the throw. Mirrors recordDryRun's null-mark shape.
     * Never let an audit-write failure mask the real rejection.
     */
    private function recordRejection(Invoice $invoice, string $requestXml, string $responseXml): void
    {
        try {
            DB::transaction(fn () => MyDataMark::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'mark' => null,
                'mydata_action' => 'REJECTED',
                'request' => $requestXml,
                'response' => $responseXml,
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ]));
        } catch (Throwable $e) {
            Log::warning('myDATA: failed to persist REJECTED audit row', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function persistResponse(
        Invoice $invoice,
        AadeInvoice $payload,
        string $xml,
        ResponseDoc $response,
        string $responseXml,
    ): MyDataMark {
        // ResponseDoc extends TypeArray which is iterable and exposes
        // first(). It does NOT have a getResponses() method (the
        // earlier code's invocation of that would have crashed every
        // single successful submit). Iterate properly.
        /** @var Response|null $firstResponse */
        $firstResponse = $response->first();

        if ($firstResponse === null || $firstResponse->getStatusCode() !== 'Success') {
            $errors = $firstResponse ? $this->describeResponseErrors($firstResponse) : 'no response';
            // Persist a forensic record + carry the XML on the throw, so a
            // rejection isn't a dead-end — the round-trip is otherwise lost
            // (no INSERT row is written on failure). Visible in the invoice's
            // myDATA history; MyDataRejected is the in-band copy for callers.
            $this->recordRejection($invoice, $xml, $responseXml);
            throw new MyDataRejected("myDATA rejected the submission: {$errors}", $xml, $responseXml);
        }

        $mark = (string) $firstResponse->getInvoiceMark();
        $qrUrl = $firstResponse->getQrUrl();

        // Idempotent INSERT: if a mydata_marks row already exists for
        // this invoice+mark, return it instead of writing a duplicate.
        // Defends against the rare AADE-success / local-DB-fail retry
        // scenario where UID dedup at AADE returns the original MARK
        // on the operator's second attempt — we'd otherwise pile up
        // duplicate audit rows for the same logical filing.
        $existing = MyDataMark::query()
            ->where('invoice_id', $invoice->id)
            ->where('mark', $mark)
            ->where('mydata_action', 'INSERT')
            ->first();
        if ($existing) {
            Log::info('myDATA submit: idempotent — MARK already recorded locally', [
                'invoice_id' => $invoice->id,
                'mark' => $mark,
            ]);

            return $existing;
        }

        return DB::transaction(function () use ($invoice, $payload, $xml, $responseXml, $mark, $qrUrl) {
            $audit = MyDataMark::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'mark' => $mark,
                'mydata_action' => 'INSERT',
                'invoice_url' => $qrUrl,
                'request' => $xml,
                // Raw response XML from firebed's HasResponseDom trait —
                // NOT (string) $response (ResponseDoc has no __toString,
                // would crash). The action's getResponseXML() is the
                // legally-required verbatim record.
                'response' => $responseXml,
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ]);

            // Legacy MARK_AI0 trigger replacement. forceFill bypasses
            // $fillable, which deliberately omits the mydata_* mirror
            // columns to prevent operator forms from spoofing them
            // (PR #23 review finding). The submitter is the ONLY
            // legitimate writer.
            $invoice->forceFill([
                'mydata_sent' => true,
                'mydata_state' => 'VALID',
                // A filed invoice is a live document. Promote a draft to
                // 'active' HERE (the single submit choke-point) so every
                // path — ViewInvoice submit, create-and-submit, credit-note
                // submit, bulk submit — stays in sync. (Leaves 'cancelled'
                // alone: a cancelled-then-filed doc surfaces in the
                // reconciliation worklist, not silently flipped active.)
                'local_status' => $invoice->local_status === 'draft' ? 'active' : $invoice->local_status,
                'mydata_mark' => $mark,
                'mydata_url' => $qrUrl,
                // firebed may return getInvoiceType() as either a raw
                // string ("1.1") OR a BackedEnum case (AadeInvoiceType::TYPE_1_1)
                // depending on how the header was set (we always pass
                // a string, but the setter may coerce). varchar(5)
                // would overflow on an enum case name like 'TYPE_1_1'
                // AND it's the wrong value semantically. Normalise.
                'mydata_type' => $this->normalizeInvoiceTypeForStorage(
                    $payload->getInvoiceHeader()->getInvoiceType()
                ),
            ])->save();

            return $audit;
        });
    }

    /**
     * Coerce whatever firebed's getInvoiceType() returns into the
     * canonical "1.1" / "11.2" / etc. AADE invoice-type string for
     * storage in the varchar(5) mydata_type column.
     */
    private function normalizeInvoiceTypeForStorage(mixed $type): ?string
    {
        if ($type === null) {
            return null;
        }
        if ($type instanceof \BackedEnum) {
            return (string) $type->value;
        }

        return (string) $type;
    }

    private function describeResponseErrors($response): string
    {
        if (! method_exists($response, 'getErrors')) {
            return $response->getStatusCode() ?? 'unknown';
        }
        // getErrors() returns a firebed Errors object (TypeArray —
        // iterable, NOT a plain array), or null. Iterate it; array_map()
        // over the object TypeErrors and masks the real AADE rejection.
        $errs = $response->getErrors();
        if ($errs === null) {
            return $response->getStatusCode() ?? 'unknown';
        }
        $messages = [];
        foreach ($errs as $e) {
            $code = method_exists($e, 'getCode') ? $e->getCode() : null;
            $msg = method_exists($e, 'getMessage') ? $e->getMessage() : (string) $e;
            $messages[] = $code ? "[{$code}] {$msg}" : $msg;
        }

        return implode('; ', $messages) ?: ($response->getStatusCode() ?? 'unknown');
    }

    private function logFailure(Invoice $invoice, string $kind, Throwable $e): void
    {
        Log::warning('myDATA submission failure', [
            'company_id' => $this->tenant->getKey(),
            'invoice_id' => $invoice->id,
            'invcode' => $invoice->invcode,
            'kind' => $kind,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
        ]);
    }
}
