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
 */
class TenantRolePickerActionTest extends TestCase
{
    use RefreshDatabase;

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
        // Bypass policies so we reach the action; the escalation guard for the
        // super_admin option is exercised in the provisioner-level test.
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Actor', 'email' => 'actor-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]));
    }

    public function test_picker_from_user_tenants_side_sets_role(): void
    {
        $company = $this->company('uside');
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
}
