<?php

namespace Tests\Feature\Filament;

use App\Models\Company;
use App\Models\User;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
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
        $panel->shouldReceive('getId')->andReturn('admin');   // used by the deny-path log

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

    public function test_denied_access_is_logged_so_it_is_never_a_silent_403(): void
    {
        $panel = \Mockery::mock(Panel::class);
        $panel->shouldReceive('getId')->andReturn('admin');

        $user = User::create([
            'name' => 'Orphan', 'email' => 'orphan-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $ctx): bool => str_contains($message, 'no company')
                && $ctx['user_id'] === $user->getKey());

        $this->assertFalse($user->canAccessPanel($panel));
    }
}
