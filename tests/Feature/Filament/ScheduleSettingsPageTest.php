<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ScheduleSettings;
use App\Filament\Pages\SystemHealth;
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
    public function the_newly_surfaced_flags_are_togglable_and_default_off(): void
    {
        $this->makeSuperAdmin();

        // whmcs_fetch_unpaid / tickets_poll_imap / domain_sync used to live ONLY in
        // .env — now they have a toggle here (routes/console already reads the
        // override). All three default OFF in config → filled false.
        Livewire::test(ScheduleSettings::class)
            ->assertSuccessful()
            ->assertSee('Υποστήριξη & domains') // the new section heading renders
            ->assertSet('data.whmcs_fetch_unpaid_enabled', false)
            ->assertSet('data.tickets_poll_imap_enabled', false)
            ->assertSet('data.domain_sync_enabled', false)
            // turn one on → override row persists (the switch is REAL, not cosmetic)
            ->set('data.tickets_poll_imap_enabled', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('system_settings', [
            'key' => 'schedule.tickets_poll_imap_enabled', 'value' => '1', 'updated_by' => $this->user->id,
        ]);
        $this->assertTrue(app(SystemSettings::class)->bool('schedule.tickets_poll_imap_enabled', false));
    }

    #[Test]
    public function it_links_to_the_health_backups_section(): void
    {
        $this->makeSuperAdmin();

        // The «Αρχεία αντιγράφων (Υγεία)» header action deep-links to the health
        // screen's backups anchor — the visibility bridge the operator asked for.
        // assertActionHasUrl reads the action's REAL getUrl(), so a wrong target
        // (or a dropped #backups fragment) fails here.
        Livewire::test(ScheduleSettings::class)
            ->assertActionExists('viewBackups')
            ->assertActionHasUrl('viewBackups', SystemHealth::getUrl().'#backups');
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

    #[Test]
    public function saving_a_valid_timing_deviation_stores_a_string_override(): void
    {
        $this->makeSuperAdmin();

        Livewire::test(ScheduleSettings::class)
            ->set('data.backup_run_cron', '0 3 * * *')       // deviate from '0 2 * * *'
            ->set('data.mydata_reconcile_time', '07:15')     // deviate from '06:00'
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('system_settings', [
            'key' => 'schedule.backup_run_cron', 'value' => '0 3 * * *', 'type' => 'string',
        ]);
        $this->assertDatabaseHas('system_settings', [
            'key' => 'schedule.mydata_reconcile_time', 'value' => '07:15', 'type' => 'string',
        ]);
    }

    #[Test]
    public function an_invalid_cron_is_rejected_and_not_stored(): void
    {
        $this->makeSuperAdmin();

        Livewire::test(ScheduleSettings::class)
            ->set('data.backup_run_cron', 'δεν-είναι-cron')
            ->call('save')
            ->assertHasErrors('data.backup_run_cron');

        $this->assertDatabaseMissing('system_settings', ['key' => 'schedule.backup_run_cron']);
    }

    #[Test]
    public function an_invalid_time_is_rejected_and_not_stored(): void
    {
        $this->makeSuperAdmin();

        Livewire::test(ScheduleSettings::class)
            ->set('data.mydata_reconcile_time', '99:99')
            ->call('save')
            ->assertHasErrors('data.mydata_reconcile_time');

        $this->assertDatabaseMissing('system_settings', ['key' => 'schedule.mydata_reconcile_time']);
    }
}
