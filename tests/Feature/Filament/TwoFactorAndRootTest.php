<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use App\Support\TwoFactor\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Facades\Filament;
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

    public function test_enrolment_qr_is_a_single_valid_data_uri_not_double_wrapped(): void
    {
        // Regression for the Filament v5.8 double-encode bug: on hosts WITHOUT
        // the imagick extension (our prod runs gd-only), Filament re-wraps the
        // data: URI that google2fa-qrcode v4 already returns, so the profile
        // «Set up authenticator app» QR <img> decodes to another data-URI STRING
        // instead of an image and renders blank (only alt text shows). Our
        // App\Support\TwoFactor\AppAuthentication override must hand back ONE
        // data: URI whose payload is the actual image markup.
        $user = User::create([
            'name' => 'Op', 'email' => 'qr-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $provider = AppAuthentication::make();
        $uri = $provider->generateQrCodeDataUri($provider->generateSecret());

        $this->assertStringStartsWith('data:image/', $uri);

        [, $base64] = explode(',', $uri, 2);
        $payload = base64_decode($base64);

        // The decoded payload must be real image markup — NOT another data: URI
        // (which is precisely what the un-patched Filament path produced).
        $this->assertStringNotContainsString('data:', $payload, 'QR data URI is double-wrapped');

        // On this gd-only host the SVG back-end is used, so the payload is SVG.
        if (! extension_loaded('imagick')) {
            $this->assertStringContainsString('<svg', $payload, 'QR payload is not SVG markup');
        }
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
