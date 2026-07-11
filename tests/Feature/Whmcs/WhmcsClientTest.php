<?php

namespace Tests\Feature\Whmcs;

use App\Exceptions\Whmcs\WhmcsApiException;
use App\Exceptions\Whmcs\WhmcsAuthenticationFailed;
use App\Exceptions\Whmcs\WhmcsUnreachable;
use App\Services\Whmcs\WhmcsClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Locks in the WHMCS API wire contract:
 *   - identifier + secret + responsetype=json on every call
 *   - result=success / result=error payload shape
 *   - single-row "object" vs multi-row "list" response shape normalisation
 *   - exception mapping for auth / unreachable / generic protocol errors
 *
 * Uses Http::fake() so no real WHMCS install is needed. Each test
 * builds a single fake response, instantiates the client with the
 * faked HTTP factory, and asserts the parsed shape.
 */
class WhmcsClientTest extends TestCase
{
    private function makeClient(): WhmcsClient
    {
        return new WhmcsClient(
            http: app(HttpFactory::class),
            apiUrl: 'https://example.gr/includes/api.php',
            identifier: 'TEST_ID',
            secret: 'TEST_SECRET',
        );
    }

    public function test_test_connection_returns_whmcs_version_on_success(): void
    {
        Http::fake([
            'example.gr/*' => Http::response([
                'result'        => 'success',
                'totalresults'  => 0,
                'whmcsversion'  => '8.7.2',
                'activity'      => ['entry' => []],
            ], 200),
        ]);

        $version = $this->makeClient()->testConnection();

        $this->assertSame('8.7.2', $version);
        Http::assertSent(function ($request) {
            $data = $request->data();
            return $data['action'] === 'GetActivityLog'
                && $data['identifier'] === 'TEST_ID'
                && $data['secret'] === 'TEST_SECRET'
                && $data['responsetype'] === 'json'
                && (int) ($data['limit'] ?? 0) === 1;
        });
    }

    /**
     * Lock the WIRE format. The body MUST be form-encoded — WHMCS's
     * /includes/api.php rejects JSON-bodied requests. Without this
     * test, a future refactor that drops asForm() (e.g. switching
     * the global default Http settings) would 100% break production
     * but all other tests would still pass (`$request->data()`
     * normalises both encodings, verified at vendor/laravel/.../
     * Http/Client/Request.php).
     */
    public function test_request_uses_form_encoded_body_not_json(): void
    {
        Http::fake([
            'example.gr/*' => Http::response(['result' => 'success'], 200),
        ]);

        $this->makeClient()->testConnection();

        Http::assertSent(function ($request) {
            // asForm() sets exactly this header — verified at
            // vendor/laravel/framework/.../Http/Client/PendingRequest.php
            $contentType = $request->header('Content-Type')[0] ?? '';
            return str_contains($contentType, 'application/x-www-form-urlencoded');
        });
    }

    public function test_test_connection_returns_unknown_when_version_field_missing(): void
    {
        Http::fake([
            'example.gr/*' => Http::response(['result' => 'success', 'totalresults' => 0], 200),
        ]);

        $this->assertSame('unknown', $this->makeClient()->testConnection());
    }

    public function test_auth_rejection_maps_to_authentication_failed(): void
    {
        Http::fake([
            'example.gr/*' => Http::response([
                'result'  => 'error',
                'message' => 'Invalid IP',
            ], 200),
        ]);

        $this->expectException(WhmcsAuthenticationFailed::class);
        $this->makeClient()->testConnection();
    }

    public function test_authentication_failed_message_variant(): void
    {
        Http::fake([
            'example.gr/*' => Http::response([
                'result'  => 'error',
                'message' => 'Authentication Failed',
            ], 200),
        ]);

        $this->expectException(WhmcsAuthenticationFailed::class);
        $this->makeClient()->testConnection();
    }

