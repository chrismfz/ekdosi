<?php

namespace App\Services\EInvoice;

use App\Contracts\EInvoiceProviderTransport;
use App\Contracts\EInvoiceSubmitter;
use App\Exceptions\EInvoice\ProviderTransportException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\MyDataMark;
use App\Services\MyDataRejected;
use App\Services\Whmcs\WhmcsWritebackService;
use App\Support\EInvoice\ProviderCredentials;
use App\Support\EInvoice\ProviderResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Files an invoice through a certified ΥΠΑΗΕΣ provider (P2). It reuses the SAME
 * canonical AADE payload as MyDataSubmitter (AadeInvoiceDocument) and hands the
 * XML to a provider transport, which submits to myDATA on the tenant's behalf and
 * returns the MARK + authentication code + QR. The mydata_marks row stays the
 * legal source of truth (action PROVIDER_INSERT / PROVIDER_CANCEL, + the provider
 * audit columns); the invoice mirror columns sync exactly as in the direct path.
 *
 * ONE submitter for ALL GR providers — the per-provider difference lives entirely
 * in the injected EInvoiceProviderTransport (resolved from the registry by the
 * factory). See docs/paroxos/implementation-plan.md §2.3 / §14.
 *
 * Idempotency / double-filing (§14):
 *   - pre-submit state guards refuse a VALID / CANCELLED / unknown-state invoice;
 *   - on an AMBIGUOUS transport failure (timeout/connection — the provider MIGHT
 *     have filed), we status-check by invoice coordinates and ADOPT an existing
 *     MARK instead of blindly re-filing;
 *   - a duplicate INSERT MARK for the same invoice is de-duped on persist.
 *
 * WHMCS write-back fires on filing + cancel (parity with MyDataSubmitter, keyed on
 * whmcs_pending_id — no-op for non-WHMCS). The ONE remaining parity follow-up is
 * auto-email on VALID (a best-effort UX nicety MyDataSubmitter does); a provider
 * tenant gets it in a later pass.
 */
class GrProviderSubmitter implements EInvoiceSubmitter
{
    public function __construct(
        private readonly Company $tenant,
        private readonly EInvoiceProviderTransport $transport,
    ) {}

    public function submit(Invoice $invoice): MyDataMark
    {
        $this->assertNotAlreadyFiled($invoice);

        $document = new AadeInvoiceDocument($this->tenant);
        $payload = $document->build($invoice);
        $xml = $document->toXml($payload);
        $credentials = ProviderCredentials::fromCompany($this->tenant);

        try {
            $result = $this->transport->send($invoice, $xml, $credentials);
        } catch (Throwable $e) {
            // Ambiguous: the provider may or may not have filed. NEVER blind-retry
            // (§14.4) — status-check by invoice coordinates first and adopt a MARK
            // if one exists, otherwise record the failure and surface it.
            $adopted = $this->recoverViaStatusCheck($invoice, $xml, $credentials, $e);
            if ($adopted !== null) {
                $this->syncWhmcsFiled($invoice, $adopted);

                return $adopted;
            }

            $this->logFailure($invoice, 'transport', $e);
            // Forensic trail even on a hard transport failure: the doc may have filed
            // despite the lost response, so record WHAT WE TRIED TO SEND (the exact
            // augmented payload when the transport reports it, else the AADE core) +
            // the error — so an operator can find/cancel it manually.
            $attempted = ($e instanceof ProviderTransportException && $e->attemptedPayload !== null)
                ? $e->attemptedPayload
                : $xml;
            $this->recordTransportFailure($invoice, 'PROVIDER_FAILED', $attempted, $e->getMessage());

            throw new RuntimeException(
                'E-invoice provider unreachable / submission failed: '.$e->getMessage(),
                0,
                $e
            );
        }

        if (! $result->success) {
            $this->recordRejection($invoice, $xml, $result);
            throw new MyDataRejected(
                'E-invoice provider rejected the submission: '.$result->errorMessage(),
                $xml,
                $result->raw ?? ''
            );
        }

        $mark = $this->persistSuccess($invoice, $xml, $result);
        $this->syncWhmcsFiled($invoice, $mark);

        return $mark;
    }

