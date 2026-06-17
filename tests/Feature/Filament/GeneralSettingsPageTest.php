<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\GeneralSettings;
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
 * «Ρυθμίσεις συστήματος» — super_admin-only deploy-wide knobs. Saving stores only
 * deviations from the env/config default and audits the change (same pattern as
 * ScheduleSettings). encrypt-secrets stays read-only guidance.
 */
class GeneralSettingsPageTest extends TestCase
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
        // Even with every permission bypassed, a non-super_admin is blocked.
        $this->assertFalse(GeneralSettings::canAccess());
    }

    #[Test]
    public function a_super_admin_sees_the_form_filled_with_effective_defaults(): void
    {
        $this->makeSuperAdmin();
        $this->assertTrue(GeneralSettings::canAccess());

        Livewire::test(GeneralSettings::class)
            ->assertSuccessful()
            ->assertSee('Ασφάλεια')
            ->assertSee('secrets:reencrypt')          // read-only encrypt guidance shown
            ->assertSee('Email (κατάσταση)')
            ->assertSet('data.require_2fa', (bool) config('ekdosi.require_2fa'))
            ->assertSet('data.backup_alert_on_failure', (bool) config('ekdosi.backup.alert_on_failure', true));
    }

    #[Test]
    public function global_backup_encryption_status_reflects_the_archive_password(): void
    {
        $this->makeSuperAdmin();

        // No archive password → the «χωρίς κωδικό» warning is shown.
        config(['backup.backup.password' => null]);
        Livewire::test(GeneralSettings::class)
            ->assertSuccessful()
            ->assertSee('ΧΩΡΙΣ κωδικό');

        // Password set → the encrypted status is shown instead.
        config(['backup.backup.password' => 's3cret']);
        Livewire::test(GeneralSettings::class)
            ->assertSuccessful()
            ->assertSee('Κρυπτογραφημένα με κωδικό')
            ->assertDontSee('ΧΩΡΙΣ κωδικό');
    }

    #[Test]
    public function saving_a_deviation_stores_an_override_and_audits_it(): void
    {
        $this->makeSuperAdmin();

        Livewire::test(GeneralSettings::class)
            ->set('data.require_2fa', true)                       // deviate from the OFF default
            ->set('data.backup_alert_on_failure', false)          // deviate from the ON default (bool path)
            ->set('data.backup_alert_email', 'ops@example.gr')    // deviate from the empty default
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('system_settings', [
            'key' => 'system.require_2fa', 'value' => '1', 'updated_by' => $this->user->id,
        ]);
        $this->assertDatabaseHas('system_settings', [
            'key' => 'system.backup_alert_on_failure', 'value' => '0', 'type' => 'bool',
        ]);
        $this->assertDatabaseHas('system_settings', [
            'key' => 'system.backup_alert_email', 'value' => 'ops@example.gr', 'type' => 'string',
        ]);
        $this->assertTrue(app(SystemSettings::class)->bool('system.require_2fa', false));

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'system_settings',
            'description' => 'Ενημέρωση ρυθμίσεων συστήματος',
        ]);
    }

    #[Test]
    public function saving_back_to_the_default_removes_the_override(): void
    {
        $this->makeSuperAdmin();
        app(SystemSettings::class)->setBool('system.require_2fa', true, $this->user->id);

        Livewire::test(GeneralSettings::class)
            ->set('data.require_2fa', false)   // back to the env default (OFF)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('system_settings', ['key' => 'system.require_2fa']);
    }
}
