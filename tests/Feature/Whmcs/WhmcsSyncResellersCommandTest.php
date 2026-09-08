<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T-1b (#6): whmcs:sync-resellers mirrors routing counts onto customers.
 */
class WhmcsSyncResellersCommandTest extends TestCase
{
    use RefreshDatabase;

    private const RESOLVE_URL = 'https://whmcs.example.com/modules/addons/ekdosi_bridge/resolve.php';

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'T', 'slug' => 'sr-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'whmcs_api_url' => 'https://whmcs.example.com/includes/api.php',
            'whmcs_webhook_secret' => str_repeat('s', 40),
        ]);
    }

    public function test_unknown_tenant_exits_6(): void
    {
        $this->artisan('whmcs:sync-resellers', ['--tenant' => 'nope'])->assertExitCode(6);
    }

    public function test_sets_count_on_linked_customer_and_resets_stale(): void
    {
        $tenant = $this->tenant();
        $linked = Customer::create([
            'company_id' => $tenant->id, 'name' => 'Chris', 'afm' => '700700700',
            'whmcs_client_id' => 793, 'whmcs_reseller_routes' => 99, // stale value
        ]);
        $deRouted = Customer::create([
            'company_id' => $tenant->id, 'name' => 'Old reseller', 'afm' => '111111111',
            'whmcs_client_id' => 555, 'whmcs_reseller_routes' => 4, // no longer routes
        ]);

        Http::fake([self::RESOLVE_URL => Http::response([
            'status' => 'ok', 'resellers' => [['userid' => 793, 'routes' => 4]],
        ], 200)]);

        $this->artisan('whmcs:sync-resellers', ['--tenant' => $tenant->slug])
            ->assertExitCode(0);

        $this->assertSame(4, $linked->fresh()->whmcs_reseller_routes);
        $this->assertSame(0, $deRouted->fresh()->whmcs_reseller_routes, 'stale flag reset to 0');
    }

    public function test_reseller_without_linked_customer_is_reported_not_stored(): void
    {
        $tenant = $this->tenant();
        Http::fake([self::RESOLVE_URL => Http::response([
            'status' => 'ok', 'resellers' => [['userid' => 999, 'routes' => 2]],
        ], 200)]);

        $this->artisan('whmcs:sync-resellers', ['--tenant' => $tenant->slug])
            ->expectsOutputToContain('no linked ekdosi Customer')
            ->assertExitCode(0);
    }
}
