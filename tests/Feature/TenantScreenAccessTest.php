<?php

namespace Tests\Feature;

use App\Filament\Pages\MyDataConsole;
use App\Filament\Pages\MyDataConsoleExpenses;
use App\Filament\Pages\MyDataE3Overview;
use App\Filament\Pages\MyDataMarkDetail;
use App\Filament\Pages\Reports;
use App\Filament\Resources\Customers\Pages\CustomerLedger;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Filament\Resources\WhmcsInbox\WhmcsInboxResource;
use App\Models\Company;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * PR3: the 8 screens that used to bypass Shield with `auth()->check()` now ride
 * on real permissions. This proves the intended split:
 *   - operator   → daily docs (Quotes, WHMCS inbox, Καρτέλα, ΜΑΡΚ detail)
 *   - company_admin / super_admin → also the live myDATA consoles + Reports
 * and that a permission-less user is cleanly denied (no PermissionDoesNotExist
 * throw → no 404 storm).
 */
class TenantScreenAccessTest extends TestCase
{
    use RefreshDatabase;

    private string $guard = 'web';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Acc', 'slug' => 'acc-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox', 'afm' => '800000001',
        ]);

        // The permission rows shield:generate would create for the rewired
        // screens (page perms + the resource perms the operator set selects).
        foreach ([
            'View:MyDataConsole', 'View:MyDataConsoleExpenses', 'View:MyDataE3Overview',
            'View:MyDataMarkDetail', 'View:Reports',
            'ViewAny:Quote', 'View:Quote', 'Create:Quote', 'Update:Quote',
            'ViewAny:PendingWhmcsInvoice', 'View:PendingWhmcsInvoice', 'Update:PendingWhmcsInvoice',
            'ViewAny:Customer', 'View:Customer',
            'ViewAny:Invoice', 'View:Invoice',
        ] as $n) {
            Permission::findOrCreate($n, $this->guard);
        }

        app(TenantRoleProvisioner::class)->ensureStandardRoles($this->company);
    }

    private function actAs(string $role): User
    {
        $user = User::create([
            'name' => $role, 'email' => $role.'-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]);
        $user->companies()->attach($this->company->id);
        app(TenantRoleProvisioner::class)->assignStandardRole($user, $this->company, $role);

        // Mimic what TenantSet does in the panel: pin the team + ambient tenant.
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($this->company->getKey());
        $registrar->forgetCachedPermissions();
        $user->unsetRelation('roles');

        $this->actingAs($user);
        Filament::setTenant($this->company);

        return $user;
    }

    public function test_operator_sees_daily_docs_only(): void
    {
        $this->actAs(TenantRoleProvisioner::ROLE_OPERATOR);

        // Daily operator surfaces.
        $this->assertTrue(QuoteResource::canAccess(), 'operator → Quotes');
        $this->assertTrue(WhmcsInboxResource::canAccess(), 'operator → WHMCS inbox');
        $this->assertTrue(CustomerLedger::canAccess(), 'operator → Καρτέλα');
        $this->assertTrue(MyDataMarkDetail::canAccess(), 'operator → ΜΑΡΚ detail (via View:Invoice)');

        // Admin-only surfaces are hidden from the operator.
        $this->assertFalse(MyDataConsole::canAccess(), 'operator ✗ myDATA console');
        $this->assertFalse(MyDataConsoleExpenses::canAccess(), 'operator ✗ myDATA expenses console');
        $this->assertFalse(MyDataE3Overview::canAccess(), 'operator ✗ Ε3 overview');
        $this->assertFalse(Reports::canAccess(), 'operator ✗ Reports');
    }

    public function test_company_admin_sees_everything(): void
    {
        $this->actAs(TenantRoleProvisioner::ROLE_COMPANY_ADMIN);

        foreach ([
            QuoteResource::class, WhmcsInboxResource::class, CustomerLedger::class,
            MyDataMarkDetail::class, MyDataConsole::class, MyDataConsoleExpenses::class,
            MyDataE3Overview::class, Reports::class,
        ] as $screen) {
            $this->assertTrue($screen::canAccess(), "company_admin → {$screen}");
        }
    }

    public function test_permissionless_user_is_denied_without_throwing(): void
    {
        // Attached but no role → no managed permissions. Must be a clean deny,
        // not a PermissionDoesNotExist exception (the old 404-storm cause).
        $user = User::create([
            'name' => 'Nobody', 'email' => 'nobody-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]);
        $user->companies()->attach($this->company->id);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->getKey());
        $this->actingAs($user);
        Filament::setTenant($this->company);

        $this->assertFalse(QuoteResource::canAccess());
        $this->assertFalse(WhmcsInboxResource::canAccess());
        $this->assertFalse(CustomerLedger::canAccess());
        $this->assertFalse(MyDataConsole::canAccess());
        $this->assertFalse(Reports::canAccess());
    }
}
