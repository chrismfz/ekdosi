<?php

namespace App\Services\EInvoice\Transports;

use App\Contracts\EInvoiceProviderTransport;
use App\Exceptions\EInvoice\ProviderTransportException;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Support\EInvoice\ProviderCredentials;
use App\Support\EInvoice\ProviderEndpointGuard;
use App\Support\EInvoice\ProviderResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * InvoSign (iNVOSign / GVSolutions) ΥΠΑΗΕΣ transport. Form-encoded POSTs of the
 * AADE InvoicesDoc XML (augmented by InvoSignDocument) + a per-client `token`;
 * responses are `<ResponseDoc>` XML carrying the AADE ΜΑΡΚ + authentication code +
 * QR. Grounded in docs/paroxos/research/invosign-api-reference.md.
 *
 * Credentials (einvoice_provider_config): base_url/token for production,
 * demo_base_url/demo_token for sandbox; the channel's mode picks which set is used.
 *
 * ⚠ Endpoints + response shape are from the public API guide; sandbox-validate
 * before go-live (P5). On a transport-level failure (timeout / non-2xx) we THROW
 * so GrProviderSubmitter's §14.4 status-check recovery can adopt a MARK that was
 * filed despite a lost response — never a silent double-file.
 */
class InvoSignTransport implements EInvoiceProviderTransport
{
    private const TIMEOUT = 30;

    private const CONNECT_TIMEOUT = 10;

    public function key(): string
    {
        return 'invosign';
    }

    public function send(Invoice $invoice, string $documentXml, ProviderCredentials $credentials): ProviderResult
    {
        [$base, $token] = $this->resolve($credentials);
        $xmlArxeio = InvoSignDocument::augment($documentXml, $invoice);

        try {
            $body = $this->post("{$base}/iNVOSign_Api.php", [
                'xml_arxeio' => $xmlArxeio,
                'token' => $token,
            ]);
        } catch (Throwable $e) {
            // A transport failure (timeout / non-2xx) — attach the EXACT payload we
            // tried to send so the submitter can record it forensically (the doc may
            // have filed despite the lost response).
            throw new ProviderTransportException($e->getMessage(), $xmlArxeio, $e);
        }

        // Carry the ACTUAL sent payload so it's stored as the mark's request
        // (the augmented xml_arxeio InvoSign received, not just the AADE core).
        return $this->parse($body, requestPayload: $xmlArxeio);
    }

    public function sendDelivery(DeliveryNote $note, string $documentXml, ProviderCredentials $credentials): ProviderResult
    {
        [$base, $token] = $this->resolve($credentials);
        // Delivery notes skip the per-line api_* printout twins, but InvoSign still
        // demands the invoice-level <API_InvoiceDetails> (issuer + counterpart) —
        // its absence is rejected with [88-006]. augmentDelivery() appends exactly
        // that block AND applies the icls/ecls→n1/n2 prefix normalisation ([88-004]).
        $xmlArxeio = InvoSignDocument::augmentDelivery($documentXml, $note);

        try {
            $body = $this->post("{$base}/iNVOSign_Api.php", [
                'xml_arxeio' => $xmlArxeio,
                'token' => $token,
            ]);
        } catch (Throwable $e) {
            throw new ProviderTransportException($e->getMessage(), $xmlArxeio, $e);
        }

        return $this->parse($body, requestPayload: $xmlArxeio);
    }

    public function cancel(string $mark, ProviderCredentials $credentials, string $reason = ''): ProviderResult
    {
        [$base, $token] = $this->resolve($credentials);

        $body = $this->post("{$base}/iNVOSign_CancelDeliveryNote.php", [
            'mark' => $mark,
            'token' => $token,
        ]);

        return $this->parse($body, cancel: true);
    }

    public function status(Invoice $invoice, ProviderCredentials $credentials): ProviderResult
    {
        [$base, $token] = $this->resolve($credentials);
        $invoice->loadMissing(['invoiceType', 'company']);

        $body = $this->post("{$base}/invoice_status.php", [
            'token' => $token,
            'issuer_vatNumber' => (string) ($invoice->company?->afm ?? ''),
            'branch' => '0',
            'invoiceType' => (string) ($invoice->invoiceType?->mydata_type ?? ''),
            'issueDate' => $invoice->issued_at?->toDateString() ?? '',
            // MYD-018: the FROZEN series. Reading the live lookup made this ask
            // the provider about a (series, ΑΑ) it was never sent.
            'series' => (string) ($invoice->filedSeries() ?? ''),
            'aa' => (string) ($invoice->code ?? ''),
        ]);

        return $this->parse($body);
    }

