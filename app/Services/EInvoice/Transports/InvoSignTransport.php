<?php

namespace App\Services\EInvoice\Transports;

use App\Contracts\EInvoiceProviderTransport;
use App\Models\Invoice;
use App\Support\EInvoice\ProviderCredentials;
use App\Support\EInvoice\ProviderResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

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

        $body = $this->post("{$base}/iNVOSign_Api.php", [
            'xml_arxeio' => $xmlArxeio,
            'token' => $token,
        ]);

        return $this->parse($body);
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
            'series' => (string) ($invoice->invoiceType?->code ?? ''),
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
            Http::timeout(self::CONNECT_TIMEOUT)->get($base);
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

        return [$base, $token];
    }

    /** @param  array<string, string>  $form */
    private function post(string $url, array $form): string
    {
        try {
            $response = Http::asForm()
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

    private function parse(string $xml, bool $cancel = false): ProviderResult
    {
        $sx = @simplexml_load_string($xml);
        if ($sx === false) {
            return ProviderResult::failed(['InvoSign: μη αναγνώσιμη απάντηση'], $xml);
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

            return ProviderResult::failed($errors, $xml);
        }

        if ($cancel) {
            return ProviderResult::ok(
                cancellationMark: ((string) ($resp->cancellationMark ?? '')) ?: null,
                raw: $xml,
            );
        }

        return ProviderResult::ok(
            mark: ((string) ($resp->invoiceMark ?? '')) ?: null,
            uid: ((string) ($resp->invoiceUid ?? '')) ?: null,
            authenticationCode: ((string) ($resp->authenticationCode ?? '')) ?: null,
            qrUrl: ((string) ($resp->qrUrl ?? '')) ?: null,
            raw: $xml,
        );
    }
}
