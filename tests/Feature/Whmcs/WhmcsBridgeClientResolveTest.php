<?php

namespace Tests\Feature\Whmcs;

use App\Exceptions\Whmcs\WhmcsApiException;
use App\Exceptions\Whmcs\WhmcsUnreachable;
use App\Models\Company;
use App\Services\Whmcs\WhmcsBridgeClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T-1a: the ekdosi → bridge resolve.php client (resolveThirdParty / listResellers).
 * HMAC + sibling-URL derivation + error mapping, all with a faked transport.
 */
class WhmcsBridgeClientResolveTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk';

    private const RESOLVE_URL = 'https://whmcs.example.com/modules/addons/ekdosi_bridge/resolve.php';

    private function tenant(string $apiUrl = 'https://whmcs.example.com/includes/api.php'): Company
    {
        return Company::create([
            'name' => 'T',
            'slug' => 'tp-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
            'whmcs_api_url' => $apiUrl,
            'whmcs_webhook_secret' => self::SECRET,
        ]);
    }

    private function client(Company $tenant)
    {
        return app(WhmcsBridgeClientFactory::class)->for($tenant);
    }

    public function test_resolve_hits_the_resolve_sibling_url_with_signed_body(): void
    {
        Http::fake([self::RESOLVE_URL => Http::response([
            'status' => 'ok',
            'whmcs_invoice_id' => 1234,
            'userid' => 793,
            'timologia_present' => true,
            'lines' => [[
                'item_id' => 7, 'relid' => 1637, 'type' => 'Domain', 'service_type' => 'domain',
                'description' => 'example.gr', 'routed' => true, 'is_receipt' => false,
                'contact' => ['id' => 5, 'company_name' => 'Haris', 'gr_vatno' => '081951154'],
            ]],
            'summary' => ['distinct_parties' => 1, 'multi_party' => false],
        ], 200)]);

        $res = $this->client($this->tenant())->resolveThirdParty(1234);

        $this->assertSame(1234, $res->whmcsInvoiceId);
        $this->assertSame(793, $res->whmcsUserId);
        $this->assertTrue($res->timologiaPresent);
        $this->assertNotNull($res->singleContact());
        $this->assertSame('Haris', $res->singleContact()['company_name']);

        Http::assertSent(function (Request $request) {
            $body = $request->body();
            $expectedSig = 'sha256='.hash_hmac('sha256', $body, self::SECRET);

            return $request->url() === self::RESOLVE_URL
                && $request->method() === 'POST'
                && $request->hasHeader('X-Webhook-Signature', $expectedSig)
                && json_decode($body, true) === ['op' => 'resolve', 'invoice_id' => 1234];
        });
    }

    public function test_resolve_url_derivation_survives_trailing_slash_on_api_url(): void
    {
        // A4-style regression (cf. write-back test): a stray trailing slash on
        // whmcs_api_url must still derive the canonical resolve.php URL.
        Http::fake([self::RESOLVE_URL => Http::response([
            'status' => 'ok', 'whmcs_invoice_id' => 1, 'userid' => 1,
            'timologia_present' => true, 'lines' => [],
        ], 200)]);

        $this->client($this->tenant('https://whmcs.example.com/includes/api.php/'))
            ->resolveThirdParty(1);

        Http::assertSent(fn (Request $r) => $r->url() === self::RESOLVE_URL);
    }

    public function test_list_resellers_parses_and_filters_junk_rows(): void
    {
        Http::fake([self::RESOLVE_URL => Http::response([
            'status' => 'ok',
            'resellers' => [
                ['userid' => 793, 'routes' => 4],
                ['userid' => 0, 'routes' => 9],   // junk: dropped
                'not-an-array',                    // junk: dropped
                ['userid' => 935, 'routes' => 1],
            ],
        ], 200)]);

        $resellers = $this->client($this->tenant())->listResellers();

        $this->assertSame(
            [['userid' => 793, 'routes' => 4], ['userid' => 935, 'routes' => 1]],
            $resellers,
        );

        Http::assertSent(fn (Request $r) => json_decode($r->body(), true) === ['op' => 'resellers']);
    }

    public function test_set_invoiced_includes_invcode_when_present(): void
    {
        Http::fake(['*inbound.php' => Http::response(['status' => 'ok'], 200)]);

        $this->client($this->tenant())->setInvoiced(8888, '400001234567890', 'ΑΠΥ423');

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'inbound.php')) {
                return false;
            }
            $body = json_decode($request->body(), true);

            return ($body['whmcs_invoice_id'] ?? null) === 8888
                && ($body['mark'] ?? null) === '400001234567890'
                && ($body['invcode'] ?? null) === 'ΑΠΥ423';
        });
    }

    public function test_set_invoiced_omits_invcode_when_null(): void
    {
        Http::fake(['*inbound.php' => Http::response(['status' => 'ok'], 200)]);

        $this->client($this->tenant())->setInvoiced(8888, '400001234567890');

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'inbound.php')) {
                return false;
            }

            return ! array_key_exists('invcode', json_decode($request->body(), true));
        });
    }

    public function test_get_legacy_invoice_links_parses_and_filters_junk(): void
    {
        Http::fake([self::RESOLVE_URL => Http::response([
            'status' => 'ok',
            'links' => [
                ['whmcs_id' => 31618, 'invoiced' => 7677],
                ['whmcs_id' => 0, 'invoiced' => 7680],   // junk: dropped
                ['whmcs_id' => 31619, 'invoiced' => 0],   // junk (invoiced<=0): dropped
                'not-an-array',                            // junk: dropped
                ['whmcs_id' => 31620, 'invoiced' => 7685],
            ],
        ], 200)]);

        $links = $this->client($this->tenant())->getLegacyInvoiceLinks(0, 500);

        $this->assertSame(
            [['whmcs_id' => 31618, 'invoiced' => 7677], ['whmcs_id' => 31620, 'invoiced' => 7685]],
            $links,
        );

        Http::assertSent(fn (Request $r) => json_decode($r->body(), true) === ['op' => 'legacy_invoice_links', 'offset' => 0, 'limit' => 500]);
    }

    public function test_fetch_invoice_signs_body_and_returns_payload(): void
    {
        Http::fake([self::RESOLVE_URL => Http::response([
            'status' => 'ok',
            'invoice' => ['invoiceid' => 555, 'id' => 555, 'userid' => 77, 'total' => '25.00'],
        ], 200)]);

        $payload = $this->client($this->tenant())->fetchInvoice(555);

        $this->assertIsArray($payload);
        $this->assertSame(555, $payload['invoiceid']);

        Http::assertSent(function (Request $request) {
            $body = $request->body();
            $expectedSig = 'sha256='.hash_hmac('sha256', $body, self::SECRET);

            return $request->url() === self::RESOLVE_URL
                && $request->method() === 'POST'
                && $request->hasHeader('X-Webhook-Signature', $expectedSig)
                && json_decode($body, true) === ['op' => 'invoice', 'invoice_id' => 555];
        });
    }

    public function test_fetch_invoice_returns_null_when_bridge_reports_no_invoice(): void
    {
        // `invoice: null` (unknown id) must map to null — NOT throw. A genuine
        // transport/auth failure still throws (covered by the resolve tests,
        // same postResolve path).
        Http::fake([self::RESOLVE_URL => Http::response(['status' => 'ok', 'invoice' => null], 200)]);

        $this->assertNull($this->client($this->tenant())->fetchInvoice(999999));
    }

    public function test_fetch_invoice_adds_with_routing_flag_when_requested(): void
    {
        Http::fake([self::RESOLVE_URL => Http::response([
            'status' => 'ok', 'invoice' => ['id' => 1, 'invoiceid' => 1],
        ], 200)]);

        $this->client($this->tenant())->fetchInvoice(1, true);

        Http::assertSent(fn (Request $r) => json_decode($r->body(), true)
            === ['op' => 'invoice', 'invoice_id' => 1, 'with_routing' => true]);
    }

    public function test_list_custom_fields_signs_body_and_maps_response(): void
    {
        Http::fake([self::RESOLVE_URL => Http::response([
            'status' => 'ok',
            'fields' => [
                ['id' => 13, 'fieldname' => 'ΑΦΜ / Vies Vat No', 'adminonly' => false],
                ['id' => 338, 'fieldname' => 'Γκρινιάρης', 'adminonly' => true],
                ['id' => 0, 'fieldname' => 'junk', 'adminonly' => false], // dropped (id<=0)
                'not-an-array',                                            // dropped
            ],
        ], 200)]);

        $fields = $this->client($this->tenant())->listCustomFields();

        $this->assertSame([
            ['id' => 13, 'fieldname' => 'ΑΦΜ / Vies Vat No', 'adminonly' => false],
            ['id' => 338, 'fieldname' => 'Γκρινιάρης', 'adminonly' => true],
        ], $fields);

        Http::assertSent(function (Request $request) {
            $body = $request->body();
            $expectedSig = 'sha256='.hash_hmac('sha256', $body, self::SECRET);

            return $request->url() === self::RESOLVE_URL
                && $request->hasHeader('X-Webhook-Signature', $expectedSig)
                && json_decode($body, true) === ['op' => 'custom_fields'];
        });
    }

    public function test_non_2xx_becomes_api_exception_with_error_field(): void
    {
        Http::fake([self::RESOLVE_URL => Http::response(['error' => 'invoice_not_found'], 404)]);

        $this->expectException(WhmcsApiException::class);
        $this->expectExceptionMessage('invoice_not_found');

        $this->client($this->tenant())->resolveThirdParty(999999);
    }

    public function test_connection_failure_becomes_unreachable(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->expectException(WhmcsUnreachable::class);

        $this->client($this->tenant())->resolveThirdParty(1);
    }
}
