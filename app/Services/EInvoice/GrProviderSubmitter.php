<?php

namespace App\Services\EInvoice;

use App\Contracts\EInvoiceProviderTransport;
use App\Contracts\EInvoiceSubmitter;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\MyDataMark;
use App\Services\MyDataRejected;
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
 * NOT yet wired here (parity follow-ups, deferred so P2 stays focused & isolated
 * from the live myDATA path): WHMCS write-back and auto-email on VALID — both are
 * best-effort downstream effects MyDataSubmitter does; a provider tenant gets them
 * in a later pass.
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
            $result = $this->transport->send($xml, $credentials);
        } catch (Throwable $e) {
            // Ambiguous: the provider may or may not have filed. NEVER blind-retry
            // (§14.4) — status-check by invoice coordinates first and adopt a MARK
            // if one exists, otherwise record the failure and surface it.
            $adopted = $this->recoverViaStatusCheck($invoice, $xml, $credentials, $e);
            if ($adopted !== null) {
                return $adopted;
            }

            $this->logFailure($invoice, 'transport', $e);
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

        return $this->persistSuccess($invoice, $xml, $result);
    }

    public function cancel(Invoice $invoice, string $reason = ''): MyDataMark
    {
        if ($invoice->mydata_state === 'CANCELLED') {
            throw new RuntimeException(
                "Invoice {$invoice->invcode} is already cancelled at myDATA (state=CANCELLED). Refusing to double-cancel."
            );
        }

        $mark = MyDataMark::query()
            ->where('invoice_id', $invoice->id)
            ->whereIn('mydata_action', ['PROVIDER_INSERT', 'INSERT'])
            ->whereNotNull('mark')
            ->orderByDesc('id')
            ->value('mark');

        if (! $mark) {
            throw new RuntimeException(
                "Cannot cancel invoice {$invoice->invcode} — no INSERT MARK on file (never filed via the provider)."
            );
        }

        $credentials = ProviderCredentials::fromCompany($this->tenant);

        try {
            $result = $this->transport->cancel((string) $mark, $credentials, $reason);
        } catch (Throwable $e) {
            $this->logFailure($invoice, 'cancel', $e);
            throw new RuntimeException('E-invoice provider cancellation failed: '.$e->getMessage(), 0, $e);
        }

        if (! $result->success) {
            throw new RuntimeException('E-invoice provider rejected the cancellation: '.$result->errorMessage());
        }

        return DB::transaction(function () use ($invoice, $mark, $reason, $result) {
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

        return $this->persistSuccess($invoice, $xml, $status);
    }

    /**
     * Persist a successful provider filing: a PROVIDER_INSERT mydata_marks row
     * (with provider audit columns) + the invoice mirror-column sync. Idempotent
     * on (invoice, mark): a duplicate adopts the existing row.
     */
    private function persistSuccess(Invoice $invoice, string $xml, ProviderResult $result): MyDataMark
    {
        $mark = (string) $result->mark;

        $existing = MyDataMark::query()
            ->where('invoice_id', $invoice->id)
            ->where('mark', $mark)
            ->where('mydata_action', 'PROVIDER_INSERT')
            ->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($invoice, $xml, $result, $mark) {
            $audit = MyDataMark::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'mark' => $mark,
                'mydata_action' => 'PROVIDER_INSERT',
                'provider_key' => $this->transport->key(),
                'authentication_code' => $result->authenticationCode,
                'delivery_state' => $result->deliveryState,
                'invoice_url' => $result->qrUrl,
                'request' => $xml,
                'response' => $result->raw,
                'mark_date' => now()->toDateString(),
                'mark_time' => now()->toTimeString(),
            ]);

            // MARK_AI0 trigger replacement — same mirror-column sync as the direct
            // path (forceFill: the submitter is the only legitimate writer). A
            // filed invoice is a live document → promote a draft to active.
            $invoice->forceFill([
                'mydata_sent' => true,
                'mydata_state' => 'VALID',
                'local_status' => $invoice->local_status === 'draft' ? 'active' : $invoice->local_status,
                'mydata_mark' => $mark,
                'mydata_url' => $result->qrUrl,
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
                'request' => $xml,
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
