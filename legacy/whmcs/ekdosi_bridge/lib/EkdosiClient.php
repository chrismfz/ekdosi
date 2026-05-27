<?php

namespace WHMCS\Module\Addon\EkdosiBridge;

use WHMCS\Database\Capsule;

/**
 * Thin HTTP client that talks to the ekdosi backend over HMAC-signed
 * requests. Mirrors the request shape ekdosi's own
 * App\Http\Controllers\Webhooks\* expect:
 *
 *   POST /webhooks/whmcs/{slug}/invoice-paid
 *       X-Webhook-Signature: sha256=<hex hmac of body>
 *       body: {"whmcs_invoice_id": N}
 *
 *   GET /webhooks/whmcs/{slug}/invoice-status/{invoice_id}
 *       X-Webhook-Signature: sha256=<hex hmac of request path>
 *
 * The shared secret comes from this addon's tbladdonmodules config
 * (the operator pastes the same string into both ekdosi's
 * companies.whmcs_webhook_secret column and this addon's
 * configuration on the WHMCS side).
 *
 * fromConfig() returns null if the addon hasn't been configured yet
 * (any of base URL / slug / secret missing). Callers render a
 * "configure first" prompt.
 */
class EkdosiClient
{
    private function __construct(
        private readonly string $baseUrl,
        private readonly string $slug,
        private readonly string $secret,
    ) {
    }

    public static function fromConfig(): ?self
    {
        // tbladdonmodules carries one row per (module, setting) pair.
        // Read the three keys we need for the bridge to function.
        $rows = Capsule::table('tbladdonmodules')
            ->where('module', 'ekdosi_bridge')
            ->whereIn('setting', ['ekdosi_base_url', 'ekdosi_slug', 'webhook_secret'])
            ->pluck('value', 'setting');

        $base = trim((string) ($rows['ekdosi_base_url'] ?? ''));
        $slug = trim((string) ($rows['ekdosi_slug'] ?? ''));
        $secret = (string) ($rows['webhook_secret'] ?? '');

        if ($base === '' || $slug === '' || $secret === '') {
            return null;
        }
        // Trim trailing slash so concatenation is clean.
        $base = rtrim($base, '/');
        return new self($base, $slug, $secret);
    }

    /**
     * POST {base}/webhooks/whmcs/{slug}/invoice-paid with
     *   { whmcs_invoice_id: N }
     * HMAC-signed with the shared secret.
     *
     * Returns: [
     *   'ok' => bool,                   // overall success (HTTP 2xx)
     *   'http_status' => int,
     *   'body' => string,               // raw response body (for diagnostics)
     *   'data' => array|null,           // parsed JSON if available
     *   'summary' => string,            // operator-facing summary
     * ]
     */
    public function pushInvoicePaid(int $whmcsInvoiceId): array
    {
        $url = $this->baseUrl.'/webhooks/whmcs/'.rawurlencode($this->slug).'/invoice-paid';
        $body = json_encode(['whmcs_invoice_id' => $whmcsInvoiceId], JSON_THROW_ON_ERROR);
        $sig = 'sha256='.hash_hmac('sha256', $body, $this->secret);

        $result = $this->httpRequest('POST', $url, $body, [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Webhook-Signature: '.$sig,
        ]);

        $result['summary'] = $this->summarisePush($result);
        return $result;
    }

    /**
     * GET {base}/webhooks/whmcs/{slug}/invoice-status/{whmcs_invoice_id}
     * HMAC-signed over the path component (no body in GET).
     *
     * Returns: same shape as pushInvoicePaid().
     */
    public function getInvoiceStatus(int $whmcsInvoiceId): array
    {
        $path = '/webhooks/whmcs/'.rawurlencode($this->slug).'/invoice-status/'.$whmcsInvoiceId;
        $url = $this->baseUrl.$path;
        $sig = 'sha256='.hash_hmac('sha256', $path, $this->secret);

        $result = $this->httpRequest('GET', $url, null, [
            'Accept: application/json',
            'X-Webhook-Signature: '.$sig,
        ]);
        $result['summary'] = $this->summariseStatus($result);
        return $result;
    }

    /**
     * Minimal cURL wrapper. WHMCS hosts vary in what HTTP libraries
     * are available; cURL is the lowest-common-denominator and
     * available on every supported PHP install.
     */
    private function httpRequest(string $method, string $url, ?string $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $responseBody = (string) curl_exec($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        $data = null;
        if ($responseBody !== '') {
            $decoded = json_decode($responseBody, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        return [
            'ok'          => $curlErr === '' && $httpStatus >= 200 && $httpStatus < 300,
            'http_status' => $httpStatus,
            'body'        => $responseBody !== '' ? $responseBody : $curlErr,
            'data'        => $data,
        ];
    }

    private function summarisePush(array $r): string
    {
        if ($r['ok']) {
            $pid = $r['data']['pending_whmcs_invoice_id'] ?? '?';
            $created = ! empty($r['data']['created']);
            return $created
                ? "Staged in ekdosi as pending #{$pid}."
                : "Already staged (pending #{$pid}). Re-push refreshed the payload.";
        }
        $err = $r['data']['error'] ?? ('HTTP '.$r['http_status']);
        return 'Push failed: '.$err;
    }

    private function summariseStatus(array $r): string
    {
        if (! $r['ok']) {
            $err = $r['data']['error'] ?? ('HTTP '.$r['http_status']);
            return 'Status query failed: '.$err;
        }
        if (empty($r['data']['found'])) {
            return 'No row in ekdosi yet.';
        }
        return 'Status: '.($r['data']['status'] ?? '?');
    }
}
