<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T-1a: the read-only diagnostic command — output + exit codes.
 */
class WhmcsResolveThirdPartyCommandTest extends TestCase
{
    use RefreshDatabase;

    private const RESOLVE_URL = 'https://whmcs.example.com/modules/addons/ekdosi_bridge/resolve.php';

    private function tenant(bool $configured = true): Company
    {
        return Company::create([
            'name' => 'MyIP',
            'slug' => 'tpc-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
            'whmcs_api_url' => $configured ? 'https://whmcs.example.com/includes/api.php' : null,
            'whmcs_webhook_secret' => $configured ? str_repeat('z', 40) : null,
        ]);
    }

    public function test_unknown_tenant_exits_6(): void
    {
        $this->artisan('whmcs:resolve-third-party', ['invoice' => 1, '--tenant' => 'nope'])
            ->assertExitCode(6);
    }

    public function test_missing_tenant_option_is_invalid(): void
    {
        $this->artisan('whmcs:resolve-third-party', ['invoice' => 1])
            ->assertExitCode(2);
    }

    public function test_invoice_required_without_resellers_flag(): void
    {
        $t = $this->tenant();
        $this->artisan('whmcs:resolve-third-party', ['--tenant' => $t->slug])
            ->assertExitCode(2);
    }

    public function test_not_configured_exits_3(): void
    {
        $t = $this->tenant(configured: false);
        $this->artisan('whmcs:resolve-third-party', ['invoice' => 1, '--tenant' => $t->slug])
            ->assertExitCode(3);
    }

    public function test_resolve_renders_multi_party_warning(): void
    {
        Http::fake([self::RESOLVE_URL => Http::response([
            'status' => 'ok', 'whmcs_invoice_id' => 1234, 'userid' => 793,
            'timologia_present' => true,
            'lines' => [
                ['item_id' => 1, 'relid' => 1, 'type' => 'Domain', 'service_type' => 'domain',
                    'description' => 'a.gr', 'routed' => true, 'is_receipt' => false,
                    'contact' => ['id' => 1, 'company_name' => 'A', 'gr_vatno' => '111']],
                ['item_id' => 2, 'relid' => 2, 'type' => 'Hosting', 'service_type' => 'hosting',
                    'description' => 'plan', 'routed' => true, 'is_receipt' => true,
                    'contact' => ['id' => 2, 'company_name' => 'B', 'gr_vatno' => '222']],
            ],
        ], 200)]);

        $t = $this->tenant();
        $this->artisan('whmcs:resolve-third-party', ['invoice' => 1234, '--tenant' => $t->slug])
            ->expectsOutputToContain('MULTI-PARTY')
            ->assertExitCode(0);
    }

    public function test_resellers_mode_lists_clients(): void
    {
        Http::fake([self::RESOLVE_URL => Http::response([
            'status' => 'ok',
            'resellers' => [['userid' => 793, 'routes' => 4]],
        ], 200)]);

        $t = $this->tenant();
        $this->artisan('whmcs:resolve-third-party', ['--tenant' => $t->slug, '--resellers' => true])
            ->expectsOutputToContain('1 client(s) route at least one service')
            ->assertExitCode(0);
    }
}
