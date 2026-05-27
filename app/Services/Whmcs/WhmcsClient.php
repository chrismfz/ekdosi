<?php

namespace App\Services\Whmcs;

use App\Exceptions\Whmcs\WhmcsApiException;
use App\Exceptions\Whmcs\WhmcsAuthenticationFailed;
use App\Exceptions\Whmcs\WhmcsUnreachable;
use App\Models\Company;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Thin wrapper around the WHMCS REST API
 * (POST {url}/includes/api.php, form-encoded body).
 *
 * What this PR covers (Stage A — read-only):
 *   - testConnection() — sanity ping (action=GetActivityLog?limit=1)
 *   - getPendingInvoices() — paid invoices with `invoiced=0` flag set
 *     (the legacy "ready to file" gate from FAutoInvoice.dfm)
 *   - getClient($whmcsClientId) — for AFM/email/name matching
 *   - searchClients($needle) — for the Filament "link to WHMCS" picker
 *
 * What's NOT here (Stage B — PR #29):
 *   - updateInvoice() (write-back of `invoiced=1` after we file)
 *   - registerInvoicePayment() / other writes
 *   - Webhook handlers (we poll, not listen, per CLAUDE.md decision)
 *
 * Constructed via WhmcsClientFactory::for($tenant); the factory
 * throws WhmcsNotConfigured if credentials are missing. We accept
 * those via constructor — the factory's job is the per-tenant
 * resolution, ours is just the wire format.
 *
 * WHMCS API specifics this code relies on (verified against WHMCS
 * docs as of 8.x; the API is stable across versions):
 *   - All actions go through ONE endpoint: {url} (the api.php path).
 *   - Auth: identifier + secret as form fields on every request.
 *     "Bearer" header tokens also work but identifier+secret is the
 *     more compatible default.
 *   - responsetype=json to get JSON back (XML is the default).
 *   - Responses ALWAYS have `result: success | error`. The success
 *     payload's shape depends on the action.
 */
class WhmcsClient
{
    /**
     * Conservative HTTP timeout for sync calls. The artisan pull
     * command may iterate over hundreds of invoices per tenant; if
     * one tenant's WHMCS is slow we don't want to wedge the whole
     * cycle. 20s response budget + 5s connect budget = 25s worst-
     * case wedge on a network blackhole. Short enough to fail
     * visibly to a cron wrapper, long enough to tolerate a slow
     * upstream during a normal request.
     */
    private const HTTP_TIMEOUT_SECONDS = 20;

    private const HTTP_CONNECT_TIMEOUT_SECONDS = 5;

