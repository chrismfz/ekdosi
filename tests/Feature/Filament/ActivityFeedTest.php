<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ActivityFeed;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The tenant-wide activity feed: company_id is stamped from the subject on
 * write, the feed lists only the current tenant's activity, and access is
 * admin-gated (View:ActivityFeed — operators excluded).
 */
class ActivityFeedTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $slug): Company
    {
        return Company::create([
            'name' => $slug, 'slug' => $slug.'-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    private function customer(Company $company, string $name): Customer
    {
        return Customer::create(['company_id' => $company->id, 'name' => $name, 'afm' => '123456789']);
    }

    public function test_company_id_is_stamped_from_the_subject(): void
    {
        $company = $this->company('stamp');
        $this->actingAs(User::create(['name' => 'U', 'email' => 'u-'.uniqid().'@t.local', 'password' => bcrypt('x')]));

        $customer = $this->customer($company, 'Πελάτης');

        $activity = Activity::query()->latest('id')->first();
        $this->assertSame($company->id, $activity->company_id);
    }

    public function test_feed_lists_only_the_current_tenants_activity(): void
    {
        Gate::before(fn () => true); // bypass canAccess; we're testing the query scope

        $a = $this->company('tenant-a');
        $b = $this->company('tenant-b');
        $this->actingAs(User::create(['name' => 'U', 'email' => 'u-'.uniqid().'@t.local', 'password' => bcrypt('x')]));

        $custA = $this->customer($a, 'Alpha Πελάτης');
        $custB = $this->customer($b, 'Beta Πελάτης');

        Filament::setTenant($a);

        Livewire::test(ActivityFeed::class)
            ->assertSuccessful()
            ->call('loadTable') // the page defers loading (wire:init="loadTable")
            ->assertCanSeeTableRecords(Activity::where('company_id', $a->id)->get())
            ->assertCanNotSeeTableRecords(Activity::where('company_id', $b->id)->get());
    }

    public function test_access_is_admin_gated(): void
    {
        $company = $this->company('gate');
        Permission::findOrCreate('View:ActivityFeed', 'web');
        Permission::findOrCreate('ViewAny:Customer', 'web');
        app(TenantRoleProvisioner::class)->ensureStandardRoles($company);

        $operator = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $admin = User::create(['name' => 'Ad', 'email' => 'ad-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $operator->companies()->attach($company->id);
        $admin->companies()->attach($company->id);
        app(TenantRoleProvisioner::class)->assignStandardRole($operator, $company, TenantRoleProvisioner::ROLE_OPERATOR);
        app(TenantRoleProvisioner::class)->assignStandardRole($admin, $company, TenantRoleProvisioner::ROLE_COMPANY_ADMIN);

        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());

        // actingAs BEFORE setTenant — the TenantSet event requires an auth user.
        $this->actingAs($operator);
        Filament::setTenant($company);
        $this->assertFalse(ActivityFeed::canAccess(), 'operator must NOT see the activity feed');

        $this->actingAs($admin);
        $this->assertTrue(ActivityFeed::canAccess(), 'company_admin sees the activity feed');
    }
}
