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
        yield 'lowercase_invalid_username'   => ['invalid username or password'];
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
        Http::fake([
            'example.gr/*' => Http::response([
                'result'       => 'success',
                'totalresults' => 3,
                'invoices'     => ['invoice' => [
                    ['id' => 1001, 'status' => 'Paid', 'invoiced' => 0, 'total' => 10.00],
                    ['id' => 1002, 'status' => 'Paid', 'invoiced' => 1, 'total' => 20.00],
                    ['id' => 1003, 'status' => 'Paid', 'invoiced' => 0, 'total' => 30.00],
                    ['id' => 1004, 'status' => 'Paid', 'total' => 40.00],  // no invoiced field
                ]],
            ], 200),
        ]);

        $rows = $this->makeClient()->getPendingInvoices();

        $this->assertCount(3, $rows);
        $ids = array_column($rows, 'id');
        $this->assertSame([1001, 1003, 1004], $ids);
    }

    public function test_get_pending_invoices_normalises_single_row_object_shape(): void
    {
        // WHMCS returns count=1 as a single object, not a list.
        Http::fake([
            'example.gr/*' => Http::response([
                'result'       => 'success',
                'totalresults' => 1,
                'invoices'     => ['invoice' => ['id' => 1001, 'invoiced' => 0]],
            ], 200),
        ]);

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
