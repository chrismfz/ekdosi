<?php

namespace Tests\Feature\Hr;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use App\Support\Hr\ErganiStaff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Shared setup for the Προσωπικό tests: one tenant with REAL managed roles
 * (the permission rows shield:generate creates for the HR screens + a few
 * operator screens), and helpers to act as a role with a linked Employee.
 */
abstract class HrTestCase extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        ErganiStaff::flush();

        $this->company = Company::create([
            'name' => 'Hr Co', 'slug' => 'hr-'.uniqid(), 'country_code' => 'GR',
            'leave_notify_email' => 'accountant@example.test',
        ]);

        $perms = ['View:LeaveCalendar', 'View:Dashboard', 'ViewAny:Invoice', 'View:Invoice', 'Create:Invoice', 'Update:Invoice'];
        foreach (['LeaveRequest', 'Employee', 'CompanyHoliday'] as $r) {
            foreach (['ViewAny', 'View', 'Create', 'Update', 'Delete', 'DeleteAny', 'Restore', 'RestoreAny', 'ForceDelete', 'ForceDeleteAny'] as $a) {
                $perms[] = "{$a}:{$r}";
            }
        }
        foreach ($perms as $p) {
            Permission::findOrCreate($p, 'web');
        }

        app(TenantRoleProvisioner::class)->ensureStandardRoles($this->company);
    }

    protected function makeUser(string $role, ?Company $company = null): User
    {
        $company ??= $this->company;
        $user = User::create(['name' => $role.' user', 'email' => $role.'-'.uniqid().'@test.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($company->id);
        app(TenantRoleProvisioner::class)->assignStandardRole($user, $company, $role);

        return $user;
    }

    protected function actAs(User $user, ?Company $company = null): User
    {
        $company ??= $this->company;
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($company->getKey());
        $registrar->forgetCachedPermissions();
        $user->unsetRelation('roles')->unsetRelation('permissions');
        ErganiStaff::flush();

        $this->actingAs($user);
        Filament::setTenant($company);

        return $user;
    }

    protected function employeeFor(?User $user, string $last = 'Παπαδόπουλος', string $first = 'Νίκος'): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id,
            'user_id' => $user?->id,
            'last_name' => $last,
            'first_name' => $first,
            'afm' => (string) random_int(100000000, 999999999),
            'annual_leave_days' => 20,
        ]);
    }
}
