<?php

namespace Tests\Feature\Filament;

use App\Models\Company;
use App\Models\User;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the production-403 regression: the User MUST implement FilamentUser,
 * otherwise Filament allows the panel only in `local` and 403s in production
 * (which is exactly what happened when APP_ENV was corrected to production).
 */
class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_implements_the_filament_user_contract(): void
    {
        $this->assertInstanceOf(FilamentUser::class, new User);
    }

    public function test_panel_access_requires_tenant_membership(): void
    {
        $panel = \Mockery::mock(Panel::class);

        $user = User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]);

        // No company yet → no panel access.
        $this->assertFalse($user->canAccessPanel($panel));

        $company = Company::create([
            'name' => 'T', 'slug' => 'pa-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $user->companies()->attach($company->id);

        // Belongs to a tenant → can reach the panel.
        $this->assertTrue($user->fresh()->canAccessPanel($panel));
    }
}