    /**
     * WHMCS's most common bad-credential message is "Invalid Username
     * or Password" — per official dev docs. Pre-PR-28-review the
     * client matched on "invalid ip", "authentication failed",
     * "invalid credentials" but missed the actual production
     * variant. Verifying every fragment in AUTH_ERROR_FRAGMENTS
     * routes to WhmcsAuthenticationFailed (not generic
     * WhmcsApiException) so the UI can surface "credentials
     * rejected" rather than the unhelpful "WHMCS error".
     *
     */
    #[DataProvider('authErrorMessageProvider')]
    public function test_auth_error_fragments_all_route_to_authentication_failed(string $message): void
    {
        Http::fake([
            'example.gr/*' => Http::response([
                'result'  => 'error',
                'message' => $message,
            ], 200),
        ]);

        $this->expectException(WhmcsAuthenticationFailed::class);
        $this->makeClient()->testConnection();
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function authErrorMessageProvider(): iterable
    {
        // Every variant WHMCS uses, per dev docs + production
        // tenant feedback. Adding a new message to
        // AUTH_ERROR_FRAGMENTS must come with a row here.
        yield 'invalid_username_or_password' => ['Invalid Username or Password'];
        yield 'invalid_permissions'          => ['Invalid Permissions'];
        yield 'invalid_ip'                   => ['Invalid IP'];
        yield 'authentication_failed'        => ['Authentication Failed'];
        yield 'invalid_credentials'          => ['Invalid Credentials'];
        // Case-insensitive match — lowercase variants must also work
        yield 'lowercase_invalid_username_or_password' => ['invalid username or password'];
    }

    public function test_generic_whmcs_error_maps_to_base_exception(): void
    {
        Http::fake([
            'example.gr/*' => Http::response([
                'result'  => 'error',
                'message' => 'Action not permitted',
            ], 200),
        ]);

        $this->expectException(WhmcsApiException::class);
        $this->expectExceptionMessageMatches('/Action not permitted/');
        $this->makeClient()->testConnection();
    }

    public function test_http_5xx_maps_to_api_exception(): void
    {
        Http::fake([
            'example.gr/*' => Http::response('<html>Internal Server Error</html>', 500),
        ]);

        $this->expectException(WhmcsApiException::class);
        $this->expectExceptionMessageMatches('/HTTP 500/');
        $this->makeClient()->testConnection();
    }

    public function test_connection_exception_maps_to_unreachable(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 6: Could not resolve host');
        });

