<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use App\Models\Customer;
use App\Models\PendingWhmcsInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * whmcs:fetch-unpaid — stage the UNPAID WHMCS invoices of «τιμολόγιο πριν την πληρωμή»
 * (needs_invoice_before_payment) customers into the inbox for MANUAL επί-πιστώσει
 * issuance. It only STAGES; the «never auto-issued» guarantee is proven separately by
 * WhmcsAutoIssueCommandTest::test_holds_an_unpaid_whmcs_row_instead_of_auto_issuing_it
 * (chooseType() holds every unpaid row, and auto-issue keys on the DIFFERENT
 * needs_immediate_invoice flag).
 */
class WhmcsFetchUnpaidCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeConfiguredTenant(): Company
    {
        return Company::create([
            'name' => 'TenantU',
            'slug' => 'whmcs-unpaid-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
            'whmcs_api_url' => 'https://example.gr/includes/api.php',
            'whmcs_api_identifier' => 'X',
            'whmcs_api_secret' => 'Y',
        ]);
    }

    /**
     * GetInvoices paginates via limitstart: rows on the first page, empty after, so
     * getInvoicesForClient() terminates. (It sends `userid`; the single flagged
     * customer in each test means no cross-client contamination.)
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function invoicesPage(Request $request, array $rows)
    {
        $start = (int) ($request->data()['limitstart'] ?? 0);

        return Http::response([
            'result' => 'success',
            'totalresults' => count($rows),
            'invoices' => ['invoice' => $start > 0 ? [] : $rows],
        ], 200);
    }

    /** Fake the whole GetInvoices → GetInvoice → GetClientsDetails flow for one client. */
    private function fakeClient(int $userid, array $listRows): void
    {
        Http::fake(function ($request) use ($userid, $listRows) {
            $data = $request->data();

            return match ($data['action'] ?? null) {
                'GetInvoices' => $this->invoicesPage($request, $listRows),
                'GetInvoice' => Http::response([
                    'result' => 'success',
                    'invoiceid' => (int) ($data['invoiceid'] ?? 0),
                    'userid' => $userid,
                    // Only unpaid ids reach here (the fetcher filters the list first);
                    // echo Unpaid so whmcsIsUnpaid() holds on the staged row.
                    'status' => 'Unpaid',
                    'date' => '2026-08-01',
                    'total' => '500.00',
                    'currencycode' => 'EUR',
                    'items' => ['item' => [['description' => 'Συνδρομή', 'amount' => '500.00', 'taxed' => '1']]],
                ], 200),
                'GetClientsDetails' => Http::response([
                    'result' => 'success', 'userid' => $userid, 'companyname' => 'Δήμος Παραδείγματος',
                ], 200),
                default => Http::response(['result' => 'error', 'message' => 'unexpected action'], 500),
            };
        });
    }

    public function test_stages_only_the_unpaid_invoices_of_flagged_customers(): void
    {
        $tenant = $this->makeConfiguredTenant();
        Customer::create([
            'company_id' => $tenant->id,
            'name' => 'Δήμος Παραδείγματος',
            'whmcs_client_id' => 101,
            'needs_invoice_before_payment' => true,
        ]);

        // The client's WHMCS invoices: one Unpaid (must stage), plus Paid + Cancelled
        // (must be ignored — this feature is only «τιμολόγιο πριν την πληρωμή»).
        $this->fakeClient(101, [
            ['id' => 6001, 'userid' => 101, 'date' => '2026-08-01', 'status' => 'Unpaid', 'total' => 500, 'currencycode' => 'EUR', 'invoiced' => 0],
            ['id' => 6002, 'userid' => 101, 'date' => '2026-08-02', 'status' => 'Paid', 'total' => 200, 'currencycode' => 'EUR', 'invoiced' => 0],
            ['id' => 6003, 'userid' => 101, 'date' => '2026-08-03', 'status' => 'Cancelled', 'total' => 100, 'currencycode' => 'EUR', 'invoiced' => 0],
        ]);

        $this->artisan('whmcs:fetch-unpaid', ['--tenant' => $tenant->slug])->assertExitCode(0);

        $rows = PendingWhmcsInvoice::all();
        $this->assertCount(1, $rows, 'only the Unpaid invoice is staged');

        $row = $rows->first();
        $this->assertSame(6001, $row->whmcs_invoice_id);
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $row->status, 'staged for the operator, not held/filed');
        $this->assertTrue($row->whmcsIsUnpaid(), 'the row carries the Unpaid status → «Απλήρωτο» badge + unpaid type');
        $this->assertNull($row->invoice_id, 'nothing is filed');

        // GetInvoice is called ONLY for the unpaid id — the paid/cancelled ones are
        // filtered before the per-row detail call.
        Http::assertSent(fn ($r) => ($r->data()['action'] ?? null) === 'GetInvoice' && (int) ($r->data()['invoiceid'] ?? 0) === 6001);
        Http::assertNotSent(fn ($r) => ($r->data()['action'] ?? null) === 'GetInvoice' && in_array((int) ($r->data()['invoiceid'] ?? 0), [6002, 6003], true));
    }

    public function test_is_idempotent_across_reruns(): void
    {
        $tenant = $this->makeConfiguredTenant();
        Customer::create([
            'company_id' => $tenant->id, 'name' => 'Δήμος', 'whmcs_client_id' => 101,
            'needs_invoice_before_payment' => true,
        ]);
        $this->fakeClient(101, [
            ['id' => 6001, 'userid' => 101, 'date' => '2026-08-01', 'status' => 'Unpaid', 'total' => 500, 'currencycode' => 'EUR', 'invoiced' => 0],
        ]);

        $this->artisan('whmcs:fetch-unpaid', ['--tenant' => $tenant->slug])->assertExitCode(0);
        $this->artisan('whmcs:fetch-unpaid', ['--tenant' => $tenant->slug])->assertExitCode(0);

        $this->assertSame(1, PendingWhmcsInvoice::count(), 'the (company, whmcs_invoice_id) key dedups across runs');
    }

    public function test_ignores_non_flagged_and_warns_about_unlinked_flagged_customers(): void
    {
        $tenant = $this->makeConfiguredTenant();
        // Flagged but NOT linked to WHMCS → cannot be fetched; surfaced as a warning.
        Customer::create([
            'company_id' => $tenant->id, 'name' => 'Αδέσμευτος', 'whmcs_client_id' => null,
            'needs_invoice_before_payment' => true,
        ]);
        // Linked but NOT flagged → not this feature's concern; never fetched.
        Customer::create([
            'company_id' => $tenant->id, 'name' => 'Απλός', 'whmcs_client_id' => 202,
            'needs_invoice_before_payment' => false,
        ]);

        Http::fake(fn ($request) => Http::response(['result' => 'error', 'message' => 'no client should be fetched'], 500));

        $this->artisan('whmcs:fetch-unpaid', ['--tenant' => $tenant->slug])
            ->expectsOutputToContain('ΔΕΝ έχουν σύνδεση WHMCS')
            ->assertExitCode(0);

        $this->assertSame(0, PendingWhmcsInvoice::count());
        Http::assertNothingSent();
    }

    public function test_exits_6_on_unknown_tenant(): void
    {
        $this->artisan('whmcs:fetch-unpaid', ['--tenant' => 'nope-'.uniqid()])
            ->assertExitCode(6);
    }

    public function test_isolates_a_failed_client_listing_and_stages_the_others(): void
    {
        // Review finding 2: one client's listing failing (e.g. a stale whmcs_client_id →
        // a generic WHMCS error) must NOT block the rest of the tenant's flagged customers.
        $tenant = $this->makeConfiguredTenant();
        Customer::create(['company_id' => $tenant->id, 'name' => 'Χαλασμένος', 'whmcs_client_id' => 101, 'needs_invoice_before_payment' => true]);
        Customer::create(['company_id' => $tenant->id, 'name' => 'Καλός', 'whmcs_client_id' => 202, 'needs_invoice_before_payment' => true]);

        Http::fake(function ($request) {
            $data = $request->data();
            $userid = (int) ($data['userid'] ?? 0);

            return match ($data['action'] ?? null) {
                // Client 101 → a NON-auth WHMCS error (isolated); client 202 → a real unpaid list.
                'GetInvoices' => $userid === 101
                    ? Http::response(['result' => 'error', 'message' => 'Client Not Found'], 200)
                    : $this->invoicesPage($request, [
                        ['id' => 6002, 'userid' => 202, 'date' => '2026-08-05', 'status' => 'Unpaid', 'total' => 300, 'currencycode' => 'EUR', 'invoiced' => 0],
                    ]),
                'GetInvoice' => Http::response(['result' => 'success', 'invoiceid' => (int) ($data['invoiceid'] ?? 0), 'userid' => 202, 'status' => 'Unpaid', 'total' => '300.00', 'currencycode' => 'EUR'], 200),
                'GetClientsDetails' => Http::response(['result' => 'success', 'userid' => 202, 'companyname' => 'ΑΕ'], 200),
                default => Http::response(['result' => 'error', 'message' => 'unexpected'], 500),
            };
        });

        // Exit 7 (partial): the bad client is counted as a failure, the good one still staged.
        $this->artisan('whmcs:fetch-unpaid', ['--tenant' => $tenant->slug])->assertExitCode(7);

        $rows = PendingWhmcsInvoice::all();
        $this->assertCount(1, $rows, 'the healthy client is still staged despite the bad one');
        $this->assertSame(6002, $rows->first()->whmcs_invoice_id);
    }

    public function test_propagates_a_transport_fatal_auth_failure(): void
    {
        // Review finding 1: an auth failure (tenant-fatal) must map to exit 4, not be
        // swallowed as a per-row/per-client 'partial'. Exercised on the per-invoice detail
        // call (getInvoiceWithClient) — the path the swallowing bug affected.
        $tenant = $this->makeConfiguredTenant();
        Customer::create(['company_id' => $tenant->id, 'name' => 'Δήμος', 'whmcs_client_id' => 101, 'needs_invoice_before_payment' => true]);

        Http::fake(function ($request) {
            $data = $request->data();

            return match ($data['action'] ?? null) {
                'GetInvoices' => $this->invoicesPage($request, [
                    ['id' => 6001, 'userid' => 101, 'date' => '2026-08-01', 'status' => 'Unpaid', 'total' => 500, 'currencycode' => 'EUR', 'invoiced' => 0],
                ]),
                // The detail call hits an auth wall (creds revoked mid-run).
                'GetInvoice' => Http::response(['result' => 'error', 'message' => 'Invalid IP'], 200),
                default => Http::response(['result' => 'error', 'message' => 'unexpected'], 500),
            };
        });

        $this->artisan('whmcs:fetch-unpaid', ['--tenant' => $tenant->slug])
            ->expectsOutputToContain('WHMCS authentication failed')
            ->assertExitCode(4);
    }
}
