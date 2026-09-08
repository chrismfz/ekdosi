<?php

namespace Tests\Feature\Updates;

use App\Filament\Resources\UpdateRuns\UpdateRunResource;
use App\Models\Company;
use App\Models\UpdateRun;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The «Ενημερώσεις» history is super_admin-only (deploy-wide, cross-tenant) and an
 * IMMUTABLE audit trail — created only by the SystemHealth action, never edited or
 * deleted from the panel.
 */
class UpdateRunResourceGatingTest extends TestCase
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
    }

    #[Test]
    public function a_non_super_admin_cannot_view_it(): void
    {
        $this->assertFalse(UpdateRunResource::canViewAny());
        $this->assertFalse(UpdateRunResource::shouldRegisterNavigation());
    }

    #[Test]
    public function a_super_admin_can_view_but_never_create_edit_or_delete(): void
    {
        app(TenantRoleProvisioner::class)->assignSuperAdmin($this->user, $this->tenant);

        $this->assertTrue(UpdateRunResource::canViewAny());
        $this->assertFalse(UpdateRunResource::canCreate());   // only the SystemHealth action creates

        $run = UpdateRun::create(['status' => UpdateRun::STATUS_SUCCEEDED, 'kind' => UpdateRun::KIND_UPDATE]);
        $this->assertFalse(UpdateRunResource::canEdit($run));    // immutable audit
        $this->assertFalse(UpdateRunResource::canDelete($run));
    }
}
