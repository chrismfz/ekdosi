<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use App\Models\Customer;
use App\Models\PendingWhmcsInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * End-to-end exercise of the `whmcs:fetch-pending` artisan command.
 *
 * Two modes:
 *   - Default: fetches GetInvoices list, then GetInvoice per row,
 *              stages each into pending_whmcs_invoices via the
 *              ingestor. Idempotent across re-runs.
 *   - --preview: Stage A behaviour - GetInvoices only, print table,
 *                no DB writes.
 *
 * Both share the same exit-code surface for tenant/auth/config errors.
 */
class WhmcsFetchPendingCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeConfiguredTenant(): Company
    {
        return Company::create([
            'name' => 'TenantW',
            'slug' => 'whmcs-cmd-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
            'whmcs_api_url' => 'https://example.gr/includes/api.php',
            'whmcs_api_identifier' => 'X',
            'whmcs_api_secret' => 'Y',
        ]);
    }

    public function test_exits_invalid_when_no_tenant_option_passed(): void
    {
        $this->artisan('whmcs:fetch-pending')
            ->expectsOutputToContain('--tenant=SLUG is required')
            ->assertExitCode(\Symfony\Component\Console\Command\Command::INVALID);
    }

    public function test_exits_6_when_tenant_slug_unknown(): void
    {
        $this->artisan('whmcs:fetch-pending', ['--tenant' => 'does-not-exist'])
            ->expectsOutputToContain("No tenant with slug='does-not-exist'")
            ->assertExitCode(6);
    }

    public function test_exits_3_when_tenant_has_no_whmcs_configured(): void
    {
        $tenant = Company::create([
            'name' => 'NoWhmcs',
            'slug' => 'nw-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
        ]);

        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])
            ->expectsOutputToContain('no WHMCS integration configured')
            ->assertExitCode(3);
    }

    public function test_exits_4_on_whmcs_auth_failure(): void
    {
        $tenant = $this->makeConfiguredTenant();
        Http::fake([
            'example.gr/*' => Http::response([
                'result' => 'error',
                'message' => 'Invalid IP',
            ], 200),
        ]);

        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])
            ->expectsOutputToContain('WHMCS authentication failed')
            ->assertExitCode(4);
    }

    // ===================== --preview path =====================

    public function test_preview_prints_table_with_matched_and_unmatched_rows(): void
    {
        $tenant = $this->makeConfiguredTenant();
        Customer::create([
            'company_id' => $tenant->id,
            'name' => 'Linked A',
            'whmcs_client_id' => 101,
        ]);
        Customer::create([
            'company_id' => $tenant->id,
            'name' => 'Email B',
            'email' => 'b@x.com',
        ]);

        Http::fake([
            'example.gr/*' => Http::response([
                'result'       => 'success',
                'totalresults' => 3,
                'invoices'     => ['invoice' => [
                    ['id' => 5001, 'userid' => 101, 'date' => '2026-05-10', 'total' => 100, 'currencycode' => 'EUR', 'invoiced' => 0, 'companyname' => 'Acme A'],
                    ['id' => 5002, 'userid' => 202, 'date' => '2026-05-11', 'total' => 200, 'currencycode' => 'EUR', 'invoiced' => 0, 'email' => 'b@x.com'],
                    ['id' => 5003, 'userid' => 303, 'date' => '2026-05-12', 'total' => 300, 'currencycode' => 'EUR', 'invoiced' => 0, 'email' => 'stranger@nope.com'],
                ]],
            ], 200),
        ]);

        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug, '--preview' => true])
            ->expectsOutputToContain('Found 3 paid+unfiled invoice(s)')
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('linked')
            ->expectsOutputToContain('email match')
            ->expectsOutputToContain('no candidate')
            ->expectsOutputToContain('Summary: 2 matched, 1 unmatched')
            ->expectsOutputToContain('Unmatched rows need a customer link')
            ->assertExitCode(0);
    }

    public function test_preview_does_not_stage_anything(): void
    {
        $tenant = $this->makeConfiguredTenant();
        Customer::create([
            'company_id' => $tenant->id,
            'name' => 'C',
            'whmcs_client_id' => 101,
        ]);

        Http::fake([
            'example.gr/*' => Http::response([
                'result' => 'success',
                'invoices' => ['invoice' => [
                    ['id' => 5001, 'userid' => 101, 'date' => '2026-05-10', 'total' => 100, 'invoiced' => 0],
                ]],
            ], 200),
        ]);

        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug, '--preview' => true])
            ->assertExitCode(0);

        $this->assertSame(0, PendingWhmcsInvoice::count());
        // Preview makes ONE WHMCS call (GetInvoices), no per-row
        // GetInvoice calls (those happen in the ingest path).
        Http::assertSentCount(1);
    }

    public function test_prints_no_pending_message_on_empty_result(): void
    {
        $tenant = $this->makeConfiguredTenant();
        Http::fake([
            'example.gr/*' => Http::response([
                'result' => 'success',
                'totalresults' => 0,
            ], 200),
        ]);

        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])
            ->expectsOutputToContain('No paid+unfiled invoices pending')
            ->assertExitCode(0);
    }

    // ===================== default (ingest) path =====================

    public function test_default_stages_each_invoice_via_ingestor(): void
    {
        $tenant = $this->makeConfiguredTenant();
        Customer::create([
            'company_id' => $tenant->id,
            'name' => 'Linked',
            'whmcs_client_id' => 101,
        ]);

        // First call: GetInvoices list. Subsequent calls: GetInvoice
        // per row. Http::fakeSequence isn't ideal here because the
        // action parameter distinguishes them - we use a single
        // closure that branches on body content.
        Http::fake(function ($request) {
            $action = $request->data()['action'] ?? null;
            return match ($action) {
                'GetInvoices' => Http::response([
                    'result' => 'success',
                    'invoices' => ['invoice' => [
                        ['id' => 5001, 'userid' => 101, 'invoiced' => 0],
                        ['id' => 5002, 'userid' => 202, 'invoiced' => 0],
                    ]],
                ], 200),
                'GetInvoice' => Http::response([
                    'result'    => 'success',
                    'invoiceid' => (int) ($request->data()['invoiceid'] ?? 0),
                    'userid'    => $request->data()['invoiceid'] == 5001 ? 101 : 202,
                    'total'     => '100.00',
                ], 200),
                default => Http::response(['result' => 'error', 'message' => 'unexpected action'], 500),
            };
        });

        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])
            ->expectsOutputToContain('Staging...')
            ->expectsOutputToContain('staged  WHMCS#5001')
            ->expectsOutputToContain('staged  WHMCS#5002')
            ->expectsOutputToContain('Summary: 2 created, 0 refreshed, 0 audit-frozen, 0 failed')
            ->assertExitCode(0);

        $this->assertSame(2, PendingWhmcsInvoice::where('company_id', $tenant->id)->count());

        $linked = PendingWhmcsInvoice::where('whmcs_invoice_id', 5001)->first();
        $this->assertSame(PendingWhmcsInvoice::REASON_LINKED, $linked->match_reason);
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $linked->status);

        $unmatched = PendingWhmcsInvoice::where('whmcs_invoice_id', 5002)->first();
        $this->assertSame(PendingWhmcsInvoice::REASON_UNMATCHED, $unmatched->match_reason);
        $this->assertNull($unmatched->customer_id);
    }

    public function test_default_is_idempotent_across_reruns(): void
    {
        $tenant = $this->makeConfiguredTenant();

        Http::fake(function ($request) {
            $action = $request->data()['action'] ?? null;
            return match ($action) {
                'GetInvoices' => Http::response([
                    'result' => 'success',
                    'invoices' => ['invoice' => [
                        ['id' => 9001, 'userid' => 999, 'invoiced' => 0],
                    ]],
                ], 200),
                'GetInvoice' => Http::response([
                    'result' => 'success', 'invoiceid' => 9001, 'userid' => 999, 'total' => '50.00',
                ], 200),
                default => Http::response(['result' => 'error'], 500),
            };
        });

        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])->assertExitCode(0);
        $this->assertSame(1, PendingWhmcsInvoice::count());

        // Second run: same WHMCS id. Expect "refresh" line, count unchanged.
        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])
            ->expectsOutputToContain('refresh WHMCS#9001')
            ->expectsOutputToContain('Summary: 0 created, 1 refreshed')
            ->assertExitCode(0);

        $this->assertSame(1, PendingWhmcsInvoice::count());
    }

    public function test_default_returns_7_on_partial_failure(): void
    {
        $tenant = $this->makeConfiguredTenant();

        Http::fake(function ($request) {
            $action = $request->data()['action'] ?? null;
            if ($action === 'GetInvoices') {
                return Http::response([
                    'result' => 'success',
                    'invoices' => ['invoice' => [
                        ['id' => 7001, 'userid' => 101, 'invoiced' => 0],
                        ['id' => 7002, 'userid' => 202, 'invoiced' => 0],
                    ]],
                ], 200);
            }
            if ($action === 'GetInvoice') {
                // Second invoice gets a "not found" - simulating
                // race where invoice was deleted between list + detail.
                $id = (int) ($request->data()['invoiceid'] ?? 0);
                if ($id === 7001) {
                    return Http::response(['result' => 'success', 'invoiceid' => 7001, 'userid' => 101, 'total' => '10.00'], 200);
                }
                return Http::response(['result' => 'error', 'message' => 'Invoice ID Not Found'], 200);
            }
            return Http::response(['result' => 'error'], 500);
        });

        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])
            ->expectsOutputToContain('staged  WHMCS#7001')
            ->expectsOutputToContain('not found on GetInvoice')
            ->expectsOutputToContain('Summary: 1 created, 0 refreshed, 0 audit-frozen, 1 failed')
            ->assertExitCode(7);

        $this->assertSame(1, PendingWhmcsInvoice::count());
    }
}
