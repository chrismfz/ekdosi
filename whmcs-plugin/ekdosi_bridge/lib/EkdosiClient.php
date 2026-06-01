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
     *
     * HMAC-signed over the CANONICAL string "{slug}:{invoice_id}"
     * (NOT the URL path). The ekdosi side verifies the same canonical
     * string — keeping the signature transport-independent so a
     * reverse proxy that rewrites the path, or a slug needing URL
     * encoding, can't break verification. See the README's
     * "Security model" section and
     * WhmcsInvoiceStatusController::verifySignature on the ekdosi side.
     *
     * Returns: same shape as pushInvoicePaid().
     */
    public function getInvoiceStatus(int $whmcsInvoiceId): array
    {
        $url = $this->baseUrl.'/webhooks/whmcs/'.rawurlencode($this->slug)
            .'/invoice-status/'.$whmcsInvoiceId;
        $canonical = $this->slug.':'.$whmcsInvoiceId;
        $sig = 'sha256='.hash_hmac('sha256', $canonical, $this->secret);

        $result = $this->httpRequest('GET', $url, null, [
            'Accept: application/json',
            'X-Webhook-Signature: '.$sig,
        ]);
        $result['summary'] = $this->summariseStatus($result);
        return $result;
    }

    /**
     * GET the 3-way mapping for a WHMCS client (WHMCS # → ekdosi παραστατικό →
     * ΜΑΡΚ + state, drafts included). Canonical "{slug}:map:{whmcs_userid}";
     * the "map:" infix keeps it distinct from an invoice-status signature.
     * Result shape matches the others; mapping rows are under data['rows'].
     */
    public function getClientInvoiceMap(int $whmcsUserId): array
    {
        $url = $this->baseUrl.'/webhooks/whmcs/'.rawurlencode($this->slug)
            .'/invoice-map/'.$whmcsUserId;
        $canonical = $this->slug.':map:'.$whmcsUserId;
        $sig = 'sha256='.hash_hmac('sha256', $canonical, $this->secret);

        return $this->httpRequest('GET', $url, null, [
            'Accept: application/json',
            'X-Webhook-Signature: '.$sig,
        ]);
    }

    /**
     * POST {base}/webhooks/whmcs/{slug}/invoices-by-afm with
     *   { afms: ["123456789", ...] }
     * HMAC-signed over the RAW body (same scheme as pushInvoicePaid).
     *
     * The AFM-keyed per-client card: ekdosi matches each ΑΦΜ against its
     * customers and returns that customer's live invoices (ΤΠΥ + ΜΑΡΚ +
     * state). ΑΦΜ is the only WHMCS↔ekdosi link that survives the legacy
     * import — there is no stored client/invoice id mapping. We pass the
     * whole SET (client's own VAT id + any third-party contact ΑΦΜ they
     * route services to) so the card can group «Δικά του» vs «Τρίτοι».
     *
     * Returns the standard httpRequest() shape; data['afms'] is a map of
     * ΑΦΜ → {customer_id, customer_name, invoices[]} | null.
     *
     * @param  list<string>  $afms
     */
    public function getInvoicesByAfm(array $afms): array
    {
        $url = $this->baseUrl.'/webhooks/whmcs/'.rawurlencode($this->slug).'/invoices-by-afm';
        $body = json_encode(['afms' => array_values($afms)], JSON_THROW_ON_ERROR);
        $sig = 'sha256='.hash_hmac('sha256', $body, $this->secret);

        return $this->httpRequest('POST', $url, $body, [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Webhook-Signature: '.$sig,
        ]);
    }

    /**
     * POST {base}/webhooks/whmcs/{slug}/invoice-states with
     *   { whmcs_invoice_ids: [N, ...] }
     * HMAC-signed over the RAW body. Returns the deterministic ekdosi state
     * for each WHMCS invoice id in ONE call (ΤΠΥ + ΜΑΡΚ + κατάσταση) — powers
     * the addon's consolidated invoice list. data['states'] maps id →
     * {status, ekdosi_invcode, mydata_mark, ...} | null (null = never pushed).
     *
     * @param  list<int>  $ids
     */
    public function getInvoiceStates(array $ids): array
    {
        $url = $this->baseUrl.'/webhooks/whmcs/'.rawurlencode($this->slug).'/invoice-states';
        $body = json_encode(['whmcs_invoice_ids' => array_values($ids)], JSON_THROW_ON_ERROR);
        $sig = 'sha256='.hash_hmac('sha256', $body, $this->secret);

        return $this->httpRequest('POST', $url, $body, [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Webhook-Signature: '.$sig,
        ]);
    }

    /**
     * POST {base}/webhooks/whmcs/{slug}/invoices-by-legacy-id with
     *   { legacy_ids: [N, ...] }
     * HMAC-signed over the RAW body. Deterministic HISTORICAL lookup: the legacy
     * INVOICE_ID we stored in tblinvoices.invoiced equals ekdosi's
     * invoices.legacy_id, so this returns ΤΠΥ + ΜΑΡΚ for already-filed (imported)
     * invoices with no re-import / no ΑΦΜ guessing. data['invoices'] maps
     * legacy_id → {ekdosi_invcode, mydata_mark, mydata_state, ...} | null.
     *
     * @param  list<int>  $legacyIds
     */
    public function invoicesByLegacyId(array $legacyIds): array
    {
        $url = $this->baseUrl.'/webhooks/whmcs/'.rawurlencode($this->slug).'/invoices-by-legacy-id';
        $body = json_encode(['legacy_ids' => array_values($legacyIds)], JSON_THROW_ON_ERROR);
        $sig = 'sha256='.hash_hmac('sha256', $body, $this->secret);

        return $this->httpRequest('POST', $url, $body, [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Webhook-Signature: '.$sig,
        ]);
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
            // audit_preserved (success flag): ekdosi already filed this
            // invoice at AADE, so the re-push did NOT refresh the frozen
            // payload — distinct from a normal "re-push refreshed" so the
            // operator knows the legal record is untouched.
            if (! empty($r['data']['audit_preserved'])) {
                return "Already filed at AADE (pending #{$pid}); re-push left the audit-frozen "
                    . 'record unchanged — nothing to do, reconcile if WHMCS-side data changed.';
            }
            return $created
                ? "Staged in ekdosi as pending #{$pid}."
                : "Already staged (pending #{$pid}). Re-push refreshed the payload.";
        }
        return 'Push failed: '.$this->classifyError($r);
    }

    private function summariseStatus(array $r): string
    {
        if (! $r['ok']) {
            return 'Status query failed: '.$this->classifyError($r);
        }
        if (empty($r['data']['found'])) {
            return 'No row in ekdosi yet.';
        }
        return 'Status: '.($r['data']['status'] ?? '?');
    }

    /**
     * Turn a failed ekdosi response into an operator-actionable message,
     * distinguishing the cases that need DIFFERENT reactions instead of
     * collapsing everything into "HTTP NNN". Status codes match the ekdosi
     * webhook controller (WhmcsInvoicePaidController):
     *
     *  - 409  → `whmcs_invoice_not_found`: WHMCS knows the tenant but not this
     *    invoice id (deleted post-push, or a typo). Input contradicts upstream;
     *    re-pushing won't help — check the invoice exists.
     *  - 502  → `whmcs_upstream_failure`: ekdosi reached WHMCS's API but it
     *    failed. Transient — retry shortly.
     *  - 503/504 → gateway/timeout, also transient.
     *  - 401/403 → HMAC/secret mismatch; fix the shared webhook secret.
     *  - else  → the ekdosi `error` field, or a bare HTTP code.
     */
    private function classifyError(array $r): string
    {
        $status = (int) ($r['http_status'] ?? 0);
        $data = is_array($r['data'] ?? null) ? $r['data'] : array();
        $err = isset($data['error']) ? (string) $data['error'] : '';

        if ($status === 409) {
            $detail = $err !== '' ? $err : 'invoice not found upstream';
            return "input contradicts WHMCS state ({$detail}) — verify the invoice exists; "
                . 're-pushing will not help.';
        }

        if ($status === 401 || $status === 403) {
            $detail = $err !== '' ? $err : 'authentication rejected';
            return "auth/signature rejected ({$detail}) — check the shared webhook secret.";
        }

        if ($status === 502 || $status === 503 || $status === 504) {
            $detail = $err !== '' ? $err : 'upstream failure';
            return "ekdosi/WHMCS temporarily unavailable (HTTP {$status}: {$detail}) — transient, retry shortly.";
        }

        if ($err !== '') {
            return $err.($status ? " (HTTP {$status})" : '');
        }

        return $status ? "HTTP {$status}" : 'no response (connection failed)';
    }
}
