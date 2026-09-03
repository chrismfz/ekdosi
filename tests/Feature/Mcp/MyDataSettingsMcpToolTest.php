<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\EkdosiMcpServer;
use App\Mcp\Tools\MyDataSettingsMcpTool;
use App\Models\Company;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `mydata_settings` is the read-only channel-config tool that explains the
 * «πάροχος δοκιμαστικός → η κονσόλα φέρνει μόνο sandbox παραστατικά» confusion.
 * Two things are locked here: (1) it surfaces the RESOLVED read environment
 * (sandbox vs production) + its AADE endpoint, and honours the read-env override;
 * (2) it is super_admin-only and NEVER egresses a subscription key.
 */
class MyDataSettingsMcpToolTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(Company $company): User
    {
        $user = User::create([
            'name' => 'Root',
            'email' => 'root-'.uniqid().'@test.local',
            'password' => bcrypt('x'),
        ]);
        $user->companies()->attach($company->id);
        app(TenantRoleProvisioner::class)->assignSuperAdmin($user, $company);

        return $user->fresh();
    }

    private function member(Company $company): User
    {
        $user = User::create([
            'name' => 'Op',
            'email' => 'op-'.uniqid().'@test.local',
            'password' => bcrypt('x'),
        ]);
        $user->companies()->attach($company->id);

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function company(array $attrs): Company
    {
        return Company::create(array_merge([
            'name' => 'T', 'slug' => 't-'.uniqid(), 'country_code' => 'GR',
        ], $attrs));
    }

    public function test_reports_sandbox_read_environment_for_a_sandbox_provider(): void
    {
        $prov = $this->company([
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox', 'mydata_mode' => 'off',
            'mydata_aade_id_sandbox' => '800561849', 'mydata_subscription_key_sandbox' => 'SBXKEY',
            'mydata_aade_id_production' => '800561849M', 'mydata_subscription_key_production' => 'PRODKEY',
        ]);

        $response = EkdosiMcpServer::actingAs($this->superAdmin($prov))
            ->tool(MyDataSettingsMcpTool::class, ['company' => $prov->slug]);

        $response->assertOk();
        // Reads land on the AADE SANDBOX host — the whole point of the tool.
        $response->assertSee('mydataapidev.aade.gr');
        $response->assertSee('gr-provider');
    }

    public function test_read_env_override_is_reflected_as_production(): void
    {
        $prov = $this->company([
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox', 'mydata_mode' => 'off',
            'mydata_read_env' => 'production',
            'mydata_aade_id_sandbox' => '800561849', 'mydata_subscription_key_sandbox' => 'SBXKEY',
            'mydata_aade_id_production' => '800561849M', 'mydata_subscription_key_production' => 'PRODKEY',
        ]);

        $response = EkdosiMcpServer::actingAs($this->superAdmin($prov))
            ->tool(MyDataSettingsMcpTool::class, ['company' => $prov->slug]);

        $response->assertOk();
        // The override flips the resolved read env to the production AADE host.
        $response->assertSee('mydatapi.aade.gr');
    }

    public function test_never_emits_a_subscription_key(): void
    {
        $prov = $this->company([
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'production', 'mydata_mode' => 'off',
            'mydata_aade_id_production' => '800561849M',
            'mydata_subscription_key_production' => 'TOP_SECRET_SUB_KEY_XYZ',
        ]);

        $response = EkdosiMcpServer::actingAs($this->superAdmin($prov))
            ->tool(MyDataSettingsMcpTool::class, ['company' => $prov->slug]);

        $response->assertOk();
        // Presence is reported, the key value itself never is.
        $response->assertSee('production_subscription_key');
        $response->assertDontSee('TOP_SECRET_SUB_KEY_XYZ');
    }

    public function test_not_offered_to_a_tenant_member(): void
    {
        $prov = $this->company([
            'einvoice_provider' => 'gr-provider', 'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox', 'mydata_mode' => 'off',
        ]);

        $response = EkdosiMcpServer::actingAs($this->member($prov))
            ->tool(MyDataSettingsMcpTool::class, ['company' => $prov->slug]);

        $response->assertHasErrors();
    }
}