        $this->expectException(WhmcsUnreachable::class);
        $this->expectExceptionMessageMatches('/unreachable/i');
        $this->makeClient()->testConnection();
    }

    public function test_get_pending_invoices_filters_unfiled_only(): void
    {
        // Mixed payload: two unfiled (invoiced=0), one already filed
        // (invoiced=1), one without the field at all (legacy WHMCS
        // without the prepare_for_ekdosi plugin — treat as pending).
        // Page 1 has the rows; page 2 is empty (the loop's end signal —
        // it now stops ONLY on an empty page, never on count<limit, since
        // WHMCS caps page size server-side).
        Http::fakeSequence('example.gr/*')
            ->push([
                'result'       => 'success',
                'totalresults' => 3,
                'invoices'     => ['invoice' => [
                    ['id' => 1001, 'status' => 'Paid', 'invoiced' => 0, 'total' => 10.00],
                    ['id' => 1002, 'status' => 'Paid', 'invoiced' => 1, 'total' => 20.00],
                    ['id' => 1003, 'status' => 'Paid', 'invoiced' => 0, 'total' => 30.00],
                    ['id' => 1004, 'status' => 'Paid', 'total' => 40.00],  // no invoiced field
                ]],
            ], 200)
            ->push(['result' => 'success', 'invoices' => ['invoice' => []]], 200);

        $rows = $this->makeClient()->getPendingInvoices();

        $this->assertCount(3, $rows);
        $ids = array_column($rows, 'id');
        $this->assertSame([1001, 1003, 1004], $ids);
    }

    public function test_get_pending_invoices_normalises_single_row_object_shape(): void
    {
        // WHMCS returns count=1 as a single object, not a list.
        Http::fakeSequence('example.gr/*')
            ->push([
                'result'       => 'success',
                'totalresults' => 1,
                'invoices'     => ['invoice' => ['id' => 1001, 'invoiced' => 0]],
            ], 200)
            ->push(['result' => 'success', 'invoices' => ['invoice' => []]], 200);

        $rows = $this->makeClient()->getPendingInvoices();

        $this->assertCount(1, $rows);
        $this->assertSame(1001, $rows[0]['id']);
    }

    public function test_get_pending_invoices_empty_on_no_rows(): void
    {
        Http::fake([
            'example.gr/*' => Http::response(['result' => 'success', 'totalresults' => 0], 200),
        ]);

        $this->assertSame([], $this->makeClient()->getPendingInvoices());
    }

    public function test_get_pending_invoices_skips_rows_older_than_min_date(): void
    {
        // Long-running tenant (myip-style): mixed historical data
        // marked invoiced=0 from years ago + recent legitimate pending
        // rows. With minDate set to a cutover date, only the recent
        // rows pass through.
        Http::fake([
            'example.gr/*' => Http::response([
                'result' => 'success',
                'invoices' => ['invoice' => [
                    // DESC ordering: newest first
                    ['id' => 5001, 'date' => '2026-05-15', 'invoiced' => 0, 'total' => 50],
                    ['id' => 5002, 'date' => '2026-04-20', 'invoiced' => 0, 'total' => 60],
                    ['id' => 5003, 'date' => '2026-01-10', 'invoiced' => 0, 'total' => 70],
                    // ↓ historical test rows below the cutover
                    ['id' => 1042, 'date' => '2008-08-11', 'invoiced' => 0, 'total' => 5],
                    ['id' => 1041, 'date' => '2008-07-03', 'invoiced' => 0, 'total' => 3],
                    ['id' => 1040, 'date' => '2007-12-10', 'invoiced' => 0, 'total' => 2],
                ]],
            ], 200),
        ]);

        $rows = $this->makeClient()->getPendingInvoices(minDate: '2025-01-01');

        // Three recent rows passed; historical (pre-2025) skipped.
        $this->assertCount(3, $rows);
        $this->assertSame([5001, 5002, 5003], array_column($rows, 'id'));
    }

    public function test_get_pending_invoices_min_date_uses_early_stop_on_desc_order(): void
    {
        // The cost story: a tenant with 16K filed invoices would force
        // a foreach over every row. DESC + early-stop means once we
        // see a row older than minDate, we break - no further iteration.
        // This test asserts the loop break by including a row PAST the
        // cutover that, if not skipped via break, would be returned.
        Http::fake([
            'example.gr/*' => Http::response([
                'result' => 'success',
                'invoices' => ['invoice' => [
                    ['id' => 9001, 'date' => '2026-05-15', 'invoiced' => 0],
                    ['id' => 8000, 'date' => '2020-01-01', 'invoiced' => 0],   // <- triggers break
                    // The following row WOULD pass minDate=2025-01-01 if
                    // we kept iterating. Because we break at 8000, it
                    // never reaches the output array. This is intentional
                    // - real WHMCS responses are sorted, and any
                    // out-of-order row at this point indicates either
                    // a WHMCS bug or operator-tampered DB; either way,
                    // erring on the side of "skip" is the safer default.
                    ['id' => 9002, 'date' => '2026-04-20', 'invoiced' => 0],
                ]],
            ], 200),
        ]);

        $rows = $this->makeClient()->getPendingInvoices(minDate: '2025-01-01');

        $this->assertCount(1, $rows);
        $this->assertSame(9001, $rows[0]['id']);
    }

    public function test_get_pending_invoices_paginates_until_min_date(): void
    {
        // Regression for the "21 unfiled in DB, only 16 reached the inbox" gap:
        // a single page (limit) of newest-first Paid invoices can be dominated
        // by ALREADY-FILED rows, pushing the OLDEST unfiled rows off the page.
        // The fetcher must paginate until it crosses minDate, not stop at page
        // 1. Here limit=2: page 0 is FULL (2 filed rows, count==limit → keep
        // going), page 1 carries the unfiled row we'd otherwise miss, page 2
        // crosses minDate and stops.
        Http::fakeSequence('example.gr/*')
            ->push([
                'result' => 'success',
                'invoices' => ['invoice' => [
                    ['id' => 100, 'date' => '2026-05-30', 'invoiced' => 700001],   // filed
                    ['id' => 99, 'date' => '2026-05-29', 'invoiced' => 700002],    // filed
                ]],
            ], 200)
            ->push([
                'result' => 'success',
                'invoices' => ['invoice' => [
                    ['id' => 50, 'date' => '2026-05-20', 'invoiced' => 0],         // unfiled — the one a single page missed
                    ['id' => 49, 'date' => '2026-05-19', 'invoiced' => 700003],    // filed
                ]],
            ], 200)
            ->push([
                'result' => 'success',
                'invoices' => ['invoice' => [
                    ['id' => 10, 'date' => '2026-04-01', 'invoiced' => 0],         // older than minDate → triggers stop
                ]],
            ], 200);

        $rows = $this->makeClient()->getPendingInvoices(limit: 2, minDate: '2026-05-01');

        // Only the in-window unfiled row (50). 100/99/49 filed; 10 past cutoff.
        $this->assertCount(1, $rows);
        $this->assertSame(50, $rows[0]['id']);
    }

    public function test_get_pending_invoices_uses_limitstart_limitnum_not_limit_offset(): void
    {
        // The real "stuck at 16 / 5-minute freeze" bug: GetInvoices paginates
        // via limitstart/limitnum — limit/offset are SILENTLY IGNORED, so the
        // API returns the same first page forever → infinite walk. Lock the
        // correct param names + that the cursor advances by the actual count.
        Http::fakeSequence('example.gr/*')
            ->push(['result' => 'success', 'invoices' => ['invoice' => [
                ['id' => 20, 'date' => '2026-05-20', 'invoiced' => 0],
                ['id' => 19, 'date' => '2026-05-19', 'invoiced' => 0],
            ]]], 200)
            // Short page (1 < limitnum 2) → the natural last-page signal.
            ->push(['result' => 'success', 'invoices' => ['invoice' => [
                ['id' => 18, 'date' => '2026-05-18', 'invoiced' => 0],
            ]]], 200);

        $rows = $this->makeClient()->getPendingInvoices(limit: 2);

        $this->assertSame([20, 19, 18], array_column($rows, 'id'));

        $starts = [];
        Http::assertSentInOrder([
            function ($req) use (&$starts) {
                $starts[] = (int) ($req->data()['limitstart'] ?? -1);
                // limitnum carries the page size; limit/offset are NOT sent.
                return (int) ($req->data()['limitnum'] ?? 0) === 2
                    && ! array_key_exists('offset', $req->data());
            },
            function ($req) use (&$starts) {
                $starts[] = (int) ($req->data()['limitstart'] ?? -1);

                return true;
            },
        ]);
        // 2nd page starts at 2 (the actual count returned), not at limit.
        $this->assertSame([0, 2], $starts);
    }

    public function test_get_pending_invoices_loop_guard_stops_a_nonpaginating_server(): void
    {
        // Defence in depth: if a server ignores pagination and keeps returning
        // the SAME page (the failure mode that hung prod), the id-repeat guard
        // must stop after the second identical page instead of looping to
        // maxPages. Http::fake (no sequence) repeats the same response.
        Http::fake([
            'example.gr/*' => Http::response(['result' => 'success', 'invoices' => ['invoice' => [
                ['id' => 5, 'date' => '2026-05-15', 'invoiced' => 0],
                ['id' => 4, 'date' => '2026-05-14', 'invoiced' => 0],
            ]]], 200),
        ]);

        $rows = $this->makeClient()->getPendingInvoices(limit: 2);

        // Page 1 keeps both; page 2 repeats id 5 → guard breaks. No hang, no
        // duplicates beyond the first page.
        $this->assertSame([5, 4], array_column($rows, 'id'));
    }

    public function test_get_pending_invoices_no_min_date_pulls_everything(): void
    {
        // Null minDate (default) = no cutoff. All invoiced=0 rows returned
        // regardless of age. Page 1 has them, page 2 empty → stop.
        Http::fakeSequence('example.gr/*')
            ->push(['result' => 'success', 'invoices' => ['invoice' => [
                ['id' => 1, 'date' => '2026-05-15', 'invoiced' => 0],
                ['id' => 2, 'date' => '2007-12-10', 'invoiced' => 0],
            ]]], 200)
            ->push(['result' => 'success', 'invoices' => ['invoice' => []]], 200);

        $rows = $this->makeClient()->getPendingInvoices();

        $this->assertCount(2, $rows);
    }

    public function test_get_invoices_for_client_paginates_with_limitstart_limitnum(): void
    {
        // WH-6: GetInvoices ignores `limit` and applies a ~25 default page size,
        // so a client with more than a page only surfaced the newest ~25. Lock
        // the paginating walk: userid filter, limitstart/limitnum (not limit),
        // cursor advances by the ACTUAL count, all statuses returned.
        Http::fakeSequence('example.gr/*')
            ->push(['result' => 'success', 'invoices' => ['invoice' => [
                ['id' => 30, 'date' => '2026-05-30', 'status' => 'Paid'],
                ['id' => 29, 'date' => '2026-05-29', 'status' => 'Unpaid'],
            ]]], 200)
            ->push(['result' => 'success', 'invoices' => ['invoice' => [
                ['id' => 28, 'date' => '2026-05-28', 'status' => 'Cancelled'],
            ]]], 200)
            // Empty page → the end signal (we do NOT short-circuit on a
            // partial page, so a limit above WHMCS's ceiling can't truncate).
            ->push(['result' => 'success', 'invoices' => ['invoice' => []]], 200);

        $rows = $this->makeClient()->getInvoicesForClient(whmcsUserId: 77, limit: 2);

        // All three come through regardless of status — the panel wants the full picture.
        $this->assertSame([30, 29, 28], array_column($rows, 'id'));

        // Cursor advances by the ACTUAL returned count: 0 → 2 → 3, and userid +
        // limitnum (not limit/offset) go out on the first request.
        $starts = [];
        Http::assertSentInOrder([
            function ($req) use (&$starts) {
                $starts[] = (int) ($req->data()['limitstart'] ?? -1);

                return (int) ($req->data()['userid'] ?? 0) === 77
                    && (int) ($req->data()['limitnum'] ?? 0) === 2
                    && ! array_key_exists('limit', $req->data())
                    && ! array_key_exists('offset', $req->data());
            },
            function ($req) use (&$starts) {
                $starts[] = (int) ($req->data()['limitstart'] ?? -1);

                return true;
            },
            function ($req) use (&$starts) {
                $starts[] = (int) ($req->data()['limitstart'] ?? -1);

                return true;
            },
        ]);
        $this->assertSame([0, 2, 3], $starts);
    }

    public function test_get_invoices_for_client_stops_at_min_date(): void
    {
        // DESC ordering → once a row predates minDate, everything after is older.
        Http::fakeSequence('example.gr/*')
            ->push(['result' => 'success', 'invoices' => ['invoice' => [
                ['id' => 40, 'date' => '2026-05-10', 'status' => 'Paid'],
                ['id' => 39, 'date' => '2019-01-01', 'status' => 'Paid'], // before cutoff
                ['id' => 38, 'date' => '2018-01-01', 'status' => 'Paid'],
            ]]], 200);

        $rows = $this->makeClient()->getInvoicesForClient(whmcsUserId: 77, minDate: '2026-01-01');

        $this->assertSame([40], array_column($rows, 'id'));
    }

    public function test_get_invoices_for_client_loop_guard_stops_a_nonpaginating_server(): void
    {
        // A server that ignores pagination returns the same page forever; the
        // id-repeat guard must stop after the second identical page.
        Http::fake([
            'example.gr/*' => Http::response(['result' => 'success', 'invoices' => ['invoice' => [
                ['id' => 9, 'date' => '2026-05-09', 'status' => 'Paid'],
                ['id' => 8, 'date' => '2026-05-08', 'status' => 'Paid'],
            ]]], 200),
        ]);

        $rows = $this->makeClient()->getInvoicesForClient(whmcsUserId: 77, limit: 2);

        $this->assertSame([9, 8], array_column($rows, 'id'));
    }

    public function test_get_client_returns_null_on_not_found(): void
    {
        Http::fake([
            'example.gr/*' => Http::response([
                'result'  => 'error',
                'message' => 'Client ID Not Found',
            ], 200),
        ]);

        $this->assertNull($this->makeClient()->getClient(9999));
    }

    public function test_get_client_returns_payload_on_success(): void
    {
        Http::fake([
            'example.gr/*' => Http::response([
                'result'      => 'success',
                'id'          => 42,
                'firstname'   => 'Jane',
                'lastname'    => 'Doe',
                'email'       => 'jane@example.com',
                'companyname' => 'Acme',
            ], 200),
        ]);

        $client = $this->makeClient()->getClient(42);
        $this->assertSame(42, $client['id']);
        $this->assertSame('jane@example.com', $client['email']);
    }

    public function test_search_clients_returns_normalised_list(): void
    {
        Http::fake([
            'example.gr/*' => Http::response([
                'result'       => 'success',
                'totalresults' => 2,
                'clients'      => ['client' => [
                    ['id' => 10, 'firstname' => 'A', 'email' => 'a@x.com'],
                    ['id' => 11, 'firstname' => 'B', 'email' => 'b@x.com'],
                ]],
            ], 200),
        ]);

        $rows = $this->makeClient()->searchClients('foo');
        $this->assertCount(2, $rows);
        $this->assertSame([10, 11], array_column($rows, 'id'));
    }
}
