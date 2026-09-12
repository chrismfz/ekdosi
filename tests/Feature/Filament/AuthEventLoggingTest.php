<?php

namespace Tests\Feature\Filament;

use App\Models\AuthEvent;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The auth/security log: App\Listeners\RecordAuthEvent records login / logout /
 * failed attempts on both guards into `auth_events`, and stamps last_login on
 * operator accounts. The whole point is spotting recon/brute-force — incl. tries
 * against NON-EXISTENT usernames — so a failed attempt with no user must still
 * capture the tried identifier + IP. The password is NEVER stored.
 */
class AuthEventLoggingTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@test.local', 'password' => bcrypt('secret-pw'),
        ]);
    }

    public function test_login_records_a_row_and_stamps_last_login(): void
    {
        $user = $this->user();
        $this->assertNull($user->last_login_at);

        event(new Login('web', $user, false));

        $this->assertDatabaseHas('auth_events', [
            'event' => 'login',
            'guard' => 'web',
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->last_login_at);
        $this->assertNotNull($fresh->last_login_ip);
    }

    public function test_logout_records_a_row(): void
    {
        $user = $this->user();

        event(new Logout('web', $user));

        $this->assertDatabaseHas('auth_events', [
            'event' => 'logout',
            'guard' => 'web',
            'user_id' => $user->id,
        ]);
    }

    public function test_failed_attempt_on_a_nonexistent_username_is_captured_without_the_password(): void
    {
        // No user (unknown username) — the exact recon case we need visibility on.
        event(new Failed('web', null, [
            'email' => 'ghost@attacker.test',
            'password' => 'sup3r-s3cret',
        ]));

        $row = AuthEvent::query()->where('event', 'failed')->firstOrFail();

        $this->assertSame('ghost@attacker.test', $row->email);
        $this->assertNull($row->user_id);
        $this->assertNotNull($row->ip_address);

        // The password must NEVER be persisted, in any column.
        $raw = (array) DB::table('auth_events')->where('id', $row->id)->first();
        foreach ($raw as $value) {
            $this->assertStringNotContainsStringIgnoringCase('sup3r-s3cret', (string) $value);
        }
    }

    public function test_failed_attempt_on_an_existing_user_keeps_the_user_id(): void
    {
        $user = $this->user();

        event(new Failed('web', $user, ['email' => $user->email, 'password' => 'wrong']));

        $this->assertDatabaseHas('auth_events', [
            'event' => 'failed',
            'user_id' => $user->id,
            'email' => $user->email,
        ]);
    }

    public function test_portal_guard_is_recorded_and_does_not_touch_operator_last_login(): void
    {
        // A failed attempt on the customer portal — guard preserved, no operator
        // last_login side effect (that column is for /admin accounts).
        event(new Failed('portal', null, ['email' => 'client@example.test', 'password' => 'x']));

        $this->assertDatabaseHas('auth_events', [
            'event' => 'failed',
            'guard' => 'portal',
            'email' => 'client@example.test',
        ]);
    }
}
