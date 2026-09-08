<?php

namespace Tests\Feature\Install;

use App\Services\Install\MariaDbConnectionTester;
use App\Support\Install\InstallState;
use App\Support\Install\InstallTokenManager;
use App\Support\Install\RequirementsChecker;
use PDOException;
use Tests\TestCase;

/**
 * The EnsureInstalled global gate + the wizard's fail-closed behaviour:
 *  - pristine host (no APP_KEY) → every request routes into /install;
 *  - configured host (APP_KEY set, the default test state) → /install is inert.
 *
 * «Pristine» is simulated by blanking config('app.key') for the request; the
 * marker file is removed first so it can't mask the key check.
 */
class InstallGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Bind an InstallState whose `.env` signal points at an absent path, so a
        // real `.env` on a dev box can't mask the key-based pristine simulation.
        // «Installed?» is then driven purely by config('app.key') + the marker.
        $this->app->instance(InstallState::class, new class extends InstallState
        {
            public function envFilePath(): string
            {
                return storage_path('app/install/__never__.env');
            }
        });

        // Never let a stray marker from another run mask the key-based gate.
        $marker = app(InstallState::class)->markerPath();
        if (is_file($marker)) {
            @unlink($marker);
        }

        // These tests exercise the token/DB gates, not the environment preflight —
        // pin a healthy checker so ambient extension gaps can't inject a blocker
        // and short-circuit a POST before the code path under test.
        $this->app->instance(RequirementsChecker::class, new ConfigurableRequirementsChecker);
    }

    protected function tearDown(): void
    {
        // The wizard's show() issues a token file — clean it up.
        $token = storage_path('app/install/verify-token.txt');
        if (is_file($token)) {
            @unlink($token);
        }
        parent::tearDown();
    }

    public function test_pristine_host_redirects_a_normal_request_to_install(): void
    {
        config(['app.key' => '']);

        $this->get('/admin')->assertRedirect('/install');
        $this->get('/')->assertRedirect('/install');
    }

    public function test_pristine_host_serves_the_wizard(): void
    {
        config(['app.key' => '']);

        $this->get('/install')
            ->assertOk()
            ->assertSee('Επιβεβαίωση πρόσβασης')
            ->assertSee('Βάση δεδομένων');
    }

    public function test_health_check_is_allowed_on_a_pristine_host(): void
    {
        config(['app.key' => '']);

        // /up must not be swallowed by the installer redirect.
        $this->get('/up')->assertOk();
    }

    public function test_installed_host_makes_the_installer_inert(): void
    {
        // Default test env HAS an APP_KEY → the app is «installed».
        $this->assertNotSame('', trim((string) config('app.key')));

        $this->get('/install')->assertRedirect('/admin');
    }

    public function test_run_and_test_db_refuse_when_already_installed(): void
    {
        // POSTs to the installer must 302 to /admin once configured (middleware
        // inert-mode), never execute.
        $this->post('/install/test-db', [])->assertRedirect('/admin');
        $this->post('/install', [])->assertRedirect('/admin');
    }

    public function test_test_db_rejects_a_bad_token_on_a_pristine_host(): void
    {
        config(['app.key' => '']);

        // No valid token has been issued for this call → any input is wrong.
        $this->postJson('/install/test-db', ['verify_token' => 'nope', 'db_host' => '127.0.0.1'])
            ->assertStatus(403)
            ->assertJson(['ok' => false, 'reason' => 'token']);
    }

    public function test_run_rejects_a_bad_token_and_redisplays_the_wizard(): void
    {
        config(['app.key' => '']);

        $this->post('/install', ['verify_token' => 'nope'])
            ->assertStatus(422)
            ->assertSee('κωδικός επιβεβαίωσης');
    }

    public function test_valid_token_but_unreachable_db_redisplays_and_stays_uninstalled(): void
    {
        config(['app.key' => '']);

        $token = app(InstallTokenManager::class)->issue();

        // Make the DB probe fail instantly (no real socket / timeout).
        $this->app->bind(MariaDbConnectionTester::class, fn () => new MariaDbConnectionTester(
            fn (string $dsn, string $u, string $p) => throw new PDOException("SQLSTATE[HY000] [2002] Can't connect to MySQL server"),
        ));

        $this->post('/install', $this->validPayload($token))
            ->assertStatus(422)
            ->assertSee('Η σύνδεση στη βάση απέτυχε');

        // Nothing committed: no marker, still installable.
        $this->assertTrue(app(InstallState::class)->canInstall());
    }

    public function test_existing_ekdosi_db_is_refused_without_the_override_checkbox(): void
    {
        config(['app.key' => '']);

        $token = app(InstallTokenManager::class)->issue();

        // Probe reports the DB already holds an ekdosi admin.
        $this->bindProbeDb(['users' => 1, 'companies' => 1]);

        $this->post('/install', $this->validPayload($token))
            ->assertStatus(422)
            ->assertSee('Συνέχεια σε μη-κενή βάση');

        $this->assertTrue(app(InstallState::class)->canInstall());
    }

    public function test_foreign_non_empty_db_is_refused_without_the_override_checkbox(): void
    {
        config(['app.key' => '']);

        $token = app(InstallTokenManager::class)->issue();

        // A populated foreign DB (no `users` table). Must NOT be auto-migrated.
        $this->bindProbeDb(['tblinvoices' => 5, 'tblclients' => 3]);

        $this->post('/install', $this->validPayload($token))
            ->assertStatus(422)
            ->assertSee('Συνέχεια σε μη-κενή βάση');

        $this->assertTrue(app(InstallState::class)->canInstall());
    }

    /**
     * Bind the DB tester to a sqlite stand-in with the given tables.
     *
     * @param  array<string, int>  $tables
     */
    private function bindProbeDb(array $tables): void
    {
        $this->app->bind(MariaDbConnectionTester::class, fn () => new MariaDbConnectionTester(
            function (string $dsn, string $u, string $p) use ($tables): \PDO {
                $pdo = new \PDO('sqlite::memory:');
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                foreach ($tables as $table => $rows) {
                    $pdo->exec("CREATE TABLE {$table} (id INTEGER)");
                    for ($i = 0; $i < $rows; $i++) {
                        $pdo->exec("INSERT INTO {$table} (id) VALUES ({$i})");
                    }
                }

                return $pdo;
            },
        ));
    }

    /** A fully valid wizard submission (bar the DB, which the test stubs). */
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
