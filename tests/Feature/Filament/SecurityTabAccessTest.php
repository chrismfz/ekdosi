<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ActivityFeed;
use App\Models\Activity;
use App\Models\AuthEvent;
use App\Models\Company;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use Filament\Facades\Filament;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The «Συνδέσεις & ασφάλεια» tab of «Δραστηριότητα» is system-level (spans every
 * tenant, incl. failed attempts on non-existent usernames), so ONLY a system
 * super-admin may see it. A per-tenant company_admin must not — and must not be
 * able to reach the auth-log data by forcing ?activeTab=security.
 */
class SecurityTabAccessTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Sec OE', 'slug' => 'sec-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
            'afm' => '800000000',
        ]);
    }

    private function member(bool $superAdmin): User
    {
        $user = User::create([
            'name' => 'U', 'email' => 'u-'.uniqid().'@t.local', 'password' => bcrypt('x'),
        ]);
        $user->companies()->attach($this->tenant->id);

        if ($superAdmin) {
            app(TenantRoleProvisioner::class)->assignSuperAdmin($user, $this->tenant);
        }

        return $user;
    }

    private function enterPanel(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant);
    }

    public function test_super_admin_sees_the_security_tab_and_its_events(): void
    {
        $this->enterPanel($this->member(superAdmin: true));

        $event = AuthEvent::create([
            'guard' => 'web', 'event' => 'failed', 'user_id' => null,
            'email' => 'ghost@attacker.test', 'ip_address' => '203.0.113.9',
            'user_agent' => 'curl/8', 'created_at' => now(),
        ]);

        $page = new ActivityFeed;
        $this->assertTrue($page->canSeeSecurityTab());

        Livewire::test(ActivityFeed::class)
            ->set('activeTab', 'security')
            ->loadTable()  // table uses deferLoading()
            ->assertCanSeeTableRecords([$event]);
    }

    public function test_company_admin_cannot_see_the_security_tab(): void
    {
        $this->enterPanel($this->member(superAdmin: false));

        $page = new ActivityFeed;
        $this->assertFalse($page->canSeeSecurityTab());
    }

    public function test_non_super_admin_forcing_the_security_tab_falls_back_to_the_records_query(): void
    {
        // The security guarantee lives in table(): even with activeTab forced to
        // 'security', a non-super-admin gets the tenant-scoped Activity query, NOT
        // the system-level AuthEvent log. Assert the resolved model directly (no
        // mount → no View:ActivityFeed setup needed).
        $this->enterPanel($this->member(superAdmin: false));

        $page = new ActivityFeed;
        $page->activeTab = 'security';

        $resolved = $page->table(Table::make($page))->getQuery()->getModel();

        $this->assertInstanceOf(Activity::class, $resolved);
        $this->assertNotInstanceOf(AuthEvent::class, $resolved);
    }

    public function test_super_admin_security_tab_resolves_to_the_auth_event_query(): void
    {
        $this->enterPanel($this->member(superAdmin: true));

        $page = new ActivityFeed;
        $page->activeTab = 'security';

        $this->assertInstanceOf(
            AuthEvent::class,
            $page->table(Table::make($page))->getQuery()->getModel(),
        );
    }
}
