<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\GeneralSettings;
use App\Filament\Resources\UpdateRuns\Pages\ListUpdateRuns;
use App\Models\Company;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use App\Services\Updates\UpdateChecker;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The «Ενημερώσεις» list page carries two read-only header actions: «Έλεγχος
 * ενημερώσεων» (runs the same UpdateChecker as SystemHealth — bust cache, toast,
 * never applies) and «Ρυθμίσεις ενημερώσεων» (deep-link to the repo/token settings).
 */
class ListUpdateRunsHeaderActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true); // isSuperAdmin() checks the role directly, not a permission
        $this->user = User::create([
            'name' => 'A', 'email' => 'a-'.uniqid().'@t.local', 'password' => bcrypt('x'),
        ]);
        $this->actingAs($this->user);
        $this->tenant = Company::create([
            'name' => 'Host', 'slug' => 'h-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->user->companies()->attach($this->tenant->id);
        Filament::setTenant($this->tenant);
        app(TenantRoleProvisioner::class)->assignSuperAdmin($this->user, $this->tenant);
    }

    #[Test]
    public function it_links_to_the_update_settings_page(): void
    {
        Livewire::test(ListUpdateRuns::class)
            ->assertActionExists('updateSettings')
            ->assertActionHasUrl('updateSettings', GeneralSettings::getUrl());
    }

    #[Test]
    public function the_check_action_reports_up_to_date_without_applying(): void
    {
        $this->mock(UpdateChecker::class, function ($m): void {
            $m->shouldReceive('check')->once()->andReturn(['ok' => true, 'update_available' => false]);
        });

        Livewire::test(ListUpdateRuns::class)
            ->callAction('checkUpdates')
            ->assertHasNoActionErrors()
            ->assertNotified('Είσαι στην πιο πρόσφατη έκδοση');
    }

    #[Test]
    public function the_check_action_reports_an_available_release(): void
    {
        $this->mock(UpdateChecker::class, function ($m): void {
            $m->shouldReceive('check')->once()->andReturn([
                'ok' => true, 'update_available' => true, 'latest_version' => 'v9.9.9',
            ]);
        });

        Livewire::test(ListUpdateRuns::class)
            ->callAction('checkUpdates')
            ->assertHasNoActionErrors()
            ->assertNotified('Διαθέσιμη νέα έκδοση: v9.9.9');
    }
}
