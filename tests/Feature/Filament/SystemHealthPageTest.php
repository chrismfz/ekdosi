<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ScheduleSettings;
use App\Filament\Pages\SystemHealth;
use App\Models\Company;
use App\Models\ScheduledTaskRun;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use App\Support\OperatorHealth\HealthRecorder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Υγεία συστήματος» — read-only web face of ops:health. SUPER_ADMIN-ONLY (it's
 * system/cross-tenant: every company's WHMCS + myDATA), so a company_admin must
 * not reach it.
 */
class SystemHealthPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true); // bypass unrelated policies; canAccess() checks super_admin directly
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
        // setUp's Gate::before(fn () => true) means EVERY permission check passes —
        // including View:SystemHealth, the page perm a company_admin holds. So this
        // proves the gate keys off super_admin (isSuperAdminAnywhere), NOT the shield
        // permission: a company_admin can't reach the cross-tenant report.
        $this->assertFalse(SystemHealth::canAccess());
    }

    #[Test]
    public function a_super_admin_sees_the_health_sections(): void
    {
        $this->makeSuperAdmin();
        $this->assertTrue(SystemHealth::canAccess());

        Livewire::test(SystemHealth::class)
            ->assertSuccessful()
            ->assertSee('Ουρά εργασιών')
            ->assertSee('Χρονοπρογραμματιστής')
            ->assertSee('Αντίγραφα ασφαλείας')
            ->assertSee('Δίσκος')
            ->assertSet('report.queue.worker_heartbeat_status', fn ($v) => $v !== null);
    }

    #[Test]
    public function refresh_rebuilds_the_report(): void
    {
        $this->makeSuperAdmin();

        Livewire::test(SystemHealth::class)
            ->callAction('refresh')
            ->assertHasNoActionErrors()
            ->assertSet('report.generated_at', fn ($v) => $v !== null);
    }

    #[Test]
    public function it_shows_the_durable_run_history(): void
    {
        $this->makeSuperAdmin();
        app(HealthRecorder::class)->recordScheduledRun('whmcs_fetch', 'running');
        app(HealthRecorder::class)->recordScheduledRun('whmcs_fetch', 'ok', 0);

        Livewire::test(SystemHealth::class)
            ->assertSee('Πρόσφατες εκτελέσεις')
            ->assertSee('WHMCS fetch')
            ->assertSet('report.recent_runs', fn ($v) => ! empty($v));
    }

    #[Test]
    public function it_links_to_the_schedule_settings_page(): void
    {
        $this->makeSuperAdmin();

        // The health table only shows WHAT ran; the on/off + timing switches live on
        // «Χρονοπρογραμματιστής» — this deep-link is where the operator flips them.
        Livewire::test(SystemHealth::class)
            ->assertActionExists('scheduleSettings')
            ->assertActionHasUrl('scheduleSettings', ScheduleSettings::getUrl());
    }

    #[Test]
    public function the_retry_action_is_hidden_when_no_jobs_failed(): void
    {
        $this->makeSuperAdmin();
        $this->assertSame(0, ScheduledTaskRun::query()->count()); // sanity: fresh DB

        // No failed_jobs rows → action hidden.
        Livewire::test(SystemHealth::class)
            ->assertActionHidden('retryFailedJobs');
    }
}
