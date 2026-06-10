<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ScheduleSettings;
use App\Models\Company;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use App\Support\Settings\SystemSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Ρυθμίσεις χρονοπρογραμματιστή» — super_admin-only, like SystemHealth. Saving
 * stores only deviations from the env default and audits the change.
 */
class ScheduleSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true); // canAccess() checks super_admin directly, not a permission
        $this->user = User::create([
            'name' => 'Admin', 'email' => 'a-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]);
        $this->actingAs($this->user);
        $this->tenant = Company::create([
            'name' => 'Host', 'slug' => 'host-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->user->companies()->attach($this->tenant->id);
        Filament::setTenant($this->tenant);
    }

    private function makeSuperAdmin(): void
    {
        app(TenantRoleProvisioner::class)->assignSuperAdmin($this->user, $this->tenant);
    }

    #[Test]
    public function a_non_super_admin_cannot_access_it(): void
    {
        // Even with every permission bypassed (Gate::before), a non-super_admin is blocked.
        $this->assertFalse(ScheduleSettings::canAccess());
    }

    #[Test]
    public function a_super_admin_sees_the_form_filled_with_effective_values(): void
    {
        $this->makeSuperAdmin();
        $this->assertTrue(ScheduleSettings::canAccess());

        Livewire::test(ScheduleSettings::class)
            ->assertSuccessful()
            ->assertSee('WHMCS')
            // mydata_reconcile defaults ON in config → toggle filled true.
            ->assertSet('data.mydata_reconcile_enabled', true)
            // overdue defaults OFF → toggle filled false.
            ->assertSet('data.overdue_notifications_enabled', false);
    }

    #[Test]
    public function saving_a_deviation_stores_an_override_and_audits_it(): void
    {
        $this->makeSuperAdmin();

        Livewire::test(ScheduleSettings::class)
            ->set('data.mydata_reconcile_enabled', false) // deviate from the ON default
            ->call('save')
            ->assertHasNoErrors();

        // Override row written; the store reflects it.
        $this->assertDatabaseHas('system_settings', [
            'key' => 'schedule.mydata_reconcile_enabled', 'value' => '0', 'updated_by' => $this->user->id,
        ]);
        $this->assertFalse(app(SystemSettings::class)->bool('schedule.mydata_reconcile_enabled', true));

        // Audited.
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'system_settings',
            'description' => 'Ενημέρωση ρυθμίσεων χρονοπρογραμματιστή',
        ]);
    }

    #[Test]
    public function saving_back_to_the_default_removes_the_override(): void
    {
        $this->makeSuperAdmin();
        app(SystemSettings::class)->setBool('schedule.mydata_reconcile_enabled', false, $this->user->id);

        // Toggle it back to the env default (ON) → override row should be dropped.
        Livewire::test(ScheduleSettings::class)
            ->set('data.mydata_reconcile_enabled', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('system_settings', ['key' => 'schedule.mydata_reconcile_enabled']);
    }
}
