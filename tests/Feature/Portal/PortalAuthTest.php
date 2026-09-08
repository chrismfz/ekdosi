<?php

namespace Tests\Feature\Portal;

use App\Models\CustomerUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Customer portal — Slice 0: the auth shell only (login/logout + placeholder),
 * on the dedicated `portal` guard. The load-bearing property is GUARD ISOLATION:
 * a portal login is never an operator, and vice-versa. No customer data yet.
 */
class PortalAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The login throttle is keyed in the cache (per IP); clear it so counts
        // don't bleed across tests in one process run.
        Cache::flush();
    }

    private function active(string $email = 'pelatis@example.com', string $password = 'secret-pass-123'): CustomerUser
    {
        return CustomerUser::factory()->create([
            'email' => $email,
            'password' => Hash::make($password),
            'status' => CustomerUser::STATUS_ACTIVE,
        ]);
    }

    public function test_login_page_renders(): void
    {
        $this->get('/user/login')->assertOk()->assertSee('Είσοδος πελατών');
    }

    public function test_valid_credentials_log_in_and_record_last_login(): void
    {
        $user = $this->active();

        $res = $this->post('/user/login', ['email' => $user->email, 'password' => 'secret-pass-123']);

        $res->assertRedirect(route('portal.home'));
        $this->assertAuthenticatedAs($user, 'portal');

        $user->refresh();
        $this->assertNotNull($user->last_login_at);
        $this->assertNotNull($user->last_login_ip);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = $this->active();

        $this->from('/user/login')
            ->post('/user/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertRedirect('/user/login')
            ->assertSessionHasErrors('email');
        $this->assertGuest('portal');
    }

    public function test_unknown_email_is_rejected(): void
    {
        $this->post('/user/login', ['email' => 'nobody@example.com', 'password' => 'whatever'])
            ->assertSessionHasErrors('email');
        $this->assertGuest('portal');
    }

    public function test_invited_login_without_password_cannot_authenticate(): void
    {
        $user = CustomerUser::factory()->invited()->create(['email' => 'invited@example.com']);

        $this->post('/user/login', ['email' => $user->email, 'password' => 'anything'])
            ->assertSessionHasErrors('email');
        $this->assertGuest('portal');
    }

    public function test_suspended_login_is_blocked_even_with_correct_password(): void
    {
        $user = CustomerUser::factory()->suspended()->create([
            'email' => 'suspended@example.com',
            'password' => Hash::make('secret-pass-123'),
        ]);

        $this->post('/user/login', ['email' => $user->email, 'password' => 'secret-pass-123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest('portal');
    }

    public function test_portal_home_requires_authentication(): void
    {
        $this->get('/user')->assertRedirect(route('portal.login'));
    }

    public function test_authenticated_user_reaching_login_is_sent_home(): void
    {
        $user = $this->active();

        $this->actingAs($user, 'portal')->get('/user/login')->assertRedirect(route('portal.home'));
    }

    public function test_logout_ends_the_session(): void
    {
        $user = $this->active();
        $this->actingAs($user, 'portal');

        $this->post('/user/logout')->assertRedirect(route('portal.login'));
        $this->assertGuest('portal');
    }

    public function test_a_portal_login_is_not_an_operator(): void
    {
        $user = $this->active();
        $this->actingAs($user, 'portal');

        $this->assertAuthenticated('portal');
        $this->assertGuest('web');   // never an operator on the Filament guard
    }

    public function test_create_user_command_restores_a_soft_deleted_login(): void
    {
        $user = CustomerUser::factory()->create(['email' => 'gone@example.com']);
        $user->delete();   // soft delete — the unique(email) row still exists

        $this->artisan('portal:create-user', [
            'email' => 'gone@example.com',
            '--name' => 'Back Again',
            '--password' => 'fresh-pass-123',
        ])->assertSuccessful();

        $fresh = CustomerUser::query()->where('email', 'gone@example.com')->first();
        $this->assertNotNull($fresh);   // restored (not trashed), no unique-constraint crash
        $this->assertSame(CustomerUser::STATUS_ACTIVE, $fresh->status);
        $this->assertTrue(Hash::check('fresh-pass-123', $fresh->password));
    }

    public function test_an_operator_is_not_a_portal_user(): void
    {
        $operator = User::factory()->create();
        $this->actingAs($operator);   // default 'web' guard

        $this->assertGuest('portal');
        // And an operator session does not open the portal.
        $this->get('/user')->assertRedirect(route('portal.login'));
    }
}
