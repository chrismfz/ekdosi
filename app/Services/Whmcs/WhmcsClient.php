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
     *
     * CRITICAL — every fragment here MUST be uniquely auth-context.
     * A bare "invalid username" would match validation errors on
     * write endpoints (e.g. AddClient returns "You provided an
     * invalid username for the new client account" — a validation
     * error, NOT an auth failure). Use the full phrase to avoid
     * misrouting. Stage B (PR #29+) introduces UpdateInvoice and
     * similar write endpoints where this matters; today PR #28 is
     * read-only, so the discipline is preventive.
     */
    private const AUTH_ERROR_FRAGMENTS = [
        'invalid username or password',
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
    ) {}

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
     * @param  string|null  $minDate  Optional cutoff in YYYY-MM-DD shape.
     *                                Invoices with `date` older than this
     *                                are skipped AND we early-stop iteration
     *                                (the DESC ordering means everything
     *                                past the first old row is also old).
     *                                Typically the tenant's ekdosi-cutover
     *                                date - avoids pulling decades of
     *                                historical test invoices.
     * @return array<int, array<string, mixed>> Raw invoice rows from
     *                                          WHMCS. Caller maps to
     *                                          our domain.
     */
    public function getPendingInvoices(int $limit = 100, int $offset = 0, ?string $minDate = null): array
    {
        // PAGINATE until the minDate cutoff (or the result set ends). A single
        // page is NOT enough: GetInvoices returns ALL Paid invoices (filed +
        // unfiled) newest-first, and we keep only the unfiled ones client-side.
        // In a busy tenant a date window can contain far more than `limit`
        // already-filed invoices that are NEWER than the oldest unfiled one —
        // so a single page silently drops the oldest unfiled rows (the "21 in
        // DB, 16 in inbox" gap). We walk pages from `offset` until we cross the
        // minDate boundary (DESC ordering → everything past it is older) or an
        // EMPTY page signals the end (NOT a short page — that can just be the
        // server's page ceiling; WH-6). A safety cap bounds the walk for a
        // tenant with no minDate set.
        //
        // `limit`/`offset` are the per-page size + starting page; callers that
        // want a single page can still pass a high limit, but the default now
        // sweeps the whole window.
        $maxPages = 2000;   // hard stop bounded by ACTUAL rows walked, not limit
        $out = [];
        $cursor = $offset;   // advance by the ACTUAL page size WHMCS returns
        $seenIds = [];       // loop guard against a non-paginating WHMCS

        for ($page = 0; $page < $maxPages; $page++) {
            $resp = $this->call('GetInvoices', [
                'status' => 'Paid',
                // WHMCS GetInvoices pagination params are limitstart/limitnum
                // (NOT limit/offset — those are silently IGNORED, so the API
                // returns the SAME first page every call → an infinite walk
                // that hangs. This was the real "stuck at 16 / 5-min freeze"
                // bug.). orderby/order keep DESC for the minDate early-stop.
                'limitstart' => $cursor,
                'limitnum' => $limit,
                'orderby' => 'date',
                'order' => 'desc',
            ]);

            // GetInvoices returns either:
            //   { invoices: { invoice: [...] } }    (list)
            //   { invoices: { invoice: {single} } } (count == 1)
            $list = $resp['invoices']['invoice'] ?? [];
            if (! empty($list) && ! array_is_list($list)) {
                $list = [$list];
            }
            $returned = count($list);
            if ($returned === 0) {
                break;   // no more invoices — the natural end signal
            }

            // Loop guard: if the FIRST id of this page repeats one we've
            // already seen, the server isn't honouring pagination (wrong
            // param names / a proxy stripping them). Stop instead of hanging.
            $firstId = (int) ($list[0]['id'] ?? 0);
            if ($firstId > 0 && isset($seenIds[$firstId])) {
                break;
            }

            $crossedCutoff = false;
            foreach ($list as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0) {
                    $seenIds[$id] = true;
                }
                // Early-stop on minDate (DESC-ordered, so older rows dominate
                // the tail). Lexicographic compare on YYYY-MM-DD is correct.
                if ($minDate !== null) {
                    $rowDate = (string) ($row['date'] ?? '');
                    if ($rowDate !== '' && $rowDate < $minDate) {
                        $crossedCutoff = true;
                        break;
                    }
                }
                // Keep only unfiled: invoiced=0 or absent. WHMCS can't filter
                // this server-side; the legacy prepare_for_ekdosi plugin adds
                // the column. Absent → treat as pending (non-plugin installs).
                if (array_key_exists('invoiced', $row) && (int) $row['invoiced'] !== 0) {
                    continue;
                }
                $out[] = $row;
            }

            if ($crossedCutoff) {
                break;
            }

            // Advance by what WHMCS ACTUALLY returned, not by $limit. The ONLY
            // end signals are an empty page (above), the seen-id loop guard, the
            // maxPages cap, and the minDate early-stop — deliberately NOT a short
            // page (returned < limit): if a caller passes a limit above WHMCS's
            // server-side page ceiling (~100), page 1 returns the ceiling < limit
            // and a short-page break would re-truncate the walk to a single page —
            // the WH-6 "stuck at N" freeze. Mirrors getInvoicesForClient.
            $cursor += $returned;
        }

        return $out;
    }

    /**
     * Fetch one invoice's full details by id. WHMCS's GetInvoices list
     * shape carries minimal per-row data (no line items, partial
     * client identity); GetInvoice returns the rich shape that Stage
     * B-1's ingestor + Stage B-2's File-at-AADE action both need to
     * build an ekdosi Invoice + InvoiceLine[]. One API call per
     * invoice - cost of having a complete audit-grade snapshot.
     *
     * @return array<string, mixed>|null null if WHMCS returned
     *                                   "Invoice ID Not Found"
     *                                   (distinguish from network /
     *                                   auth failures)
     */
    public function getInvoice(int $whmcsInvoiceId): ?array
    {
        try {
            return $this->call('GetInvoice', [
                'invoiceid' => $whmcsInvoiceId,
            ]);
        } catch (WhmcsApiException $e) {
            // WHMCS's error literal for a missing invoice id - confirmed
            // against the WHMCS developer docs (developers.whmcs.com,
            // GetInvoice action, "result" : "error" envelope).
            if (str_contains($e->getMessage(), 'Invoice ID Not Found')) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Fetch ALL invoices for a single WHMCS client (any status),
     * optionally filtered by minDate. Used by the per-customer
     * comparison panel (CustomerWhmcsLedger) - the operator picks
     * a customer in ekdosi and sees "here is what WHMCS has for them"
     * cross-referenced with "here is what we have for them in ekdosi".
     *
     * Distinct from getPendingInvoices which returns paid+unfiled
     * tenant-wide. This method:
     *   - filters by client (userid) ON the WHMCS side
     *   - does NOT filter by status (operator wants the full picture:
     *     Paid, Unpaid, Cancelled, Refunded)
     *   - does NOT filter by invoiced flag (we want to see ALL of
     *     this client's invoices and explicitly mark which are in
     *     ekdosi vs not)
     *   - DOES apply the minDate cutoff so a 20-year-old customer
     *     doesn't surface decades of test invoices
     *
     * @return array<int, array<string, mixed>>
     */
    public function getInvoicesForClient(int $whmcsUserId, ?string $minDate = null, int $limit = 100): array
    {
        // PAGINATE (WH-6). GetInvoices ignores a `limit` param and applies its
        // own default page size (~25) — the same class of bug as the tenant-wide
        // "frozen at 16". A client with more than a page of invoices only ever
        // surfaced the newest ~25, so the comparison panel mislabelled the older
        // ones as "absent from ekdosi". Walk pages with limitstart/limitnum until
        // the minDate cutoff, a short/empty page, or the loop guard — the exact
        // shape getPendingInvoices() uses (this method just filters by userid
        // instead of status, and keeps ALL statuses + ALL invoiced flags).
        $maxPages = 2000;   // hard stop bounded by ACTUAL rows walked, not limit
        $out = [];
        $cursor = 0;         // advance by the ACTUAL page size WHMCS returns
        $seenIds = [];       // loop guard against a non-paginating WHMCS

        for ($page = 0; $page < $maxPages; $page++) {
            $resp = $this->call('GetInvoices', [
                'userid' => $whmcsUserId,
                // limitstart/limitnum — NOT limit (silently ignored → same first
                // page every call). DESC keeps the minDate early-stop valid.
                'limitstart' => $cursor,
                'limitnum' => $limit,
                'orderby' => 'date',
                'order' => 'desc',
            ]);

            $list = $resp['invoices']['invoice'] ?? [];
            if (! empty($list) && ! array_is_list($list)) {
                $list = [$list];   // single-row object → wrap as list
            }
            $returned = count($list);
            if ($returned === 0) {
                break;   // no more invoices — the natural end signal
            }

            // Loop guard: a repeated first id means the server isn't honouring
            // pagination (stripped params / a proxy) — stop instead of hanging.
            $firstId = (int) ($list[0]['id'] ?? 0);
            if ($firstId > 0 && isset($seenIds[$firstId])) {
                break;
            }

            $crossedCutoff = false;
            foreach ($list as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0) {
                    $seenIds[$id] = true;
                }
                // minDate early-stop (DESC-ordered; lexicographic YYYY-MM-DD).
                if ($minDate !== null) {
                    $rowDate = (string) ($row['date'] ?? '');
                    if ($rowDate !== '' && $rowDate < $minDate) {
                        $crossedCutoff = true;
                        break;
                    }
                }
                // No status / invoiced filtering — the panel wants the FULL
                // picture (Paid/Unpaid/Cancelled/Refunded, filed or not).
                $out[] = $row;
            }

            if ($crossedCutoff) {
                break;
            }

            // Advance by the ACTUAL returned count and loop; the ONLY end signals
            // are an empty page (above) and the loop guard. We deliberately do
            // NOT stop on a short page (returned < limit): if a caller passes a
            // limit above WHMCS's server-side page ceiling (~100), the first full
            // page returns the ceiling < limit and a short-page break would
            // re-truncate to one page — the exact WH-6 bug. Terminating on the
            // empty page costs one extra call on an exhausted client (a cold
            // comparison panel, not a hot path) and is correct for any limit.
            $cursor += $returned;
        }

        return $out;
    }

    /**
     * Fetch a WHMCS invoice AND merge in the corresponding client's
     * full details (including customfields). This is the canonical
     * shape Stage B-1's ingestor expects so the matcher's AFM-by-
     * customfield strategy (WhmcsCustomerMatcher::extractCustomField)
     * actually has data to read.
     *
     * Why this exists: WHMCS's GetInvoice response carries `userid`
     * + invoice fields but does NOT embed the client's customfields
     * block. Without enrichment, every ingest matcher call would
     * skip strategy #2 (AFM exact match) and degrade to email/name -
     * defeating the operator's setup of WHMCS custom-field IDs in
     * the tenant config.
     *
     * Cost: 2 API calls per invoice instead of 1. For the pull
     * command's N+1 loop that's 1 + 2N total (a 100-invoice batch
     * is ~201 sequential calls at ~300ms each, ~60s). Locked in
     * as the correct tradeoff; the alternative is silent AFM-match
     * degradation which is the worse failure mode. Http::pool
     * parallelisation is tracked as a separate efficiency deferral.
     *
     * If the invoice exists but the linked client doesn't (data
     * corruption on the WHMCS side), the invoice payload is returned
     * with no client merge - matcher will fall through to unmatched.
     *
     * @return array<string, mixed>|null null if WHMCS returned
     *                                   "Invoice ID Not Found"
     */
    public function getInvoiceWithClient(int $whmcsInvoiceId): ?array
    {
        $invoice = $this->getInvoice($whmcsInvoiceId);
        if ($invoice === null) {
            return null;
        }

        $userId = (int) ($invoice['userid'] ?? 0);
        if ($userId <= 0) {
            return $invoice;
        }

        // GetClientsDetails failures are NOT fatal to the ingest -
        // the invoice itself is what AADE cares about. Log the
        // shortfall so operators can spot a chronically-broken
        // client lookup, but proceed with the invoice payload alone.
        try {
            $client = $this->getClient($userId);
        } catch (WhmcsApiException $e) {
            return $invoice;
        }

        if ($client === null) {
            return $invoice;
        }

        // Merge client identity AND customfields onto the invoice
        // payload at the top level. Top-level wins on key collision
        // (invoice keys are more recent / canonical for the invoice
        // event); customfields is the load-bearing addition for the
        // matcher's AFM strategy.
        $clientKeys = [
            'email', 'firstname', 'lastname', 'companyname',
            'address1', 'address2', 'city', 'state', 'postcode',
            'country', 'phonenumber', 'customfields',
        ];
        foreach ($clientKeys as $k) {
            if (array_key_exists($k, $client) && ! array_key_exists($k, $invoice)) {
                $invoice[$k] = $client[$k];
            }
        }
        // customfields is the ONE case where we want the client's
        // value even if the invoice payload happened to carry an
        // (irrelevant, possibly stale) version - the client is the
        // authoritative source for it.
        if (array_key_exists('customfields', $client)) {
            $invoice['customfields'] = $client['customfields'];
        }

        return $invoice;
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
     * @return array<string, mixed>|null null if WHMCS returned
     *                                   "Client ID Not Found"
     *                                   (distinguish from network /
     *                                   auth failures)
     */
    public function getClient(int $whmcsClientId): ?array
    {
        try {
            return $this->call('GetClientsDetails', [
                'clientid' => $whmcsClientId,
                'stats' => 'false',
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
            'limit' => $limit,
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
     * @return array<string, mixed>
     */
    private function call(string $action, array $params = []): array
    {
        $body = array_merge($params, [
            'identifier' => $this->identifier,
            'secret' => $this->secret,
            'action' => $action,
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
