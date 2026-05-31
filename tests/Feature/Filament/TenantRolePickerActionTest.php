<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\RelationManagers\UsersRelationManager;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\RelationManagers\CompaniesRelationManager;
use App\Models\Company;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Drives the role-picker action through BOTH relation managers' Livewire
 * components, proving the resolveUser/resolveCompany wiring hands the right
 * (User, Company) pair to the provisioner from each side of the pivot.
 *
 * Role management is super_admin-only, so the acting user is made super_admin in
 * the target company first (the action is hidden otherwise — see the dedicated
 * visibility test).
 */
class TenantRolePickerActionTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private function company(string $slug): Company
    {
        return Company::create([
            'name' => $slug, 'slug' => $slug.'-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Bypass POLICIES so we reach the relation manager; the action's own
        // visibility still requires real super_admin role membership (not Gate).
        Gate::before(fn () => true);
        $this->actor = User::create([
            'name' => 'Actor', 'email' => 'actor-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]);
        $this->actingAs($this->actor);
    }

    public function test_picker_from_user_tenants_side_sets_role(): void
    {
        $company = $this->company('uside');
        app(TenantRoleProvisioner::class)->assignSuperAdmin($this->actor, $company);

        $user = User::create(['name' => 'Target', 'email' => 't-'.uniqid().'@test.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($company->id);

        Livewire::test(CompaniesRelationManager::class, [
            'ownerRecord' => $user,
            'pageClass' => EditUser::class,
        ])->callTableAction('manageTenantRole', $company, data: [
            'role' => TenantRoleProvisioner::ROLE_OPERATOR,
        ])->assertHasNoTableActionErrors();

        $this->assertSame(
            TenantRoleProvisioner::ROLE_OPERATOR,
            app(TenantRoleProvisioner::class)->roleInCompany($user, $company),
        );
    }

    public function test_picker_from_company_users_side_sets_role(): void
    {
        $company = $this->company('cside');
        app(TenantRoleProvisioner::class)->assignSuperAdmin($this->actor, $company);

        $user = User::create(['name' => 'Target', 'email' => 't-'.uniqid().'@test.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($company->id);

        Livewire::test(UsersRelationManager::class, [
            'ownerRecord' => $company,
            'pageClass' => EditCompany::class,
        ])->callTableAction('manageTenantRole', $user, data: [
            'role' => TenantRoleProvisioner::ROLE_COMPANY_ADMIN,
        ])->assertHasNoTableActionErrors();

        $this->assertSame(
            TenantRoleProvisioner::ROLE_COMPANY_ADMIN,
            app(TenantRoleProvisioner::class)->roleInCompany($user, $company),
        );
    }

    public function test_non_super_actor_cannot_demote_a_super_admin(): void
    {
        $company = $this->company('guard');

        // The TARGET is super_admin; the ACTOR is only company_admin (not super).
        $target = User::create(['name' => 'Boss', 'email' => 'boss-'.uniqid().'@test.local', 'password' => bcrypt('x')]);
        $target->companies()->attach($company->id);
        $provisioner = app(TenantRoleProvisioner::class);
        $provisioner->assignSuperAdmin($target, $company);
        $provisioner->assignStandardRole($this->actor, $company, TenantRoleProvisioner::ROLE_COMPANY_ADMIN);

        // The action is not even visible to a non-super actor → calling it is
        // rejected, and the target keeps super_admin.
        Livewire::test(UsersRelationManager::class, [
            'ownerRecord' => $company,
            'pageClass' => EditCompany::class,
        ])->assertTableActionHidden('manageTenantRole', $target);

        $this->assertTrue($provisioner->hasSuperAdminIn($target, $company), 'target must remain super_admin');
    }
}