    public function ping(ProviderCredentials $credentials): bool
    {
        // InvoSign documents no health endpoint; verify the creds are present and
        // the base URL is reachable (a real auth check needs an issuer + document,
        // which «Έλεγχος σύνδεσης» doesn't have). Sandbox-refine later.
        [$base] = $this->resolve($credentials);

        try {
            Http::withoutRedirecting()->timeout(self::CONNECT_TIMEOUT)->get($base);
        } catch (ConnectionException $e) {
            throw new RuntimeException('InvoSign endpoint unreachable: '.$e->getMessage(), 0, $e);
        }

        return true;
    }

    /**
     * @return array{0: string, 1: string} [base_url (no trailing slash), token]
     */
    private function resolve(ProviderCredentials $c): array
    {
        $base = rtrim((string) ($c->sandbox ? $c->get('demo_base_url') : $c->get('base_url')), '/');
        $token = (string) ($c->sandbox ? $c->get('demo_token') : $c->get('token'));

        if ($base === '' || $token === '') {
            throw new RuntimeException(
                'InvoSign credentials are not configured for the '.($c->sandbox ? 'sandbox' : 'production').
                ' environment (base URL + token).'
            );
        }

        // Constrain the operator-supplied endpoint to a public HTTPS host BEFORE any
        // request leaves with the token + invoice XML (SSRF / data-exfil). Enforced
        // here so CLI/API callers are covered, not only the form. PROV-017.
        ProviderEndpointGuard::assertSafeBaseUrl($base);

        return [$base, $token];
    }

    /** @param  array<string, string>  $form */
    private function post(string $url, array $form): string
    {
        try {
            $response = Http::asForm()
                ->withoutRedirecting()   // a rogue endpoint must not 302 the token+payload elsewhere
                ->timeout(self::TIMEOUT)
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->post($url, $form);
        } catch (ConnectionException $e) {
            throw new RuntimeException('InvoSign endpoint unreachable: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException('InvoSign HTTP '.$response->status().' from '.$url);
        }

        return $response->body();
    }

    private function parse(string $xml, bool $cancel = false, ?string $requestPayload = null): ProviderResult
    {
        // LIBXML_NONET: never resolve external entities/network from a third-party
        // provider's response (XXE hardening on a money path).
        $sx = @simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        if ($sx === false) {
            return ProviderResult::failed(['InvoSign: μη αναγνώσιμη απάντηση'], $xml, $requestPayload);
        }

        $resp = $sx->response ?? $sx;
        $status = (string) ($resp->statusCode ?? '');

        if ($status !== 'Success') {
            $errors = [];
            if (isset($resp->errors->error)) {
                foreach ($resp->errors->error as $e) {
                    $code = (string) ($e->code ?? '');
                    $message = (string) ($e->message ?? '');
                    $errors[] = $code !== '' ? "[{$code}] {$message}" : $message;
                }
            }
            if ($errors === []) {
                $errors[] = $status !== '' ? $status : 'unknown InvoSign error';
            }

            return ProviderResult::failed($errors, $xml, $requestPayload);
        }

        if ($cancel) {
            return ProviderResult::ok(
                cancellationMark: ((string) ($resp->cancellationMark ?? '')) ?: null,
                raw: $xml,
                requestPayload: $requestPayload,
            );
        }

        // B1: a 'Success' WITHOUT a MARK is a malformed response — treat it as a
        // failure, never a filing. Otherwise the invoice would be flipped to VALID
        // with an empty MARK (legally filed, no proof, un-re-fileable). Returning
        // failed() also lets §14.4 recovery status-check before any retry.
        $mark = ((string) ($resp->invoiceMark ?? '')) ?: null;
        if ($mark === null) {
            return ProviderResult::failed(['InvoSign: «Success» χωρίς ΜΑΡΚ — μη έγκυρη απάντηση'], $xml, $requestPayload);
        }

        return ProviderResult::ok(
            mark: $mark,
            requestPayload: $requestPayload,
            uid: ((string) ($resp->invoiceUid ?? '')) ?: null,
            authenticationCode: ((string) ($resp->authenticationCode ?? '')) ?: null,
            qrUrl: ((string) ($resp->qrUrl ?? '')) ?: null,
            raw: $xml,
            // PROV-009: operational evidence InvoSign returns on every issue.
            // remaining_invoices is a numeric quota (sandbox-confirmed «988»); an
            // absent/non-numeric one → null (never a fabricated 0). receptionEmails
            // is present-but-empty until the notification path is configured → null.
            // Clamp to the unsignedInteger range [0, 4294967295]: this write shares the
            // VALID-commit transaction, so an out-of-range value (a negative «overdraft»
            // OR a garbage huge one) must NOT fail the insert and roll back an accepted
            // filing. 0 already means exhausted; the 32-bit cap never bites a real quota.
            remainingInvoices: is_numeric((string) ($resp->remaining_invoices ?? '')) ? min(4294967295, max(0, (int) $resp->remaining_invoices)) : null,
            receptionEmails: ((string) ($resp->receptionEmails ?? '')) ?: null,
        );
    }
}
