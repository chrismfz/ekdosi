<?php

namespace App\Services\Whmcs;

use App\Exceptions\Whmcs\WhmcsApiException;
use App\Exceptions\Whmcs\WhmcsUnreachable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Stage B-3: outbound client for the WHMCS-side ekdosi_bridge plugin.
 *
 * Distinct from WhmcsClient (which talks to WHMCS's native API using
 * identifier+secret in the body). This client talks to the
 * ekdosi_bridge PHP plugin that the operator deploys at
 *
 *   {whmcs_root}/modules/addons/ekdosi_bridge/inbound.php
 *
 * using the SAME HMAC scheme we use for inbound webhooks (X-Webhook-
 * Signature: sha256=<hex of body, HMAC-SHA256 with whmcs_webhook_secret>).
 * The shared secret makes the trust boundary bidirectional: both
 * sides authenticate with the same key.
 *
 * Why a separate client class:
 *  - Different auth model (HMAC vs identifier+secret)
 *  - Different URL (plugin path vs api.php)
 *  - Different error semantics (the plugin returns its own JSON
 *    envelope, not the WHMCS API's result=success/error shape)
 *
 * Currently supports ONE operation: setInvoiced() — the write-back
 * after ekdosi files an invoice at AADE. The plugin stores the MARK
 * in its OWN mod_ekdosi_invoice_marks table (keyed by WHMCS invoice
 * id), NOT in the legacy tblinvoices.invoiced SMALLINT flag. Stage
 * B-3's scope is intentionally narrow; future bridge-specific
 * operations (mark-as-cancelled, attach-PDF) get added as separate
 * methods here.
 */
class WhmcsBridgeClient
{
    /**
     * Same connect/total budget as WhmcsClient. The bridge plugin
     * does a single DB UPDATE so 20s is very generous; the timeout
     * is there to fail visibly on a wedged upstream rather than
     * accidentally tolerating a chronically-slow tenant.
     */
    private const HTTP_TIMEOUT_SECONDS = 20;

    private const HTTP_CONNECT_TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $bridgeUrl,
        private readonly string $webhookSecret,
    ) {}

    /**
     * Push the MARK value into the bridge's mod_ekdosi_invoice_marks
     * table for the given WHMCS invoice. The plugin authenticates the
     * request via HMAC over the raw body using whmcs_webhook_secret;
     * on success it upserts the MARK (as a VARCHAR string) keyed by the
     * WHMCS invoice id and returns 200 OK. It does NOT touch the legacy
     * tblinvoices.invoiced flag — that stays a SMALLINT the legacy
     * ekdosi app reads/writes.
     *
     * @param  int  $whmcsInvoiceId  The WHMCS invoice id (tblinvoices.id)
     * @param  string  $mark  The AADE MARK value as a string (a 15-digit
     *                        int). Stored verbatim in the bridge's own
     *                        VARCHAR column, so there are no
     *                        column-width concerns.
     * @param  string|null  $invcode  The ekdosi ΤΠΥ (e.g. ΑΠΥ423) shown next to
     *                                 the MARK on the WHMCS admin badges. Optional
     *                                 — omitted from the body when null.
     *
     * Throws:
     *  - WhmcsUnreachable if the WHMCS server is unreachable / TLS handshake fails
     *  - WhmcsApiException for any non-2xx response or non-success envelope
     *
     * Does NOT throw for "invoice id not found" — the plugin returns
     * 404 in that case and we surface it as WhmcsApiException with
     * the diagnostic message intact. Callers can pattern-match the
     * message if they need to distinguish (the filer currently
     * treats it as a non-fatal log).
     */
    public function setInvoiced(int $whmcsInvoiceId, string $mark, ?string $invcode = null): void
    {
        $bodyData = [
            'whmcs_invoice_id' => $whmcsInvoiceId,
            'mark' => $mark,
        ];
        if ($invcode !== null && $invcode !== '') {
            $bodyData['invcode'] = $invcode;
        }
        $body = json_encode($bodyData, JSON_THROW_ON_ERROR);

        $signature = 'sha256='.hash_hmac('sha256', $body, $this->webhookSecret);

        try {
            /** @var Response $response */
            $response = $this->http
                ->timeout(self::HTTP_TIMEOUT_SECONDS)
                ->connectTimeout(self::HTTP_CONNECT_TIMEOUT_SECONDS)
                ->withHeaders([
                    'X-Webhook-Signature' => $signature,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->withBody($body, 'application/json')
                ->post($this->bridgeUrl);
        } catch (ConnectionException $e) {
            throw new WhmcsUnreachable(
                'ekdosi_bridge plugin unreachable: '.$e->getMessage(),
                0,
                $e,
            );
        }

        if (! $response->successful()) {
            // Plugin reachable but rejected the request: bad signature,
            // unknown invoice id, DB error. Body is JSON with an `error`
            // key (per the plugin's response shape).
            $payload = $response->json();
            $message = is_array($payload) && isset($payload['error'])
                ? (string) $payload['error']
                : ('ekdosi_bridge HTTP '.$response->status());
            throw new WhmcsApiException(
                'ekdosi_bridge set_invoiced failed: '.$message,
            );
        }
    }

    /**
     * T-1 (timologia v2): READ-ONLY third-party-invoicing resolution. Asks the
     * bridge's resolve.php which of a WHMCS invoice's lines route to a third
     * party (the legacy mod_timologia* tables, which the standard WHMCS API
     * can't reach). Returns a ThirdPartyResolution describing the routing —
     * NO billing decision is made here.
     *
     * Throws WhmcsUnreachable / WhmcsApiException with the same semantics as
     * setInvoiced(). A 404 (unknown invoice id) surfaces as WhmcsApiException
     * with the diagnostic message intact.
     */
    public function resolveThirdParty(int $whmcsInvoiceId): ThirdPartyResolution
    {
        $data = $this->postResolve(['op' => 'resolve', 'invoice_id' => $whmcsInvoiceId]);

        return ThirdPartyResolution::fromBridgeResponse($data);
    }

    /**
     * T-1: every WHMCS client (userid) that has >=1 third-party routing row,
     * with a route count. ekdosi badges the matching Customers (by
     * whmcs_client_id) so an operator can tell which customers route some
     * invoices to third parties.
     *
     * @return array<int, array{userid: int, routes: int}>
     */
    public function listResellers(): array
    {
        $data = $this->postResolve(['op' => 'resellers']);
        $rows = $data['resellers'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $userId = (int) ($row['userid'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            $out[] = ['userid' => $userId, 'routes' => (int) ($row['routes'] ?? 0)];
        }

        return $out;
    }

    /**
     * Dual-run visibility: the legacy `tblinvoices.invoiced` flag for a batch of
     * WHMCS invoice ids (the WHMCS API can't expose this custom column, so the
     * bridge reads it directly — READ-ONLY). ekdosi shows "already invoiced in
     * the legacy app" on its inbox so the operator doesn't double-issue.
     *
     * Returns a map { whmcsInvoiceId => invoiced } for the ids the bridge knew;
     * ids absent from the response are simply omitted (caller treats missing as
     * unknown). Throws WhmcsUnreachable / WhmcsApiException like resolveThirdParty.
     *
     * @param  array<int>  $ids
     * @return array<int, int>
     */
    public function getInvoicedFlags(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }

        $data = $this->postResolve(['op' => 'invoiced_flags', 'ids' => $ids]);
        $flags = $data['flags'] ?? [];
        if (! is_array($flags)) {
            return [];
        }

        $out = [];
        foreach ($flags as $id => $value) {
            $id = (int) $id;
            if ($id > 0) {
                $out[$id] = (int) $value;
            }
        }

        return $out;
    }

    /**
     * POST an HMAC-signed op to the bridge's resolve.php (sibling of
     * inbound.php). Same auth + error handling as setInvoiced(); returns the
     * decoded JSON body.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function postResolve(array $payload): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $body, $this->webhookSecret);

        try {
            /** @var Response $response */
            $response = $this->http
                ->timeout(self::HTTP_TIMEOUT_SECONDS)
                ->connectTimeout(self::HTTP_CONNECT_TIMEOUT_SECONDS)
                ->withHeaders([
                    'X-Webhook-Signature' => $signature,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->withBody($body, 'application/json')
                ->post($this->resolveUrl());
        } catch (ConnectionException $e) {
            throw new WhmcsUnreachable(
                'ekdosi_bridge plugin unreachable: '.$e->getMessage(),
                0,
                $e,
            );
        }

        if (! $response->successful()) {
            $errPayload = $response->json();
            $message = is_array($errPayload) && isset($errPayload['error'])
                ? (string) $errPayload['error']
                : ('ekdosi_bridge HTTP '.$response->status());
            throw new WhmcsApiException('ekdosi_bridge resolve failed: '.$message);
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new WhmcsApiException('ekdosi_bridge resolve returned a non-JSON body.');
        }

        return $data;
    }

    /**
     * The resolve endpoint is a sibling of inbound.php in the same addon
     * directory. bridgeUrl always ends with the
     * Company::WHMCS_BRIDGE_PATH (.../ekdosi_bridge/inbound.php) suffix, so we
     * swap the filename. Anchored to end-of-string so a host that happens to
     * contain "/inbound.php" earlier in the path isn't rewritten.
     */
    private function resolveUrl(): string
    {
        return (string) preg_replace('#/inbound\.php$#', '/resolve.php', $this->bridgeUrl);
    }
}
