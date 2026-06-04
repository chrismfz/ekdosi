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
    public function setInvoiced(int $whmcsInvoiceId, string $mark, ?string $invcode = null, ?string $state = null, ?string $pdfUrl = null): void
    {
        $bodyData = [
            'whmcs_invoice_id' => $whmcsInvoiceId,
            'mark' => $mark,
        ];
        if ($invcode !== null && $invcode !== '') {
            $bodyData['invcode'] = $invcode;
        }
        // AADE state ('active'/'cancelled') so the WHMCS badge can show
        // «ΑΚΥΡΩΜΕΝΟ» after a cancellation. Omitted when null (older flow).
        if ($state !== null && $state !== '') {
            $bodyData['state'] = $state;
        }
        // Signed public URL to the official παραστατικό PDF (hosted on ekdosi);
        // the bridge surfaces it as a link. Omitted when null.
        if ($pdfUrl !== null && $pdfUrl !== '') {
            $bodyData['pdf_url'] = $pdfUrl;
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
     * Slice 1 of "the bridge is the inbox feed": one page of full invoice
     * payloads (invoice + client identity + customfields + line items), built
     * by the plugin's InvoiceFeed — shape-compatible with the native
     * WhmcsClient::getInvoiceWithClient, so WhmcsInvoiceIngestor consumes each
     * payload UNCHANGED. Server-side filtered (paid+unfiled by default) and
     * paginated — one HMAC call replaces the native API's 1+2N round-trips.
     *
     * Throws WhmcsUnreachable / WhmcsApiException like resolveThirdParty.
     *
     * @return array<int, array<string, mixed>>  the page's payloads (empty = end)
     */
    public function fetchPendingInvoices(int $offset, int $limit = 100, ?string $since = null, string $status = 'paid_unfiled', bool $withRouting = false): array
    {
        $body = ['op' => 'invoices', 'status' => $status, 'offset' => $offset, 'limit' => $limit];
        if ($since !== null && $since !== '') {
            $body['since'] = $since;
        }
        if ($withRouting) {
            // Ask the plugin to embed third-party routing per invoice so the
            // ingestor needs no separate resolve call (Slice 2).
            $body['with_routing'] = true;
        }

        $data = $this->postResolve($body);
        $invoices = $data['invoices'] ?? [];
        if (! is_array($invoices)) {
            return [];
        }

        // Keep only well-formed payloads (must carry an invoice id the ingestor
        // can stage on).
        return array_values(array_filter($invoices, static fn ($p): bool => is_array($p)
            && (int) ($p['invoiceid'] ?? $p['id'] ?? 0) > 0));
    }

    /**
     * Plugin-API op=invoice: fetch ONE invoice's full payload (the single-invoice
     * twin of fetchPendingInvoices), so the push path («Αποστολή» → invoice-paid
     * webhook) can pull the canonical payload from the bridge instead of the
     * native WHMCS API. Shape-compatible with getInvoiceWithClient, so the
     * ingestor consumes it unchanged.
     *
     * Returns null when the bridge reports no such invoice (`invoice: null`),
     * which the caller maps to "not found". Throws WhmcsUnreachable /
     * WhmcsApiException like the other ops (a genuine transport/auth failure must
     * surface — never be silently swallowed as "not found").
     *
     * @return array<string, mixed>|null
     */
    public function fetchInvoice(int $whmcsInvoiceId, bool $withRouting = false): ?array
    {
        $body = ['op' => 'invoice', 'invoice_id' => $whmcsInvoiceId];
        if ($withRouting) {
            $body['with_routing'] = true;
        }

        $data = $this->postResolve($body);
        $invoice = $data['invoice'] ?? null;

        return is_array($invoice) && (int) ($invoice['invoiceid'] ?? $invoice['id'] ?? 0) > 0
            ? $invoice
            : null;
    }

    /**
     * Historical backfill: one page of (whmcs_id, invoiced) links for invoices
     * the LEGACY app filed (invoiced > 0). invoiced holds the legacy ekdosi
     * INVOICE_ID, which the ETL kept as invoices.legacy_id — so the caller can
     * stamp invoices.whmcs_invoice_id where legacy_id = invoiced. Paginated;
     * an empty list signals the end. Throws WhmcsUnreachable / WhmcsApiException
     * like resolveThirdParty.
     *
     * @return list<array{whmcs_id: int, invoiced: int}>
     */
    public function getLegacyInvoiceLinks(int $offset, int $limit = 500): array
    {
        $data = $this->postResolve(['op' => 'legacy_invoice_links', 'offset' => $offset, 'limit' => $limit]);
        $links = $data['links'] ?? [];
        if (! is_array($links)) {
            return [];
        }

        $out = [];
        foreach ($links as $row) {
            if (! is_array($row)) {
                continue;
            }
            $whmcsId = (int) ($row['whmcs_id'] ?? 0);
            $invoiced = (int) ($row['invoiced'] ?? 0);
            if ($whmcsId > 0 && $invoiced > 0) {
                $out[] = ['whmcs_id' => $whmcsId, 'invoiced' => $invoiced];
            }
        }

        return $out;
    }

    /**
     * The WHMCS client custom-field catalogue (id + name + adminonly), so the
     * operator can MAP role→field via a picker instead of hand-typing integer
     * ids (which silently break when WHMCS reassigns them — the empty/forgotten
     * map is exactly what made AFM + invoice-intent vanish from the inbox).
     * Throws WhmcsUnreachable / WhmcsApiException like the other ops.
     *
     * @return list<array{id: int, fieldname: string, adminonly: bool}>
     */
    public function listCustomFields(): array
    {
        $data = $this->postResolve(['op' => 'custom_fields']);
        $fields = $data['fields'] ?? [];
        if (! is_array($fields)) {
            return [];
        }

        $out = [];
        foreach ($fields as $f) {
            if (! is_array($f)) {
                continue;
            }
            $id = (int) ($f['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'fieldname' => (string) ($f['fieldname'] ?? ''),
                'adminonly' => (bool) ($f['adminonly'] ?? false),
            ];
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
