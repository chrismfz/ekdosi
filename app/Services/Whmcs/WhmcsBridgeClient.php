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
 * after ekdosi files an invoice at AADE. The plugin updates
 * tblinvoices.invoiced for the matching WHMCS invoice id with the
 * MARK value. Stage B-3's scope is intentionally narrow; future
 * bridge-specific operations (mark-as-cancelled, attach-PDF) get
 * added as separate methods here.
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
    ) {
    }

    /**
     * Push the MARK value into tblinvoices.invoiced for the given
     * WHMCS invoice. The plugin authenticates the request via HMAC
     * over the raw body using whmcs_webhook_secret; on success it
     * runs Capsule::table('tblinvoices')->update(['invoiced' => $mark])
     * and returns 200 OK.
     *
     * @param  int  $whmcsInvoiceId  The WHMCS invoice id (tblinvoices.id)
     * @param  string  $mark         The AADE MARK value as a string.
     *                                Stored in tblinvoices.invoiced
     *                                which is a SMALLINT(5) in WHMCS's
     *                                native schema BUT AADE MARKs are
     *                                15-digit ints. The plugin handles
     *                                column-widening on its end (the
     *                                deploy runbook documents the
     *                                required ALTER TABLE).
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
    public function setInvoiced(int $whmcsInvoiceId, string $mark): void
    {
        $body = json_encode([
            'whmcs_invoice_id' => $whmcsInvoiceId,
            'mark'             => $mark,
        ], JSON_THROW_ON_ERROR);

        $signature = 'sha256='.hash_hmac('sha256', $body, $this->webhookSecret);

        try {
            /** @var Response $response */
            $response = $this->http
                ->timeout(self::HTTP_TIMEOUT_SECONDS)
                ->connectTimeout(self::HTTP_CONNECT_TIMEOUT_SECONDS)
                ->withHeaders([
                    'X-Webhook-Signature' => $signature,
                    'Content-Type'        => 'application/json',
                    'Accept'              => 'application/json',
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
}
