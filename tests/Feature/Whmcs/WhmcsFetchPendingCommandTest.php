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

    /**
     * Wrap a GetInvoices list page so the paginating fetcher terminates:
     * return the rows on the FIRST page (offset 0) and an EMPTY page on any
     * subsequent offset. getPendingInvoices now stops only on an empty page
     * (WHMCS caps page size, so count<limit is not the end), so a repeating
     * fake would otherwise loop. Use inside an Http::fake closure for the
     * 'GetInvoices' branch.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function invoicesPage(\Illuminate\Http\Client\Request $request, array $rows)
    {
        $offset = (int) ($request->data()['offset'] ?? 0);
        $invoice = $offset > 0 ? [] : $rows;

        return Http::response([
            'result' => 'success',
            'totalresults' => count($rows),
            'invoices' => ['invoice' => $invoice],
        ], 200);
    }

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

        Http::fake(fn ($request) => $this->invoicesPage($request, [
            ['id' => 5001, 'userid' => 101, 'date' => '2026-05-10', 'total' => 100, 'currencycode' => 'EUR', 'invoiced' => 0, 'companyname' => 'Acme A'],
            ['id' => 5002, 'userid' => 202, 'date' => '2026-05-11', 'total' => 200, 'currencycode' => 'EUR', 'invoiced' => 0, 'email' => 'b@x.com'],
            ['id' => 5003, 'userid' => 303, 'date' => '2026-05-12', 'total' => 300, 'currencycode' => 'EUR', 'invoiced' => 0, 'email' => 'stranger@nope.com'],
        ]));

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

        Http::fake(fn ($request) => $this->invoicesPage($request, [
            ['id' => 5001, 'userid' => 101, 'date' => '2026-05-10', 'total' => 100, 'invoiced' => 0],
        ]));

        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug, '--preview' => true])
            ->assertExitCode(0);

        $this->assertSame(0, PendingWhmcsInvoice::count());
        // Preview makes only GetInvoices LIST calls (now paginated: page 1 +
        // an empty page-2 terminator), and NO per-row GetInvoice calls — those
        // happen only in the ingest path.
        Http::assertNotSent(fn ($request) => ($request->data()['action'] ?? null) === 'GetInvoice');
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
                'GetInvoices' => $this->invoicesPage($request, [
                    ['id' => 5001, 'userid' => 101, 'invoiced' => 0],
                    ['id' => 5002, 'userid' => 202, 'invoiced' => 0],
                ]),
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
                'GetInvoices' => $this->invoicesPage($request, [
                    ['id' => 9001, 'userid' => 999, 'invoiced' => 0],
                ]),
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
                return $this->invoicesPage($request, [
                    ['id' => 7001, 'userid' => 101, 'invoiced' => 0],
                    ['id' => 7002, 'userid' => 202, 'invoiced' => 0],
                ]);
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
            // GetClientsDetails calls from getInvoiceWithClient
            // enrichment: tolerate gracefully.
            if ($action === 'GetClientsDetails') {
                return Http::response(['result' => 'success', 'id' => (int) ($request->data()['clientid'] ?? 0)], 200);
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

    // ===================== Fix #1: tenant-fatal aborts batch =====================

    public function test_default_aborts_with_code_4_on_mid_loop_auth_failure(): void
    {
        // Tenant's IP allowlist rotates between GetInvoices (succeeds)
        // and the first GetInvoice (fails with "Invalid IP"). Every
        // remaining invoice will fail the same way. Old behaviour:
        // log per-row "failed", spin through N * 20s timeouts, exit 7.
        // New behaviour: abort immediately with exit 4 so cron
        // wrappers route to the auth-failure alert.
        $tenant = $this->makeConfiguredTenant();
        $getInvoiceCalls = 0;

        Http::fake(function ($request) use (&$getInvoiceCalls) {
            $action = $request->data()['action'] ?? null;
            if ($action === 'GetInvoices') {
                return $this->invoicesPage($request, [
                    ['id' => 5001, 'userid' => 101, 'invoiced' => 0],
                    ['id' => 5002, 'userid' => 202, 'invoiced' => 0],
                    ['id' => 5003, 'userid' => 303, 'invoiced' => 0],
                ]);
            }
            if ($action === 'GetInvoice') {
                $getInvoiceCalls++;
                return Http::response(['result' => 'error', 'message' => 'Invalid IP'], 200);
            }
            return Http::response(['result' => 'error'], 500);
        });

        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])
            ->expectsOutputToContain('Aborting batch: WHMCS authentication failed')
            ->assertExitCode(4);

        // Confirm we did NOT continue iterating after the first failure
        // (the bug was: catch (WhmcsApiException) for the parent class
        // also caught WhmcsAuthenticationFailed/WhmcsUnreachable, and
        // marked every remaining row as a per-row failure).
        $this->assertSame(1, $getInvoiceCalls, 'Should abort after first auth failure, not iterate all rows');
        $this->assertSame(0, PendingWhmcsInvoice::count());
    }

    public function test_default_aborts_with_code_5_on_mid_loop_unreachable(): void
    {
        $tenant = $this->makeConfiguredTenant();
        $getInvoiceCalls = 0;

        Http::fake(function ($request) use (&$getInvoiceCalls) {
            $action = $request->data()['action'] ?? null;
            if ($action === 'GetInvoices') {
                return $this->invoicesPage($request, [
                    ['id' => 5001, 'userid' => 101, 'invoiced' => 0],
                    ['id' => 5002, 'userid' => 202, 'invoiced' => 0],
                ]);
            }
            if ($action === 'GetInvoice') {
                $getInvoiceCalls++;
                // Simulate connection refused / DNS failure via
                // an HTTP-level transport exception. Laravel's
                // Http::fake exposes Http::failedConnection() for
                // this shape.
                throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
            }
            return Http::response(['result' => 'error'], 500);
        });

        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])
            ->expectsOutputToContain('Aborting batch: WHMCS unreachable')
            ->assertExitCode(5);

        $this->assertSame(1, $getInvoiceCalls, 'Should abort after first unreachable failure');
    }

    // ===================== Fix #2: getInvoiceWithClient enriches customfields =====================

    public function test_default_passes_customfields_through_so_afm_match_works(): void
    {
        // The point: an operator who has linked the WHMCS custom field
        // for AFM (fieldid=13 in CLAUDE.md's reference legacy install)
        // expects pending rows to resolve match_reason='afm'. Pre-fix,
        // the ingestor cherry-picked top-level keys and stripped
        // customfields, so AFM-match was dead. Fix routes through
        // WhmcsClient::getInvoiceWithClient which fetches
        // GetClientsDetails and merges customfields onto the invoice
        // payload before handing to the matcher.
        $tenant = $this->makeConfiguredTenant();
        $tenant->update(['whmcs_custom_field_map' => ['vatno' => 13]]);

        // ekdosi customer with known AFM, NOT linked by whmcs_client_id
        // (forces matcher to fall through to strategy #2 = AFM match).
        Customer::create([
            'company_id' => $tenant->id,
            'name' => 'AFM Matched',
            'afm' => '123456789',
        ]);

        Http::fake(function ($request) {
            $action = $request->data()['action'] ?? null;
            return match ($action) {
                'GetInvoices' => $this->invoicesPage($request, [
                    ['id' => 8001, 'userid' => 555, 'invoiced' => 0],
                ]),
                'GetInvoice' => Http::response([
                    'result' => 'success', 'invoiceid' => 8001, 'userid' => 555, 'total' => '50.00',
                ], 200),
                'GetClientsDetails' => Http::response([
                    'result' => 'success',
                    'id' => 555,
                    'customfields' => [
                        ['id' => 13, 'name' => 'AFM', 'value' => '123456789'],
                    ],
                ], 200),
                default => Http::response(['result' => 'error'], 500),
            };
        });

        $this->artisan('whmcs:fetch-pending', ['--tenant' => $tenant->slug])
            ->assertExitCode(0);

        $staged = PendingWhmcsInvoice::where('whmcs_invoice_id', 8001)->first();
        $this->assertNotNull($staged);
        $this->assertSame(PendingWhmcsInvoice::REASON_AFM, $staged->match_reason);
    }
}
