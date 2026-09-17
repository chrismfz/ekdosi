<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use App\Models\Customer;
use App\Models\PendingWhmcsInvoice;
use App\Services\Whmcs\WhmcsBridgeClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Slice 1 of "the bridge is the inbox feed": fetch the invoice payloads from the
 * plugin (resolve.php op=invoices) instead of the native WHMCS API. The payloads
 * are shape-compatible, so the SAME ingestor stages them. The plugin's
 * InvoiceFeed is faked here (it runs inside WHMCS).
 */
class WhmcsFetchViaBridgeTest extends TestCase
{
    use RefreshDatabase;

    private const RESOLVE_URL = 'https://whmcs.example.com/modules/addons/ekdosi_bridge/resolve.php';

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'Bridge Co', 'slug' => 'bf-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'whmcs_api_url' => 'https://whmcs.example.com/includes/api.php',
            'whmcs_webhook_secret' => str_repeat('s', 40),
            'whmcs_third_party_enabled' => false,
        ]);
    }

    private function payload(int $id, int $userId, float $total): array
    {
        return [
            'invoiceid' => $id, 'id' => $id, 'userid' => $userId,
            'date' => '2026-04-01', 'total' => (string) $total, 'subtotal' => (string) $total,
            'tax' => '0', 'taxrate' => '0', 'currencycode' => 'EUR', 'status' => 'Paid', 'invoiced' => 0,
            'companyname' => 'ACME OE', 'firstname' => '', 'lastname' => '', 'email' => 'a@e.test',
            'customfields' => [], 'items' => ['item' => [['id' => 1, 'description' => 'X', 'amount' => (string) $total, 'taxed' => 0]]],
        ];
    }

    /** Fake resolve.php: op=invoices paginated. */
    private function fakeFeed(array $page0): void
    {
        Http::fake([self::RESOLVE_URL => function (Request $request) use ($page0) {
            $body = json_decode($request->body(), true);
            $op = $body['op'] ?? '';
            if ($op === 'invoices') {
                $offset = (int) ($body['offset'] ?? 0);
                $invoices = $offset === 0 ? $page0 : [];

                return Http::response(['status' => 'ok', 'invoices' => $invoices, 'offset' => $offset, 'count' => count($invoices)], 200);
            }

            return Http::response(['status' => 'ok'], 200);
        }]);
    }

    public function test_via_bridge_stages_invoices_from_the_plugin_feed(): void
    {
        $tenant = $this->tenant();
        // Linked reseller so one row matches by link, the other is unmatched.
        Customer::create(['company_id' => $tenant->id, 'name' => 'Reseller', 'afm' => '700700700', 'whmcs_client_id' => 793]);

        $this->fakeFeed([
            $this->payload(5001, 793, 124.00),
            $this->payload(5002, 999, 50.00),
        ]);

        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug, '--via-bridge' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('pending_whmcs_invoices', [
            'company_id' => $tenant->id, 'whmcs_invoice_id' => 5001, 'source' => 'whmcs',
        ]);
        $this->assertDatabaseHas('pending_whmcs_invoices', [
            'company_id' => $tenant->id, 'whmcs_invoice_id' => 5002,
        ]);
        $this->assertSame(2, PendingWhmcsInvoice::where('company_id', $tenant->id)->count());

        // The invoice fetch went to the bridge (resolve.php op=invoices), NOT the
        // native WHMCS API (includes/api.php).
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'resolve.php')
            && (json_decode($r->body(), true)['op'] ?? '') === 'invoices');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'includes/api.php'));
    }

    public function test_client_fetch_parses_and_filters_malformed_payloads(): void
    {
        $tenant = $this->tenant();
        Http::fake([self::RESOLVE_URL => Http::response([
            'status' => 'ok',
            'invoices' => [
                ['invoiceid' => 7001, 'userid' => 1],
                ['id' => 7002, 'userid' => 2],
                ['userid' => 3],          // junk: no invoice id → dropped
                'not-an-array',           // junk → dropped
            ],
            'offset' => 0, 'count' => 4,
        ], 200)]);

        $bridge = app(WhmcsBridgeClientFactory::class)->for($tenant);
        $out = $bridge->fetchPendingInvoices(0, 100, '2026-01-01');

        $this->assertCount(2, $out);
        $this->assertSame(7001, $out[0]['invoiceid']);
        $this->assertSame(7002, $out[1]['id']);

        Http::assertSent(fn (Request $r) => json_decode($r->body(), true) === [
            'op' => 'invoices', 'status' => 'paid_unfiled', 'offset' => 0, 'limit' => 100, 'since' => '2026-01-01',
        ]);
    }

    public function test_tenant_toggle_routes_to_bridge_without_the_flag(): void
    {
        $tenant = $this->tenant();
        $tenant->update(['whmcs_fetch_via_bridge' => true]);   // the per-tenant switch
        $this->fakeFeed([$this->payload(6001, 1, 10.0)]);

        // No --via-bridge flag: the toggle alone must pick the bridge path.
        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])
            ->assertSuccessful();

        $this->assertDatabaseHas('pending_whmcs_invoices', ['company_id' => $tenant->id, 'whmcs_invoice_id' => 6001]);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'resolve.php')
            && (json_decode($r->body(), true)['op'] ?? '') === 'invoices');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'includes/api.php'));
    }

    public function test_embedded_routing_is_used_with_no_separate_resolve_call(): void
    {
        $tenant = $this->tenant();
        $tenant->update(['whmcs_third_party_enabled' => true]);   // → with_routing requested

        // The feed embeds the routing block (here: no routed lines → TP_NONE).
        $payload = $this->payload(6101, 1, 10.0);
        $payload['third_party'] = ['whmcs_invoice_id' => 6101, 'userid' => 1, 'timologia_present' => true, 'lines' => []];
        $this->fakeFeed([$payload]);

        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug, '--via-bridge' => true])
            ->assertSuccessful();

        $row = PendingWhmcsInvoice::where('company_id', $tenant->id)->where('whmcs_invoice_id', 6101)->first();
        $this->assertNotNull($row);
        $this->assertSame(PendingWhmcsInvoice::TP_NONE, $row->third_party_state);
        // The body asked for routing…
        Http::assertSent(fn (Request $r) => (json_decode($r->body(), true)['op'] ?? '') === 'invoices'
            && (json_decode($r->body(), true)['with_routing'] ?? false) === true);
        // …and NO separate op=resolve call was made (routing came embedded).
        Http::assertNotSent(fn (Request $r) => (json_decode($r->body(), true)['op'] ?? '') === 'resolve');
        // The routing block is NOT persisted inside the stored payload.
        $this->assertArrayNotHasKey('third_party', $row->payload);
    }

    public function test_via_bridge_errors_when_bridge_unconfigured(): void
    {
        $t = Company::create([
            'name' => 'NoCfg', 'slug' => 'nc-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);

        $this->artisan('whmcs:fetch-pending', ['--tenant' => $t->slug, '--via-bridge' => true])
            ->assertExitCode(3);
    }
}
