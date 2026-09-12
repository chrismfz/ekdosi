<?php

namespace Tests\Feature\Security;

use App\Filament\Pages\MySessions;
use App\Models\Company;
use App\Models\CustomerUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Security-hardening pack: app-wide password policy, safe response headers,
 * portal login enumeration-resistance, and self-service session management.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    // ── #4 Password policy ─────────────────────────────────────────────────
    public function test_password_defaults_enforce_a_minimum_length(): void
    {
        // Password::defaults() is defined in AppServiceProvider (min 8; the
        // breach check is skipped under the test runner, so this stays offline).
        $this->assertTrue(
            Validator::make(['p' => 'abcdefg'], ['p' => [Password::defaults()]])->fails(),
            'A 7-char password must be rejected by the default policy.'
        );
        $this->assertFalse(
            Validator::make(['p' => 'abcdefgh'], ['p' => [Password::defaults()]])->fails(),
            'An 8-char password must pass the default policy in tests (no network check).'
        );
    }

    // ── #7 Security headers ────────────────────────────────────────────────
    public function test_safe_security_headers_are_present(): void
    {
        $response = $this->get('/user/login');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    // ── #6 Portal login enumeration resistance ─────────────────────────────
    public function test_portal_login_failure_is_generic_for_unknown_and_wrong_password(): void
    {
        $generic = 'Λάθος email ή κωδικός.';

        // Unknown e-mail → generic error (and the timing-equalizer path runs
        // without crashing).
        $this->post('/user/login', ['email' => 'ghost-'.uniqid().'@nope.test', 'password' => 'whatever12'])
            ->assertSessionHasErrors(['email' => $generic]);

        // Existing account, wrong password → the SAME generic error (no signal
        // that the address is registered).
        $user = CustomerUser::create([
            'name' => 'C', 'email' => 'known-'.uniqid().'@test.local',
            'password' => bcrypt('the-right-password'), 'status' => CustomerUser::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $this->post('/user/login', ['email' => $user->email, 'password' => 'the-wrong-password'])
            ->assertSessionHasErrors(['email' => $generic]);
    }

    // ── #8 «Οι συνεδρίες μου» ───────────────────────────────────────────────
    private function insertSession(string $id, int $userId, string $guard): void
    {
        $key = 'login_'.$guard.'_'.sha1(SessionGuard::class);
        $attrs = [$key => $userId, '_token' => 'x'];

        // Encode the payload the SAME way the app's session store does (json by
        // default per config/session.php) so the test exercises the real decode
        // path — a serialize() fixture would mask a json/php mismatch in prod.
        $payload = config('session.serialization', 'php') === 'json'
            ? json_encode($attrs)
            : serialize($attrs);

        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '203.0.113.5',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120',
            'payload' => base64_encode($payload),
            'last_activity' => time(),
        ]);
    }

    private function enterPanel(User $user): Company
    {
        $tenant = Company::create([
            'name' => 'Sess OE', 'slug' => 'sess-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
            'afm' => '800000000',
        ]);
        $user->companies()->attach($tenant->id);

        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($tenant);
        config(['session.driver' => 'database']); // test env defaults to array

        return $tenant;
    }

    public function test_my_sessions_lists_only_own_web_guard_sessions(): void
    {
        $me = User::create(['name' => 'Me', 'email' => 'me-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $other = User::create(['name' => 'Other', 'email' => 'ot-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $this->enterPanel($me);

        $this->insertSession('own-web', $me->id, 'web');       // mine, web → shown
        $this->insertSession('own-portal', $me->id, 'portal'); // mine, portal → hidden
        $this->insertSession('other-web', $other->id, 'web');  // not mine → hidden

        $ids = array_column((new MySessions)->sessions(), 'id');

        $this->assertContains('own-web', $ids);
        $this->assertNotContains('own-portal', $ids);
        $this->assertNotContains('other-web', $ids);
    }

    public function test_my_sessions_decodes_encrypted_payloads(): void
    {
        // With SESSION_ENCRYPT on, the payload is base64(encrypt(serialize(attrs)))
        // — the decode must decrypt it (encrypter default serialize=true) before
        // reading the guard key, or the whole page is silently empty in prod.
        $me = User::create(['name' => 'Me', 'email' => 'enc-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $this->enterPanel($me);
        config(['session.encrypt' => true]);

        $key = 'login_web_'.sha1(SessionGuard::class);
        $attrs = [$key => $me->id, '_token' => 'x'];
        $serialized = config('session.serialization', 'php') === 'json' ? json_encode($attrs) : serialize($attrs);

        DB::table('sessions')->insert([
            'id' => 'enc-web',
            'user_id' => $me->id,
            'ip_address' => '203.0.113.9',
            'user_agent' => 'Mozilla/5.0 Chrome/120',
            'payload' => base64_encode(Crypt::encrypt($serialized)), // encrypter serialize=true
            'last_activity' => time(),
        ]);

        $this->assertContains('enc-web', array_column((new MySessions)->sessions(), 'id'));
    }

    public function test_revoke_deletes_own_web_session_but_refuses_portal_and_foreign(): void
    {
        $me = User::create(['name' => 'Me', 'email' => 'me2-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $other = User::create(['name' => 'Other', 'email' => 'ot2-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $this->enterPanel($me);

        $this->insertSession('mine-web', $me->id, 'web');
        $this->insertSession('mine-portal', $me->id, 'portal');
        $this->insertSession('foreign-web', $other->id, 'web');

        Livewire::test(MySessions::class)
            ->call('revoke', 'mine-web')
            ->call('revoke', 'mine-portal')
            ->call('revoke', 'foreign-web');

        // Only my own web session is gone; the portal row and the other user's
        // row are untouched (guard + ownership double-gate).
        $this->assertDatabaseMissing('sessions', ['id' => 'mine-web']);
        $this->assertDatabaseHas('sessions', ['id' => 'mine-portal']);
        $this->assertDatabaseHas('sessions', ['id' => 'foreign-web']);
    }

    public function test_logout_other_devices_clears_only_my_other_web_sessions(): void
    {
        $me = User::create([
            'name' => 'Me', 'email' => 'me3-'.uniqid().'@t.local', 'password' => bcrypt('secret-password'),
        ]);
        $other = User::create(['name' => 'O', 'email' => 'ot3-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $this->enterPanel($me);

        $this->insertSession('me-web-A', $me->id, 'web');
        $this->insertSession('me-portal', $me->id, 'portal');
        $this->insertSession('other-web', $other->id, 'web');

        Livewire::test(MySessions::class)
            ->callAction('logout_others', ['password' => 'secret-password']);

        $this->assertDatabaseMissing('sessions', ['id' => 'me-web-A']);  // other web session of mine → gone
        $this->assertDatabaseHas('sessions', ['id' => 'me-portal']);     // my portal session → kept
        $this->assertDatabaseHas('sessions', ['id' => 'other-web']);     // someone else's → kept
    }
}
