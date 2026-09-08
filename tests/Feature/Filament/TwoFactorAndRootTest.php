<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TwoFactorAndRootTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_implements_the_mfa_contracts(): void
    {
        $user = new User;
        $this->assertInstanceOf(HasAppAuthentication::class, $user);
        $this->assertInstanceOf(HasAppAuthenticationRecovery::class, $user);
    }

    public function test_totp_secret_and_recovery_codes_round_trip_encrypted(): void
    {
        // 2FA secrets are encrypted at rest only when the flag is on (DR default
        // = plaintext) — enable it for this round-trip-encrypted assertion.
        config(['ekdosi.secrets.encrypt_at_rest' => true]);
        $user = User::create([
            'name' => 'Op', 'email' => '2fa-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]);

        $user->saveAppAuthenticationSecret('S3CR3TBASE32');
        $user->saveAppAuthenticationRecoveryCodes(['aaaa-bbbb', 'cccc-dddd']);

        $fresh = $user->fresh();
        $this->assertSame('S3CR3TBASE32', $fresh->getAppAuthenticationSecret());
        $this->assertSame(['aaaa-bbbb', 'cccc-dddd'], $fresh->getAppAuthenticationRecoveryCodes());
        $this->assertSame($user->email, $fresh->getAppAuthenticationHolderName());

        // Stored encrypted at rest — the raw column is not the plaintext secret.
        $raw = DB::table('users')->where('id', $user->id)->value('app_authentication_secret');
        $this->assertNotSame('S3CR3TBASE32', $raw);
    }

    public function test_root_is_an_intentional_blank_placeholder(): void
    {
        // /admin ⟂ /user split: the root leaks neither surface, just a placeholder.
        $this->get('/')->assertOk()->assertDontSee('/admin')->assertDontSee('/user');
    }

    public function test_mfa_secrets_are_hidden_from_serialization(): void
    {
        $user = User::create([
            'name' => 'Op', 'email' => 'h-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]);
        $user->saveAppAuthenticationSecret('SECRET');
        $user->saveAppAuthenticationRecoveryCodes(['a', 'b']);

        $array = $user->fresh()->toArray();
        $this->assertArrayNotHasKey('app_authentication_secret', $array);
        $this->assertArrayNotHasKey('app_authentication_recovery_codes', $array);
        $this->assertArrayNotHasKey('password', $array);
    }
}
