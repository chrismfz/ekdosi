<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * End-to-end exercise of the `whmcs:pull-pending-invoices` artisan
 * command using Http::fake() for the WHMCS API. Locks the dry-run
 * contract:
 *   - Reads paid+unfiled invoices
 *   - Matches each against ekdosi customers
 *   - Prints a table; never modifies state
 *   - Exits with the right code for each failure class
 */
class WhmcsPullPendingInvoicesCommandTest extends TestCase
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
        $this->artisan('whmcs:pull-pending-invoices')
            ->expectsOutputToContain('--tenant=SLUG is required')
            ->assertExitCode(\Symfony\Component\Console\Command\Command::INVALID);
    }

    public function test_exits_6_when_tenant_slug_unknown(): void
    {
        // 6 (not 2) so cron wrappers can distinguish "command misuse"
        // (Command::INVALID = 2) from "tenant doesn't exist" (6 —
        // typically a data issue, possibly tenant deleted).
        $this->artisan('whmcs:pull-pending-invoices', ['--tenant' => 'does-not-exist'])
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

        $this->artisan('whmcs:pull-pending-invoices', ['--tenant' => $tenant->slug])
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

        $this->artisan('whmcs:pull-pending-invoices', ['--tenant' => $tenant->slug])
            ->expectsOutputToContain('WHMCS authentication failed')
            ->assertExitCode(4);
    }

    public function test_prints_table_with_matched_and_unmatched_rows(): void
    {
        $tenant = $this->makeConfiguredTenant();
        // Customer A is linked by id; B will match by email; C is
        // a stranger (won't match).
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

        $this->artisan('whmcs:pull-pending-invoices', ['--tenant' => $tenant->slug])
            ->expectsOutputToContain('Found 3 paid+unfiled invoice(s)')
            ->expectsOutputToContain('DRY RUN')
            // Row contents — match reasons surface in the table
            ->expectsOutputToContain('linked')
            ->expectsOutputToContain('email match')
            ->expectsOutputToContain('no candidate')
            ->expectsOutputToContain('Summary: 2 matched, 1 unmatched')
            ->expectsOutputToContain('Unmatched rows need a customer link')
            ->assertExitCode(0);
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

        $this->artisan('whmcs:pull-pending-invoices', ['--tenant' => $tenant->slug])
            ->expectsOutputToContain('No paid+unfiled invoices pending')
            ->assertExitCode(0);
    }

    public function test_does_not_modify_any_state(): void
    {
        $tenant = $this->makeConfiguredTenant();
        $customer = Customer::create([
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

        $this->artisan('whmcs:pull-pending-invoices', ['--tenant' => $tenant->slug])
            ->assertExitCode(0);

        // No invoice should have been created; the WHMCS-side row
        // should not have been written back (we don't make that call
        // in Stage A); customer unchanged.
        $this->assertSame(0, \App\Models\Invoice::count());
        $this->assertSame(101, $customer->fresh()->whmcs_client_id);
        // Stage A makes ONE WHMCS call (GetInvoices), no others.
        Http::assertSentCount(1);
    }
}
