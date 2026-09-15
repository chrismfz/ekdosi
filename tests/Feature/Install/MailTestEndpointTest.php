<?php

namespace Tests\Feature\Install;

use App\Services\Install\MailConnectionTester;
use App\Services\Install\SmtpProbe;
use App\Support\Install\InstallState;
use App\Support\Install\InstallTokenManager;
use App\Support\Install\RequirementsChecker;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * POST /install/test-mail — the token gate + wiring of the «Δοκιμή email» probe.
 * The probe logic itself is covered in MailConnectionTesterTest; here we bind a
 * MailConnectionTester over a fake SmtpProbe and check the endpoint contract.
 */
class MailTestEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A pristine host: no marker, and an env path that never exists so
        // InstallState::canInstall() stays true (same trick as InstallBundleUploadTest).
        $this->app->instance(InstallState::class, new class extends InstallState
        {
            public function envFilePath(): string
            {
                return storage_path('app/install/__never__.env');
            }
        });
        $marker = app(InstallState::class)->markerPath();
        if (is_file($marker)) {
            @unlink($marker);
        }

        $this->app->instance(RequirementsChecker::class, new ConfigurableRequirementsChecker);
    }

    protected function tearDown(): void
    {
        $token = storage_path('app/install/verify-token.txt');
        if (is_file($token)) {
            @unlink($token);
        }
        parent::tearDown();
    }

    /** Bind a tester whose probe records how it was driven, and succeeds. */
    private function bindSucceedingProbe(): object
    {
        $probe = new class implements SmtpProbe
        {
            public bool $started = false;

            public ?Email $sent = null;

            public function start(): void
            {
                $this->started = true;
            }

            public function send(Email $email): void
            {
                $this->sent = $email;
            }

            public function stop(): void {}
        };

        $this->app->instance(
            MailConnectionTester::class,
            new MailConnectionTester(fn (): SmtpProbe => $probe),
        );

        return $probe;
    }

    public function test_wrong_token_is_rejected_403(): void
    {
        config(['app.key' => '']); // installer POST runs session/CSRF-free
        app(InstallTokenManager::class)->issue();
        $this->bindSucceedingProbe();

        $this->postJson('/install/test-mail', [
            'verify_token' => 'WRONG',
            'mail_host' => 'mail.example.gr',
            'mail_port' => 587,
            'mail_encryption' => 'tls',
        ])->assertStatus(403)->assertJson(['ok' => false, 'reason' => 'token']);
    }

    public function test_valid_token_runs_the_probe_and_returns_connected(): void
    {
        config(['app.key' => '']);
        $token = app(InstallTokenManager::class)->issue();
        $probe = $this->bindSucceedingProbe();

        $this->postJson('/install/test-mail', [
            'verify_token' => $token,
            'mail_host' => 'mail.example.gr',
            'mail_port' => 587,
            'mail_encryption' => 'tls',
            'mail_username' => 'user',
            'mail_password' => 'pass',
            // no test_recipient → connect+auth only
        ])->assertOk()->assertJson(['ok' => true, 'reason' => 'connected']);

        $this->assertTrue($probe->started);
        $this->assertNull($probe->sent);
    }

    public function test_valid_token_with_a_recipient_sends_a_test_message(): void
    {
        config(['app.key' => '']);
        $token = app(InstallTokenManager::class)->issue();
        $probe = $this->bindSucceedingProbe();

        $this->postJson('/install/test-mail', [
            'verify_token' => $token,
            'mail_host' => 'mail.example.gr',
            'mail_port' => 587,
            'mail_encryption' => 'tls',
            'mail_username' => 'user',
            'mail_password' => 'pass',
            'mail_from_address' => 'no-reply@example.gr',
            'mail_from_name' => 'ekdosi',
            'test_recipient' => 'me@example.gr',
        ])->assertOk()->assertJson(['ok' => true, 'reason' => 'sent']);

        $this->assertNotNull($probe->sent);
        $this->assertSame('me@example.gr', $probe->sent->getTo()[0]->getAddress());
    }
}
