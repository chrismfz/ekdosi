<?php

namespace Tests\Feature\Install;

use App\Support\Install\InstallState;
use App\Support\Install\InstallTokenManager;
use App\Support\Install\RequirementsChecker;
use Tests\TestCase;

/**
 * The installer preflight, end-to-end through the wizard:
 *  - a blocking requirement renders the «Έλεγχος συστήματος» card, disables the
 *    submit button, and (defence-in-depth) makes a direct POST refuse BEFORE
 *    the DB is touched;
 *  - a healthy host shows the all-clear and leaves the form usable.
 *
 * Pristine state is simulated exactly as {@see InstallGuardTest}.
 */
class InstallRequirementsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
    }

    protected function tearDown(): void
    {
        $token = storage_path('app/install/verify-token.txt');
        if (is_file($token)) {
            @unlink($token);
        }
        parent::tearDown();
    }

    private function bindChecker(ConfigurableRequirementsChecker $checker): void
    {
        $this->app->instance(RequirementsChecker::class, $checker);
    }

    public function test_wizard_shows_requirements_and_all_clear_on_a_healthy_host(): void
    {
        config(['app.key' => '']);
        $this->bindChecker(new ConfigurableRequirementsChecker);

        $this->get('/install')
            ->assertOk()
            ->assertSee('Έλεγχος συστήματος')
            ->assertSee('Όλες οι υποχρεωτικές απαιτήσεις καλύπτονται');
    }

    public function test_a_blocking_requirement_disables_the_form(): void
    {
        config(['app.key' => '']);
        $checker = new ConfigurableRequirementsChecker;
        $checker->absentExtensions = ['soap'];
        $this->bindChecker($checker);

        $this->get('/install')
            ->assertOk()
            ->assertSee('Επέκταση PHP: soap')
            ->assertSee('Αναζήτηση ΑΦΜ/GSIS σε πελάτες &amp; προμηθευτές', false)
            ->assertSee('Το κουμπί «Εγκατάσταση» είναι απενεργοποιημένο', false)
            ->assertSee('κάλυψε πρώτα τις υποχρεωτικές απαιτήσεις');
    }

    public function test_post_is_refused_when_a_requirement_blocks_before_touching_the_db(): void
    {
        config(['app.key' => '']);

        $token = app(InstallTokenManager::class)->issue();

        $checker = new ConfigurableRequirementsChecker;
        $checker->absentExtensions = ['pdo_mysql'];
        $this->bindChecker($checker);

        $this->post('/install', $this->validPayload($token))
            ->assertStatus(422)
            ->assertSee('δεν πληροί τις ελάχιστες απαιτήσεις');

        // Nothing committed — still installable.
        $this->assertTrue(app(InstallState::class)->canInstall());
    }

    public function test_an_unwritable_app_root_refuses_the_post_before_the_db_is_touched(): void
    {
        config(['app.key' => '']);

        $token = app(InstallTokenManager::class)->issue();

        // OPS-002: the installer writes .env in the app root as its LAST step,
        // AFTER migrate + ekdosi:install. An unwritable root must be caught at
        // preflight so it can NEVER leave a built DB with no .env.
        $checker = new ConfigurableRequirementsChecker;
        $checker->envWritable = false;
        $this->bindChecker($checker);

        $this->post('/install', $this->validPayload($token))
            ->assertStatus(422)
            ->assertSee('δεν πληροί τις ελάχιστες απαιτήσεις');

        // Nothing committed — still installable (no half-install to clean up).
        $this->assertTrue(app(InstallState::class)->canInstall());
    }

    /** A valid submission bar the environment (which the checker double controls). */
    private function validPayload(string $token): array
    {
        return [
            'verify_token' => $token,
            'app_name' => 'Test Co',
            'app_url' => 'https://ekdosi.test',
            'app_env' => 'production',
            'app_locale' => 'el',
            'app_timezone' => 'Europe/Athens',
            'db_host' => '10.255.255.1',
            'db_port' => '3306',
            'db_database' => 'ekdosi',
            'db_username' => 'ekdosi',
            'db_password' => 'secret',
            'mail_mailer' => 'log',
            'mail_encryption' => 'null',
            'mail_from_address' => 'no-reply@ekdosi.test',
            'mail_from_name' => 'Test Co',
            'admin_name' => 'Admin',
            'admin_email' => 'admin@ekdosi.test',
            'admin_password' => 'supersecret',
            'admin_password_confirmation' => 'supersecret',
            'company_name' => 'Test Co ΑΕ',
            'company_country' => 'GR',
        ];
    }
}
