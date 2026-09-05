<?php

namespace Tests\Feature\Portal;

use App\Models\CustomerUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * «Changing your password ends your other sessions.» The portal binds each
 * session to the login's password hash; a change ANYWHERE (reset from another
 * device, operator reset, or the customer's own profile change) logs out every
 * OTHER session on its next request, while the session that made the change
 * survives.
 */
class PortalSessionInvalidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();   // reset login/profile throttles between tests
    }

    private function activeUser(): CustomerUser
    {
        return CustomerUser::factory()->create([
            'email' => 'session@example.com',
            'password' => Hash::make('secret-pass-123'),
            'status' => CustomerUser::STATUS_ACTIVE,
        ]);
    }

    public function test_a_password_change_elsewhere_logs_out_this_session(): void
    {
        $user = $this->activeUser();

        $this->post('/user/login', ['email' => $user->email, 'password' => 'secret-pass-123'])
            ->assertRedirect(route('portal.home'));
        $this->get('/user')->assertOk();   // first protected request seeds the hash

        // The password is changed on another device (a reset / operator / other
        // session) — a raw DB write, no cached model touched.
        CustomerUser::query()->whereKey($user->id)->update([
            'password' => Hash::make('a-New-Password-9'),
            'password_changed_at' => now(),
        ]);

        // Fresh request reloads the user from the DB (new hash); the session still
        // holds the old hash → stale → logged out.
        $this->app['auth']->forgetGuards();

        $this->get('/user')
            ->assertRedirect(route('portal.login'))
            ->assertSessionHas('status');
        $this->assertGuest('portal');
    }

    public function test_the_session_that_changes_the_password_survives(): void
    {
        $user = $this->activeUser();

        $this->post('/user/login', ['email' => $user->email, 'password' => 'secret-pass-123']);
        $this->get('/user')->assertOk();   // seed

        // The customer changes their OWN password via the profile page.
        $this->post(route('portal.profile.password'), [
            'current_password' => 'secret-pass-123',
            'password' => 'a-New-Password-9',
            'password_confirmation' => 'a-New-Password-9',
        ])->assertSessionHasNoErrors();

        $this->app['auth']->forgetGuards();

        // This session's stored hash was refreshed, so it stays authenticated.
        $this->get('/user')->assertOk();
        $this->assertAuthenticatedAs($user->fresh(), 'portal');
    }

    public function test_an_acting_as_session_is_seeded_not_logged_out(): void
    {
        // A session with no stored hash (actingAs, or a pre-feature session) must
        // be seeded on first sighting, never force-logged-out.
        $user = $this->activeUser();

        $this->actingAs($user, 'portal')->get('/user')->assertOk();
        $this->assertAuthenticatedAs($user, 'portal');
    }

    public function test_reset_rotates_remember_token_so_stale_remember_cookies_die(): void
    {
        // Remembered sessions on other/stolen devices are cut off by rotating the
        // remember_token (the recaller then fails retrieveByToken) — the mechanism
        // the «assume compromise» goal leans on for remembered devices.
        $user = $this->activeUser();
        $user->forceFill(['remember_token' => 'stale-remember-token-value'])->save();

        $token = Password::broker('customer_users')->createToken($user);
        $this->post('/user/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'a-New-Password-9', 'password_confirmation' => 'a-New-Password-9',
        ])->assertRedirect(route('portal.login'));

        $this->assertNotSame('stale-remember-token-value', $user->fresh()->remember_token);
    }

    public function test_profile_password_change_rotates_remember_token(): void
    {
        $user = $this->activeUser();
        $user->forceFill(['remember_token' => 'stale-remember-token-value'])->save();

        $this->post('/user/login', ['email' => $user->email, 'password' => 'secret-pass-123']);
        $this->get('/user')->assertOk();

        $this->post(route('portal.profile.password'), [
            'current_password' => 'secret-pass-123',
            'password' => 'a-New-Password-9',
            'password_confirmation' => 'a-New-Password-9',
        ])->assertSessionHasNoErrors();

        $this->assertNotSame('stale-remember-token-value', $user->fresh()->remember_token);
    }
}
