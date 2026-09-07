<?php

namespace Tests\Feature\Domains;

use App\Filament\Clusters\DomainsCluster;
use App\Filament\Resources\DomainRegistrarConnections\DomainRegistrarConnectionResource;
use App\Filament\Resources\DomainTlds\DomainTldResource;
use App\Models\Company;
use App\Models\DomainRegistrarConnection;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use App\Support\Domains\DomainRegistrarCredentials;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Πυλώνας A / A0 — the whole Domains pillar is invisible unless the tenant has
 * it switched on (`companies.enable_domain_management`, default off), and the
 * registrar-connection screen additionally requires a system super-admin (it
 * carries credentials). Mirrors SupportPillarGateTest.
 */
class DomainsPillarGateTest extends TestCase
{
    use RefreshDatabase;

    private function bootPanelFor(Company $company, bool $superAdmin = true): User
    {
        Gate::before(fn () => true); // Shield perms allowed: only flag + super-admin should gate here
        $user = User::create(['name' => 'A', 'email' => 'a-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($company->id);
        if ($superAdmin) {
            // isSystemSuperAdmin() is ROLE-based (super_admin role in a tenant the
            // user is still a member of), not a column.
            app(TenantRoleProvisioner::class)->assignSuperAdmin($user, $company);
        }
        $this->actingAs($user);

        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);
        Filament::setTenant($company);

        return $user;
    }

    private function company(bool $domains): Company
    {
        return Company::create([
            'name' => 'Dom', 'slug' => 'd-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'enable_domain_management' => $domains,
        ]);
    }

    public function test_domains_screens_are_hidden_when_the_pillar_is_off(): void
    {
        $this->bootPanelFor($this->company(domains: false));

        $this->assertFalse(DomainRegistrarConnectionResource::canAccess(), 'Οι συνδέσεις registrar δεν πρέπει να φαίνονται με ανενεργό pillar');
        $this->assertFalse(DomainTldResource::canAccess(), 'Το «TLDs & τιμές» δεν πρέπει να φαίνεται με ανενεργό pillar');
        $this->assertFalse(DomainsCluster::canAccess(), 'Το cluster «Domains» πρέπει να είναι κρυμμένο');
    }

    public function test_domains_screens_appear_for_a_super_admin_when_the_pillar_is_on(): void
    {
        $this->bootPanelFor($this->company(domains: true));

        $this->assertTrue(DomainRegistrarConnectionResource::canAccess());
        $this->assertTrue(DomainTldResource::canAccess());
        $this->assertTrue(DomainsCluster::canAccess());
    }

    public function test_connections_stay_super_admin_only_even_with_the_pillar_on(): void
    {
        $this->bootPanelFor($this->company(domains: true), superAdmin: false);

        $this->assertFalse(
            DomainRegistrarConnectionResource::canAccess(),
            'Οι συνδέσεις registrar κουβαλούν credentials — μόνο system super-admin'
        );
    }

    public function test_connection_config_is_encrypted_at_rest_and_credentials_read_it(): void
    {
        $company = $this->company(domains: true);

        $conn = DomainRegistrarConnection::create([
            'company_id' => $company->id,
            'registrar' => 'manual',
            'label' => 'FORTH EPP',
            'mode' => 'sandbox',
            'config' => ['epp_user' => 'reg', 'epp_pass' => 's3cret'],
        ]);

        // At rest: the raw column is Laravel-encrypted, never plaintext JSON.
        $raw = $conn->getRawOriginal('config');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('s3cret', $raw);

        // Through the credentials value object: decrypted + fail-safe sandbox.
        $creds = DomainRegistrarCredentials::fromConnection($conn->fresh());
        $this->assertSame('s3cret', $creds->get('epp_pass'));
        $this->assertTrue($creds->sandbox, 'mode=sandbox → sandbox creds');

        $conn->update(['mode' => 'production']);
        $this->assertFalse(DomainRegistrarCredentials::fromConnection($conn->fresh())->sandbox);

        $conn->update(['mode' => 'typo-mode']);
        $this->assertTrue(
            DomainRegistrarCredentials::fromConnection($conn->fresh())->sandbox,
            'Οτιδήποτε εκτός από ρητό production πρέπει να μένει sandbox'
        );
    }
}
