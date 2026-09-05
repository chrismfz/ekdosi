<?php

namespace Tests\Feature\Portal;

use App\Models\CustomerUser;
use App\Notifications\Portal\PortalResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Customer-portal password reset + invited-login «claim». The request endpoint
 * never discloses whether an email exists (one generic message), a suspended
 * login gets no mail, and the reset link sets an invited login's first password
 * AND activates it. Uses the dedicated `customer_users` broker.
 */
class PortalPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('portal-pwreset:'.sha1('active@example.com'));
        Cache::flush();
    }

    private function make(string $status, ?string $password = 'secret-pass-123', string $email = 'active@example.com'): CustomerUser
    {
        return CustomerUser::factory()->create([
            'email' => $email,
            'password' => $password ? Hash::make($password) : null,
            'status' => $status,
        ]);
    }

    public function test_request_sends_a_link_to_an_active_login(): void
    {
        Notification::fake();
        $user = $this->make(CustomerUser::STATUS_ACTIVE);

        $this->post('/user/forgot-password', ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('status');

        Notification::assertSentTo($user, PortalResetPasswordNotification::class);
    }

    public function test_request_sends_the_claim_link_to_an_invited_login(): void
    {
        Notification::fake();
        $user = $this->make(CustomerUser::STATUS_INVITED, password: null, email: 'invited@example.com');

        $this->post('/user/forgot-password', ['email' => $user->email])->assertSessionHas('status');

        Notification::assertSentTo($user, PortalResetPasswordNotification::class);
    }

    public function test_request_sends_nothing_to_a_suspended_login_but_looks_identical(): void
    {
        Notification::fake();
        $user = $this->make(CustomerUser::STATUS_SUSPENDED, email: 'suspended@example.com');

        $this->post('/user/forgot-password', ['email' => $user->email])->assertSessionHas('status');

        Notification::assertNothingSent();
    }

    public function test_request_for_an_unknown_email_discloses_nothing(): void
    {
        Notification::fake();

        $this->post('/user/forgot-password', ['email' => 'nobody@example.com'])->assertSessionHas('status');

        Notification::assertNothingSent();
    }

    public function test_honeypot_blocks_a_bot_silently(): void
    {
        Notification::fake();
        $user = $this->make(CustomerUser::STATUS_ACTIVE);

        $this->post('/user/forgot-password', [
            'email' => $user->email,
            'company_website' => 'http://spam.example',   // honeypot filled
        ])->assertSessionHas('status');

        Notification::assertNothingSent();
    }

    public function test_per_email_throttle_stops_further_sends(): void
    {
        Notification::fake();
        $user = $this->make(CustomerUser::STATUS_ACTIVE);

        // Pre-exhaust the per-email limiter (MAX_PER_EMAIL = 5).
        $key = 'portal-pwreset:'.sha1(mb_strtolower($user->email));
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::hit($key, 3600);
        }

        $this->post('/user/forgot-password', ['email' => $user->email])->assertSessionHas('status');

        Notification::assertNothingSent();
    }

    public function test_reset_sets_a_new_password_for_an_active_login(): void
    {
        $user = $this->make(CustomerUser::STATUS_ACTIVE);
        $token = Password::broker('customer_users')->createToken($user);

        $this->post('/user/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-Brand-New-1',
            'password_confirmation' => 'a-Brand-New-1',
        ])->assertRedirect(route('portal.login'))->assertSessionHas('status');

        $this->assertTrue(Hash::check('a-Brand-New-1', $user->fresh()->password));
    }

    public function test_reset_claims_and_activates_an_invited_login(): void
    {
        $user = $this->make(CustomerUser::STATUS_INVITED, password: null, email: 'invited@example.com');
        $token = Password::broker('customer_users')->createToken($user);

        $this->post('/user/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'first-Password-9',
            'password_confirmation' => 'first-Password-9',
        ])->assertRedirect(route('portal.login'));

        $fresh = $user->fresh();
        $this->assertSame(CustomerUser::STATUS_ACTIVE, $fresh->status);
        $this->assertTrue($fresh->canLogin());

        // The freshly-claimed login can actually authenticate.
        $this->post('/user/login', ['email' => $user->email, 'password' => 'first-Password-9'])
            ->assertRedirect(route('portal.home'));
        $this->assertAuthenticatedAs($fresh, 'portal');
    }

    public function test_reset_rejects_an_invalid_token(): void
    {
        $user = $this->make(CustomerUser::STATUS_ACTIVE);

        $this->from('/user/reset-password/whatever')->post('/user/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'a-Brand-New-1',
            'password_confirmation' => 'a-Brand-New-1',
        ])->assertSessionHasErrors('email');

        // Password unchanged.
        $this->assertTrue(Hash::check('secret-pass-123', $user->fresh()->password));
    }

    public function test_reset_page_renders_with_the_token(): void
    {
        $this->get('/user/reset-password/sometoken?email=x@example.com')
            ->assertOk()
            ->assertSee('Όρισε νέο κωδικό');
    }
}