    /**
     * Substring fragments (lower-cased) that mark a response as an
     * AUTH failure rather than a generic protocol error. WHMCS uses
     * several distinct messages depending on what went wrong:
     *   - "Invalid Username or Password" — bad identifier/secret pair
     *     (the most common case operators hit)
     *   - "Invalid Permissions" — credentials valid but role lacks
     *     the requested action
     *   - "Invalid IP" — IP allowlist on the API credential rejects
     *     our source IP
     *   - "Authentication Failed" — generic catch-all from older WHMCS
     *   - "Invalid Credentials" — newer phrasing on some 8.x versions
     * Per WHMCS dev docs (https://developers.whmcs.com/api/error-handling/).
     * Sourced from real-world tenant feedback + WHMCS source. Extend
     * here when a new variant surfaces.
     */
    private const AUTH_ERROR_FRAGMENTS = [
        'invalid username',
        'invalid permissions',
        'invalid ip',
        'authentication failed',
        'invalid credentials',
    ];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $apiUrl,
        private readonly string $identifier,
        private readonly string $secret,
    ) {
    }

    /**
     * Issue a one-call probe to verify the URL + credentials work.
     * Uses GetActivityLog with limit=1 because it's cheap (returns
     * a single row regardless of WHMCS instance size) and has been
     * available since WHMCS 5 — a stable target for backwards-
     * compatible probes.
     *
     * Returns the WHMCS version string from the response (so the
     * caller can surface "Connected to WHMCS 8.7.2" in the UI).
     *
     * Throws WhmcsAuthenticationFailed / WhmcsUnreachable / WhmcsApiException
     * — caller distinguishes for the toast message.
     */
    public function testConnection(): string
    {
        $resp = $this->call('GetActivityLog', ['limit' => 1]);

        // GetActivityLog doesn't return a version directly, but its
        // response envelope has the WHMCS version on most 7.x+
        // installs as "whmcsversion". Older installs omit it; we
        // surface "unknown" rather than throwing, since the call
        // itself succeeding IS the probe result.
        return (string) ($resp['whmcsversion'] ?? 'unknown');
    }

    /**
     * Fetch invoices that have been paid by the customer but not yet
     * marked as filed (`invoiced=0` in WHMCS's `tblinvoices` schema —
     * verified at legacy/ekdosi-main/FAutoInvoice.dfm:QueryInvoices
     * line 22 `WHERE mi.status='Paid' AND mi.invoiced = 0`).
     *
     * WHMCS doesn't expose `invoiced` as a filter parameter on
     * GetInvoices — we filter client-side after fetching all Paid
     * invoices, because WHMCS will only ever return reasonable
     * page sizes anyway (limit defaults to 25, max 100 per docs).
     *
     * @param  int  $limit  Max rows per WHMCS API call. Default 100
     *                      (the WHMCS-side ceiling); the caller paginates
     *                      via $offset if it needs more.
     *
     * @return array<int, array<string, mixed>> Raw invoice rows from
     *                                          WHMCS. Caller maps to
     *                                          our domain.
     */
    public function getPendingInvoices(int $limit = 100, int $offset = 0): array
    {
        $resp = $this->call('GetInvoices', [
            'status'    => 'Paid',
            'limit'     => $limit,
            'offset'    => $offset,
            'orderby'   => 'date',
            'order'     => 'asc',
        ]);

        // GetInvoices returns either:
        //   { invoices: { invoice: [...] } }   (XML-ish shape WHMCS
        //                                       preserves under JSON)
        //   { invoices: { invoice: {single} } } (when count == 1)
        $list = $resp['invoices']['invoice'] ?? [];

        // Normalise the single-row-returned-as-object case.
        if (! empty($list) && ! array_is_list($list)) {
            $list = [$list];
        }

        // Client-side filter for `invoiced=0`. WHMCS's `invoiced`
        // field is the custom column the legacy `prepare_for_ekdosi`
        // plugin manages (legacy/whmcs/prepare_for_ekdosi/); not all
        // WHMCS installs will have it. Without the column the field
        // is absent from the response; treat absent as "pending"
        // (worth surfacing for the operator's preview).
        return array_values(array_filter($list, function (array $row): bool {
            if (! array_key_exists('invoiced', $row)) {
                return true;  // assume pending; preview will surface
            }
            return (int) $row['invoiced'] === 0;
        }));
    }

    /**
     * Fetch one client's full details. Used by the Filament "Link to
     * WHMCS" picker AND by the auto-match heuristic during the pull
     * preview.
     *
     * Returns the raw response payload; caller picks fields. We do
     * pass `stats=false` to skip the optional expensive sub-queries
     * (paid totals, last login, etc) — we only need identity.
     *
     * @return array<string, mixed>|null  null if WHMCS returned
     *                                    "Client ID Not Found"
     *                                    (distinguish from network /
     *                                    auth failures)
     */
    public function getClient(int $whmcsClientId): ?array
    {
        try {
            return $this->call('GetClientsDetails', [
                'clientid' => $whmcsClientId,
                'stats'    => 'false',
            ]);
        } catch (WhmcsApiException $e) {
            // WHMCS's standard error string for missing client.
            // Distinguish from generic API failures so the caller
            // can render "Customer no longer exists in WHMCS"
            // instead of "Send failed".
            if (str_contains($e->getMessage(), 'Client ID Not Found')) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Search WHMCS clients by name / email / company. Used for the
     * Filament "Link to WHMCS" customer-matching action — operator
     * types part of the customer name; we return candidates.
     *
     * WHMCS's GetClients action accepts a `search` parameter that
     * matches across firstname, lastname, companyname, email — the
     * exact shape we need.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchClients(string $needle, int $limit = 25): array
    {
        $resp = $this->call('GetClients', [
            'search' => $needle,
            'limit'  => $limit,
        ]);

        $list = $resp['clients']['client'] ?? [];
        if (! empty($list) && ! array_is_list($list)) {
            $list = [$list];
        }
        return $list;
    }

    /**
     * Single point of contact with the WHMCS API. All actions go
     * through here so auth, encoding, error mapping, and retry
     * policy are uniform.
     *
     * Network failure → WhmcsUnreachable
     * Auth rejection → WhmcsAuthenticationFailed
     * Other WHMCS-side error → WhmcsApiException with WHMCS's own message
     *
     * @param  array<string, mixed>  $params
     *
     * @return array<string, mixed>
     */
    private function call(string $action, array $params = []): array
    {
        $body = array_merge($params, [
            'identifier'   => $this->identifier,
            'secret'       => $this->secret,
            'action'       => $action,
            'responsetype' => 'json',
        ]);

        try {
            /** @var Response $response */
            $response = $this->http
                ->timeout(self::HTTP_TIMEOUT_SECONDS)
                ->connectTimeout(self::HTTP_CONNECT_TIMEOUT_SECONDS)
                ->asForm()
                ->post($this->apiUrl, $body);
        } catch (ConnectionException $e) {
            throw new WhmcsUnreachable(
                "WHMCS endpoint unreachable: {$e->getMessage()}",
                0,
                $e,
            );
        }

        if (! $response->successful()) {
            // HTTP-level failure — 5xx WHMCS down, 4xx URL wrong,
            // etc. Body is usually HTML at this point (an Apache or
            // WHMCS error page), not JSON. Don't try to parse.
            throw new WhmcsApiException(
                "WHMCS HTTP {$response->status()} for action={$action}"
            );
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new WhmcsApiException(
                "WHMCS returned non-JSON response for action={$action}"
            );
        }

        $result = (string) ($payload['result'] ?? '');
        if ($result === 'success') {
            return $payload;
        }

        // result=error — WHMCS-side rejection. The `message` field
        // carries WHMCS's own description.
        $message = (string) ($payload['message'] ?? 'unknown WHMCS error');

        // Auth rejections have specific message text. Distinguish
        // so the UI can offer the right remediation. Fragment list
        // is the AUTH_ERROR_FRAGMENTS constant; covers the variants
        // WHMCS uses across versions + IP allowlist + permissions.
        $messageLower = strtolower($message);
        foreach (self::AUTH_ERROR_FRAGMENTS as $fragment) {
            if (str_contains($messageLower, $fragment)) {
                throw new WhmcsAuthenticationFailed("WHMCS auth failed: {$message}");
            }
        }

        throw new WhmcsApiException("WHMCS error on {$action}: {$message}");
    }
}
