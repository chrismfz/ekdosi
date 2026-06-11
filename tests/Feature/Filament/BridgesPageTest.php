<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Bridges;
use App\Filament\Resources\WhmcsInbox\WhmcsInboxResource;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * «Γέφυρες» — the honest, read-mostly bridges list, + the inbox relabel.
 * Proves: the page lists registry sources with TRUTHFUL status (no fake
 * toggle), company_admin auto-gets View:Bridges, the inbox is de-WHMCS'd in
 * the UI.
 */
class BridgesPageTest extends TestCase
{
    use RefreshDatabase;

    private string $guard = 'web';

    private function makeCompany(bool $withWhmcs = false): Company
    {
        return Company::create([
            'name' => 'Bridge OE', 'slug' => 'br-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'whmcs_api_url' => $withWhmcs ? 'https://billing.example.gr/includes/api.php' : null,
            'whmcs_api_identifier' => $withWhmcs ? 'id' : null,
            'whmcs_api_secret' => $withWhmcs ? 'secret' : null,
        ]);
    }

    #[Test]
    public function the_inbox_is_relabelled_source_neutral(): void
    {
        $this->assertSame('Εισερχόμενα', WhmcsInboxResource::getNavigationLabel());
        $this->assertSame('Εισερχόμενα', WhmcsInboxResource::getPluralModelLabel());
        $this->assertStringNotContainsStringIgnoringCase('whmcs', WhmcsInboxResource::getNavigationLabel());
    }

    #[Test]
    public function company_admin_gets_the_page_permission_operator_does_not(): void
    {
        foreach (['View:Bridges', 'ViewAny:Invoice'] as $n) {
            Permission::findOrCreate($n, $this->guard);
        }
        $company = $this->makeCompany();
        app(TenantRoleProvisioner::class)->ensureStandardRoles($company);

        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Role::where('name', TenantRoleProvisioner::ROLE_COMPANY_ADMIN)->where('company_id', $company->getKey())->first();
        $operator = Role::where('name', TenantRoleProvisioner::ROLE_OPERATOR)->where('company_id', $company->getKey())->first();

        $this->assertContains('View:Bridges', $admin->permissions->pluck('name')->all());
        $this->assertNotContains('View:Bridges', $operator->permissions->pluck('name')->all());
    }

    #[Test]
    public function a_user_without_the_permission_cannot_access(): void
    {
        $company = $this->makeCompany();
        $user = User::create(['name' => 'Plain', 'email' => 'p-'.uniqid().'@test.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($company->id);
        $this->actingAs($user);
        Filament::setTenant($company);

        $this->assertFalse(Bridges::canAccess());
    }

    private function actAsAuthorized(bool $withWhmcs): Company
    {
        Gate::before(fn () => true);
        $user = User::create(['name' => 'Admin', 'email' => 'a-'.uniqid().'@test.local', 'password' => bcrypt('x')]);
        $this->actingAs($user);
        $company = $this->makeCompany($withWhmcs);
        $user->companies()->attach($company->id);
        Filament::setTenant($company);

        return $company;
    }

    #[Test]
    public function it_lists_whmcs_with_configured_status_when_creds_present(): void
    {
        $this->actAsAuthorized(withWhmcs: true);

        $page = Livewire::test(Bridges::class)->assertSuccessful();
        $bridges = $page->get('bridges');

        $this->assertCount(1, $bridges); // only WHMCS registered today
        $this->assertSame('whmcs', $bridges[0]['key']);
        $this->assertSame('WHMCS', $bridges[0]['label']);
        $this->assertSame('configured', $bridges[0]['status']);
        $this->assertNotNull($bridges[0]['settings_url'], 'an updater gets a configure link');
        $page->assertSee('WHMCS')->assertSee('Ρυθμισμένο');
    }

    #[Test]
    public function whmcs_shows_unconfigured_when_no_creds(): void
    {
        $this->actAsAuthorized(withWhmcs: false);

        $bridges = Livewire::test(Bridges::class)->get('bridges');

        $this->assertSame('unconfigured', $bridges[0]['status']);
    }

    #[Test]
    public function configure_link_is_hidden_when_the_user_cannot_update_the_company(): void
    {
        // Grant ONLY the page-view ability; 'update' falls through to the real
        // CompanyPolicy (denied — no Update:Company) → status visible, no button.
        Gate::before(fn ($user, string $ability) => $ability === 'View:Bridges' ? true : null);
        $company = $this->makeCompany(withWhmcs: true);
        $user = User::create(['name' => 'CA', 'email' => 'ca-'.uniqid().'@test.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($company->id);
        $this->actingAs($user);
        Filament::setTenant($company);

        $bridges = Livewire::test(Bridges::class)->get('bridges');

        $this->assertSame('configured', $bridges[0]['status']);
        $this->assertNull($bridges[0]['settings_url'], 'non-updater gets status but no configure link');
    }
}
