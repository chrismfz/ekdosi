<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Stage 3: whmcs:use-bridge flips a tenant between the Plugin-API and the native
 * WHMCS API — but only ENABLES after probing the deployed plugin for op=invoice,
 * so it can't flip into a broken push path (the deploy-ordering trap).
 */
class WhmcsUseBridgeCommandTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(bool $viaBridge = false): Company
    {
        return Company::create([
            'name' => 'UB',
            'slug' => 'ub-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
            'whmcs_api_url' => 'https://whmcs.example.com/includes/api.php',
            'whmcs_webhook_secret' => 'kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk',
            'whmcs_fetch_via_bridge' => $viaBridge,
        ]);
    }

    public function test_enable_flips_flag_after_successful_probe(): void
    {
        $tenant = $this->tenant();
        Http::fake(['*resolve.php' => Http::response(['status' => 'ok', 'invoice' => null], 200)]);

        $this->artisan('whmcs:use-bridge', ['--tenant' => $tenant->slug])->assertExitCode(0);

        $this->assertTrue((bool) $tenant->fresh()->whmcs_fetch_via_bridge);
    }

    public function test_enable_refuses_when_plugin_too_old(): void
    {
        // Deployed plugin lacks op=invoice → 400 unknown_op. We must NOT flip.
        $tenant = $this->tenant();
        Http::fake(['*resolve.php' => Http::response(['error' => 'unknown_op'], 400)]);

        $this->artisan('whmcs:use-bridge', ['--tenant' => $tenant->slug])->assertExitCode(1);

        $this->assertFalse((bool) $tenant->fresh()->whmcs_fetch_via_bridge);
    }

    public function test_off_reverts_flag_without_probing(): void
    {
        $tenant = $this->tenant(viaBridge: true);
        Http::fake();

        $this->artisan('whmcs:use-bridge', ['--tenant' => $tenant->slug, '--off' => true])->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertFalse((bool) $tenant->fresh()->whmcs_fetch_via_bridge);
    }

    public function test_unknown_tenant_returns_6(): void
    {
        Http::fake();
        $this->artisan('whmcs:use-bridge', ['--tenant' => 'nope-nope'])->assertExitCode(6);
        Http::assertNothingSent();
    }

    public function test_returns_3_when_bridge_not_configured(): void
    {
        // No webhook secret → bridge URL/secret not derivable → 3, no flip, no call.
        $tenant = Company::create([
            'name' => 'NoSecret',
            'slug' => 'ns-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
            'whmcs_api_url' => 'https://whmcs.example.com/includes/api.php',
        ]);
        Http::fake();

        $this->artisan('whmcs:use-bridge', ['--tenant' => $tenant->slug])->assertExitCode(3);

        Http::assertNothingSent();
        $this->assertFalse((bool) $tenant->fresh()->whmcs_fetch_via_bridge);
    }
}
