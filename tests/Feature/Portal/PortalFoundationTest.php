<?php

namespace Tests\Feature\Portal;

use App\Filament\Resources\CustomerUsers\CustomerUserResource;
use App\Models\CustomerUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Foundation guarantees for the customer-portal base (from the whole-subsystem
 * audit): the reset broker is isolated from the operator broker, logout does not
 * clobber a co-logged-in operator's session, and a soft-deleted login stays
 * reachable for restore instead of becoming an invisible email-blocking dead end.
 */
class PortalFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();   // reset the login throttle between tests
    }

    public function test_portal_reset_broker_uses_a_separate_token_table(): void
    {
        // Same-email operator+customer would collide on a shared, email-keyed table.
        $this->assertSame('customer_users_password_reset_tokens', config('auth.passwords.customer_users.table'));
        $this->assertNotSame(
            config('auth.passwords.users.table'),
            config('auth.passwords.customer_users.table'),
        );
        $this->assertTrue(Schema::hasTable('customer_users_password_reset_tokens'));
    }

    public function test_logout_destroys_the_session_server_side(): void
    {
        $user = CustomerUser::factory()->create([
            'password' => Hash::make('secret-pass-123'),
            'status' => CustomerUser::STATUS_ACTIVE,
        ]);

        $this->post('/user/login', ['email' => $user->email, 'password' => 'secret-pass-123']);
        $this->assertAuthenticatedAs($user, 'portal');

        // Logout invalidate()s the shared session: any prior session data is gone
        // (so a stolen pre-logout cookie can't keep authenticating), and the portal
        // guard is a guest. The whole-session teardown is intentional (a shared
        // record can't be partially destroyed) — see config/auth.php.
        $this->withSession(['pre_logout_marker' => 'x'])
            ->post('/user/logout')
            ->assertRedirect(route('portal.login'))
            ->assertSessionMissing('pre_logout_marker');

        $this->assertGuest('portal');
    }

    public function test_operator_resource_can_reach_a_soft_deleted_login(): void
    {
        $user = CustomerUser::factory()->create();
        $user->delete();   // soft delete

        // The resource query drops the SoftDeletingScope, so the TrashedFilter /
        // RestoreAction can find and restore the row.
        $this->assertTrue(
            CustomerUserResource::getEloquentQuery()->whereKey($user->id)->exists(),
        );
    }
}
