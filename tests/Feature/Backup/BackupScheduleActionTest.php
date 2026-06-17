<?php

namespace Tests\Feature\Backup;

use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Models\Company;
use App\Models\CompanyBackupSetting;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Locks the dehydration contract of the «Αυτόματα αντίγραφα» (backup_schedule)
 * action after the raw-toggle change hid the passphrase field when
 * secrets_mode='raw'. The guarantee: switching to «raw» must NOT wipe a stored
 * passphrase (a hidden field drops its key from $data → updateOrCreate leaves the
 * column untouched), and «passphrase» mode still persists the value.
 */
class BackupScheduleActionTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true); // policies bypassed; the action's super_admin gate is real
        $this->actor = User::create([
            'name' => 'Admin', 'email' => 'a-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]);
        $this->actingAs($this->actor);

        $this->company = Company::create([
            'name' => 'Host', 'slug' => 'host-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->actor->companies()->attach($this->company->id);
        app(TenantRoleProvisioner::class)->assignSuperAdmin($this->actor, $this->company);
        Filament::setTenant($this->company);
    }

    public function test_switching_to_raw_preserves_the_stored_passphrase(): void
    {
        // Existing encrypted-mode setting with a passphrase.
        CompanyBackupSetting::create([
            'company_id' => $this->company->id, 'enabled' => true, 'frequency' => 'daily',
            'run_at_time' => '02:00', 'bucket' => 'settings_setup', 'secrets_mode' => 'passphrase',
            'passphrase' => 'keep-me-123', 'retention_keep' => 7,
        ]);

        Livewire::test(ListCompanies::class)
            ->callTableAction('backup_schedule', $this->company, data: [
                'enabled' => true, 'frequency' => 'daily', 'run_at_time' => '02:00',
                'bucket' => 'settings_setup', 'secrets_mode' => 'raw', 'retention_keep' => 7,
            ])
            ->assertHasNoTableActionErrors();

        $setting = $this->company->backupSetting->fresh();
        $this->assertSame('raw', $setting->secrets_mode);
        // The hidden passphrase field is dropped from $data → column untouched,
        // NOT wiped (and the runner ignores it in raw mode anyway).
        $this->assertSame('keep-me-123', $setting->passphrase);
    }

    public function test_passphrase_mode_persists_the_passphrase(): void
    {
        Livewire::test(ListCompanies::class)
            ->callTableAction('backup_schedule', $this->company, data: [
                'enabled' => true, 'frequency' => 'daily', 'run_at_time' => '02:00',
                'bucket' => 'settings_setup', 'secrets_mode' => 'passphrase',
                'passphrase' => 'new-secret-456', 'retention_keep' => 7,
            ])
            ->assertHasNoTableActionErrors();

        $setting = $this->company->backupSetting;
        $this->assertSame('passphrase', $setting->secrets_mode);
        $this->assertSame('new-secret-456', $setting->passphrase);
    }
}