    /**
     * Parity with MyDataSubmitter (H1): close the WHMCS-inbox loop on a provider
     * filing too. Write-back is keyed on invoices.whmcs_pending_id — INDEPENDENT
     * of the e-invoice provider — so a provider tenant that staged an invoice from
     * the WHMCS inbox must still flip the pending row drafted→filed and push the
     * MARK back to the bridge. No-op for non-WHMCS invoices; never throws (a
     * write-back hiccup must not mask a successful filing).
     */
    private function syncWhmcsFiled(Invoice $invoice, MyDataMark $mark): void
    {
        app(WhmcsWritebackService::class)->syncFiledFromLifecycle($invoice, $mark->mark);
    }

    public function cancel(Invoice $invoice, string $reason = ''): MyDataMark
    {
        if ($invoice->mydata_state === 'CANCELLED') {
            throw new RuntimeException(
                "Invoice {$invoice->invcode} is already cancelled at myDATA (state=CANCELLED). Refusing to double-cancel."
            );
        }

        // Read the MARK from the audit history, not the mirror column (same
        // reasoning as MyDataSubmitter::cancel). Accept both PROVIDER_INSERT and a
        // legacy direct INSERT — a tenant migrated gr-mydata→gr-provider mid-life
        // may cancel a directly-filed document through the provider (deliberate).
        $inserts = MyDataMark::query()
            ->where('invoice_id', $invoice->id)
            ->whereIn('mydata_action', ['PROVIDER_INSERT', 'INSERT'])
            ->whereNotNull('mark')
            ->orderByDesc('id')
            ->get();

        if ($inserts->isEmpty()) {
            throw new RuntimeException(
                "Cannot cancel invoice {$invoice->invcode} — no INSERT MARK on file (never filed via the provider)."
            );
        }

        // M1: the §14.4 recovery can, in a partial-failure window, leave more than
        // one INSERT MARK for an invoice. Cancel the latest but surface the others
        // — they may be orphan filings needing manual reconciliation.
        if ($inserts->count() > 1) {
            Log::warning('Provider cancel: multiple INSERT MARKs found — cancelling latest only', [
                'invoice_id' => $invoice->id,
                'invcode' => $invoice->invcode,
                'marks' => $inserts->pluck('mark')->all(),
                'note' => 'Earlier MARKs may be orphan filings. Manual reconciliation required.',
            ]);
        }

        $mark = $inserts->first()->mark;

        $credentials = ProviderCredentials::fromCompany($this->tenant);

        try {
            $result = $this->transport->cancel((string) $mark, $credentials, $reason);
        } catch (Throwable $e) {
            $this->logFailure($invoice, 'cancel', $e);
            $this->recordTransportFailure(
                $invoice, 'PROVIDER_CANCEL_FAILED',
                'Cancel MARK '.$mark.($reason !== '' ? " — reason: {$reason}" : ''),
                $e->getMessage(), (string) $mark,
            );
            throw new RuntimeException('E-invoice provider cancellation failed: '.$e->getMessage(), 0, $e);
        }

        if (! $result->success) {
            // Record a forensic PROVIDER_CANCEL_REJECTED row so the rejection (what
            // we asked + what the provider returned) is visible in the history —
            // otherwise a failed cancel leaves NO trace to debug.
            $this->recordCancelRejection($invoice, (string) $mark, $reason, $result);
            throw new RuntimeException('E-invoice provider rejected the cancellation: '.$result->errorMessage());
        }

        $audit = DB::transaction(function () use ($invoice, $mark, $reason, $result) {
            $audit = MyDataMark::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'mark' => $result->cancellationMark ?? (string) $mark,
                'mydata_action' => 'PROVIDER_CANCEL',
                'provider_key' => $this->transport->key(),
                'request' => $reason !== '' ? "Cancel reason: {$reason}" : null,
                'response' => $result->raw,
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ]);

            $invoice->forceFill([
                'mydata_state' => 'CANCELLED',
                'local_status' => 'cancelled',
            ])->save();

            return $audit;
        });

        // Reflect the cancellation on the WHMCS side (parity with MyDataSubmitter).
        // OUTSIDE the transaction — network call, must not hold a DB lock. No-op for
        // non-WHMCS invoices; never throws.
        app(WhmcsWritebackService::class)->syncCancelledFromLifecycle($invoice);

        return $audit;
    }

    public function testConnection(): bool
    {
        return $this->transport->ping(ProviderCredentials::fromCompany($this->tenant));
    }

    // ---- internals ------------------------------------------------------

    /**
     * Same defense-in-depth as MyDataSubmitter::submit — never re-file an invoice
     * that's already VALID or CANCELLED at myDATA, and refuse any unrecognised state.
     */
    private function assertNotAlreadyFiled(Invoice $invoice): void
    {
        if ($invoice->mydata_state === 'VALID') {
            throw new RuntimeException(
                "Invoice {$invoice->invcode} was already filed at myDATA under MARK ".
                ($invoice->mydata_mark ?: '?').'. Cancel and re-issue if a correction is needed.'
            );
        }
        if ($invoice->mydata_state === 'CANCELLED') {
            throw new RuntimeException(
                "Invoice {$invoice->invcode} was previously filed and CANCELLED at myDATA. ".
                'Issue a correction invoice (new code) instead of resubmitting.'
            );
        }
        if ($invoice->mydata_state !== null && $invoice->mydata_state !== '') {
            throw new RuntimeException(
                "Invoice {$invoice->invcode} has an unrecognised mydata_state='{$invoice->mydata_state}'. ".
                'Refusing to submit — investigate before retrying.'
            );
        }
        // MYD-3 (AUDIT): mirror MyDataSubmitter — a locally-voided document
        // must never reach the provider/AADE.
        if ($invoice->local_status === 'cancelled') {
            throw new RuntimeException(
                "Invoice {$invoice->invcode} is locally cancelled — refusing to file it. ".
                'Restore it first (Επαναφορά σε πρόχειρο → Οριστικοποίηση) if the cancellation was a mistake.'
            );
        }
    }

    /**
     * §14.4 idempotency: a transport-level failure (timeout/connection) is
     * ambiguous. Ask the provider for the document's state by coordinates; if it
     * already has a MARK, the filing DID happen — adopt it (persist success) so a
     * retry can't double-file. Any error from the status-check itself is swallowed
     * (logged) — we fall back to surfacing the original failure to the operator.
     */
    private function recoverViaStatusCheck(
        Invoice $invoice,
        string $xml,
        ProviderCredentials $credentials,
        Throwable $original,
    ): ?MyDataMark {
        try {
            $status = $this->transport->status($invoice, $credentials);
        } catch (Throwable $e) {
            Log::info('Provider status-check after a failed send found nothing to adopt.', [
                'invoice_id' => $invoice->id,
                'send_error' => $original->getMessage(),
                'status_error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $status->success || ($status->mark ?? '') === '') {
            return null;
        }

        Log::warning('Provider send failed but status-check found an existing MARK — adopting (avoided double-file).', [
            'invoice_id' => $invoice->id,
            'mark' => $status->mark,
        ]);

        return $this->persistSuccess($invoice, $xml, $status, viaRecovery: true);
    }

    /**
     * Persist a successful provider filing: a PROVIDER_INSERT mydata_marks row
     * (with provider audit columns) + the invoice mirror-column sync. Idempotent
     * on (invoice, mark): a duplicate adopts the existing row.
     */
    private function persistSuccess(Invoice $invoice, string $xml, ProviderResult $result, bool $viaRecovery = false): MyDataMark
    {
        $mark = (string) $result->mark;

        // Defense in depth (any transport): never flip an invoice to VALID without a
        // real MARK — a "success" with no MARK is not a filing. Refuse loudly so the
        // operator sees it and the document stays re-fileable.
        if ($mark === '') {
            throw new RuntimeException(
                "E-invoice provider reported success but returned no MARK for invoice {$invoice->invcode}. ".
                'Refusing to mark VALID — investigate the provider response.'
            );
        }

        $existing = MyDataMark::query()
            ->where('invoice_id', $invoice->id)
            ->where('mark', $mark)
            ->where('mydata_action', 'PROVIDER_INSERT')
            ->first();
        if ($existing) {
            return $existing;
        }

        // M2: a status-check (recovery) response is lighter than a send response —
        // it may carry only the MARK, not the QR/auth code. Never NULL-out a mirror
        // column we can't refresh; flag the adopted row so an operator knows the
        // audit is partial and a reconciliation pass should backfill it.
        $deliveryState = $result->deliveryState ?? ($viaRecovery ? 'ADOPTED' : null);

        return DB::transaction(function () use ($invoice, $xml, $result, $mark, $deliveryState) {
            $audit = MyDataMark::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'mark' => $mark,
                'mydata_action' => 'PROVIDER_INSERT',
                'provider_key' => $this->transport->key(),
                'authentication_code' => $result->authenticationCode,
                'delivery_state' => $deliveryState,
                'invoice_url' => $result->qrUrl,
                // Store the ACTUAL payload the transport sent (e.g. InvoSign's
                // augmented xml_arxeio), falling back to the AADE core if the
                // transport didn't report one — so the history shows what the
                // provider really received, not just the pre-augment XML.
                'request' => $result->requestPayload ?? $xml,
                'response' => $result->raw,
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ]);

            // MARK_AI0 trigger replacement — same mirror-column sync as the direct
            // path (forceFill: the submitter is the only legitimate writer). A
            // filed invoice is a live document → promote a draft to active. Coalesce
            // mydata_url so a lighter recovery response can't wipe an existing QR.
            $invoice->forceFill([
                'mydata_sent' => true,
                'mydata_state' => 'VALID',
                'local_status' => $invoice->local_status === 'draft' ? 'active' : $invoice->local_status,
                'mydata_mark' => $mark,
                'mydata_url' => $result->qrUrl ?? $invoice->mydata_url,
                'mydata_type' => $invoice->invoiceType?->mydata_type,
            ])->save();

            return $audit;
        });
    }

    private function recordRejection(Invoice $invoice, string $xml, ProviderResult $result): void
    {
        try {
            DB::transaction(fn () => MyDataMark::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'mark' => null,
                'mydata_action' => 'PROVIDER_REJECTED',
                'provider_key' => $this->transport->key(),
                // The ACTUAL sent payload (augmented), not just the AADE core.
                'request' => $result->requestPayload ?? $xml,
                'response' => $result->raw,
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ]));
        } catch (Throwable $e) {
            Log::warning('Provider: failed to persist PROVIDER_REJECTED audit row', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Forensic row for a TRANSPORT failure (timeout / non-2xx) on submit or cancel —
     * so the attempt (what we tried to send + the error) is in the history even when
     * no provider response came back. Never throws (best-effort audit).
     */
    private function recordTransportFailure(Invoice $invoice, string $action, string $request, string $error, ?string $mark = null): void
    {
        try {
            DB::transaction(fn () => MyDataMark::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'mark' => $mark,
                'mydata_action' => $action,
                'provider_key' => $this->transport->key(),
                'request' => $request,
                'response' => 'Transport error: '.$error,
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ]));
        } catch (Throwable $e) {
            Log::warning('Provider: failed to persist '.$action.' audit row', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Forensic row for a REJECTED cancellation — so the failed cancel is debuggable. */
    private function recordCancelRejection(Invoice $invoice, string $mark, string $reason, ProviderResult $result): void
    {
        try {
            DB::transaction(fn () => MyDataMark::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'mark' => $mark,
                'mydata_action' => 'PROVIDER_CANCEL_REJECTED',
                'provider_key' => $this->transport->key(),
                'request' => 'Cancel MARK '.$mark.($reason !== '' ? " — reason: {$reason}" : ''),
                'response' => $result->raw,
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ]));
        } catch (Throwable $e) {
            Log::warning('Provider: failed to persist PROVIDER_CANCEL_REJECTED audit row', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function logFailure(Invoice $invoice, string $kind, Throwable $e): void
    {
        Log::warning('E-invoice provider submission failure', [
            'company_id' => $this->tenant->getKey(),
            'invoice_id' => $invoice->id,
            'invcode' => $invoice->invcode,
            'provider_key' => $this->transport->key(),
            'kind' => $kind,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
        ]);
    }
}
