<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Preflight;
use App\Models\Company;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Έλεγχος ετοιμότητας» — cross-tenant config-readiness. SUPER_ADMIN-ONLY, like its
 * «Υγεία συστήματος» twin.
 */
class PreflightPageTest extends TestCase
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
            'name' => 'Host OE', 'slug' => 'host-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->user->companies()->attach($this->tenant->id);
        Filament::setTenant($this->tenant);
    }

    #[Test]
    public function a_non_super_admin_cannot_access_it(): void
    {
        // Gate::before → every permission passes, so a false here proves the gate
        // keys off super_admin (cross-tenant), not a per-tenant shield permission.
        $this->assertFalse(Preflight::canAccess());
    }

    #[Test]
    public function a_super_admin_sees_the_readiness_sections(): void
    {
        app(TenantRoleProvisioner::class)->assignSuperAdmin($this->user, $this->tenant);
        $this->assertTrue(Preflight::canAccess());

        Livewire::test(Preflight::class)
            ->assertSuccessful()
            ->assertSee('Host OE')
            ->assertSee('Ρυθμίσεις myDATA')
            ->assertSee('Πίνακες (lookups)')
            ->assertSet('report', fn ($v) => is_array($v) && $v !== []);
    }

    #[Test]
    public function refresh_rebuilds_the_report(): void
    {
        app(TenantRoleProvisioner::class)->assignSuperAdmin($this->user, $this->tenant);

        Livewire::test(Preflight::class)
            ->callAction('refresh')
            ->assertHasNoActionErrors()
            ->assertSet('report', fn ($v) => is_array($v) && $v !== []);
    }
}
