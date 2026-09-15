<?php

namespace Tests\Feature\Install;

use App\Http\Controllers\Install\InstallController;
use App\Models\Company;
use App\Models\VatCategory;
use App\Services\Install\MariaDbConnectionTester;
use App\Services\Install\MariaDbProbeResult;
use App\Services\Portability\BundleArchive;
use App\Services\Portability\CompanyExporter;
use App\Support\Install\InstallState;
use App\Support\Install\InstallTokenManager;
use App\Support\Install\RequirementsChecker;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The web installer's «Νέα εταιρία / Εισαγωγή από .zip» branch: the mode/bundle
 * validation and the bundle-read + passphrase gate that must reject a bad file
 * BEFORE the DB is migrated. The full success path (real .env write + MariaDB
 * reconnect) is out of scope here (TEST-001) — the actual --bundle install is
 * covered at the command level in InstallCommandTest.
 */
class InstallBundleUploadTest extends TestCase
{
    use RefreshDatabase;

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

    /** A probe that reports an empty DB so the flow reaches the bundle gate. */
    private function fakeEmptyDb(): void
    {
        $this->app->instance(MariaDbConnectionTester::class, new class extends MariaDbConnectionTester
        {
            public function test(string $host, int $port, string $database, string $user, string $password): MariaDbProbeResult
            {
                return MariaDbProbeResult::emptyDatabase();
            }
        });
    }

    /** @return array<string, mixed> the common (valid) form fields. */
    private function baseForm(string $token): array
    {
        return [
            'verify_token' => $token,
            'app_name' => 'Test Co',
            'app_url' => 'https://ekdosi.test',
            'app_env' => 'production',
            'app_locale' => 'el',
            'app_timezone' => 'Europe/Athens',
            // A closed localhost port: these tests must never reach migrate, but
            // if a regression lets one through it fails FAST (connection refused),
            // not after a multi-minute TCP timeout to a non-routable host.
            'db_host' => '127.0.0.1',
            'db_port' => '1',
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
        ];
    }

    private function sealedBundleUpload(): UploadedFile
    {
        $source = Company::create([
            'name' => 'MyIP OE', 'slug' => 'myip', 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'afm' => '800561849',
            // A non-null secret so the sealed bundle actually carries ciphertext —
            // otherwise open() has nothing to decrypt and a wrong passphrase never
            // fails (the gate would pass and the flow would reach migrate).
            'gsis_password' => 'a-real-secret',
        ]);
        VatCategory::create(['company_id' => $source->id, 'description' => 'ΦΠΑ 24%', 'rate' => 24, 'is_default' => true]);

        $bundle = app(CompanyExporter::class)->build($source, 'passphrase', 'p@ss');
        $tmp = tempnam(sys_get_temp_dir(), 'ekb').'.zip';
        app(BundleArchive::class)->write($tmp, $bundle);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return UploadedFile::fake()->createWithContent('myip.zip', $bytes);
    }

    public function test_import_mode_requires_a_bundle_file(): void
    {
        config(['app.key' => '']); // installer POST runs session/CSRF-free (see InstallRequirementsTest)
        $token = app(InstallTokenManager::class)->issue();

        $payload = $this->baseForm($token) + ['install_mode' => 'import'];

        $this->post('/install', $payload)->assertStatus(422);
        // Fails at validation, before the DB is touched.
        $this->assertTrue(app(InstallState::class)->canInstall());
    }

    public function test_new_mode_requires_a_company_name(): void
    {
        config(['app.key' => '']);
        $token = app(InstallTokenManager::class)->issue();

        // «new» mode, but no company_name → validation refuses.
        $payload = $this->baseForm($token) + ['install_mode' => 'new', 'company_country' => 'GR'];

        $this->post('/install', $payload)->assertStatus(422);
        $this->assertTrue(app(InstallState::class)->canInstall());
    }

    public function test_read_header_requires_company_json_so_the_gate_matches_the_full_read(): void
    {
        // A .zip with a valid manifest + secrets but NO company.json: the gate
        // (readHeader) must reject it up-front, else the command's full read()
        // would fail AFTER migrate — the migrated-empty-DB the gate exists to stop.
        $tmp = tempnam(sys_get_temp_dir(), 'ekb').'.zip';
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::CREATE);
        $zip->addFromString('manifest.json', (string) json_encode(['company' => ['slug' => 'x']]));
        $zip->addFromString('secrets.json', (string) json_encode(['mode' => 'raw', 'values' => []]));
        $zip->close();

        $threw = false;
        try {
            app(BundleArchive::class)->readHeader($tmp);
        } catch (\RuntimeException) {
            $threw = true;
        } finally {
            @unlink($tmp);
        }

        $this->assertTrue($threw, 'readHeader must reject a bundle missing company.json');
    }

    public function test_read_header_rejects_a_present_but_corrupt_company_json(): void
    {
        // Existence alone isn't enough: a present-but-corrupt company.json must
        // also fail the gate (else it slips to migrate then fails in read()).
        $tmp = tempnam(sys_get_temp_dir(), 'ekb').'.zip';
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::CREATE);
        $zip->addFromString('manifest.json', (string) json_encode(['company' => ['slug' => 'x']]));
        $zip->addFromString('secrets.json', (string) json_encode(['mode' => 'raw', 'values' => []]));
        $zip->addFromString('company.json', 'not-json-at-all');
        $zip->close();

        $threw = false;
        try {
            app(BundleArchive::class)->readHeader($tmp);
        } catch (\RuntimeException) {
            $threw = true;
        } finally {
            @unlink($tmp);
        }

        $this->assertTrue($threw, 'readHeader must reject a corrupt company.json, not just an absent one');
    }

    public function test_read_rejects_a_corrupt_table_entry_instead_of_importing_it_empty(): void
    {
        // A corrupt setup/data table must ERROR on restore, never silently import
        // as an empty table (silent data loss on a legal-document restore).
        $tmp = tempnam(sys_get_temp_dir(), 'ekb').'.zip';
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::CREATE);
        $zip->addFromString('manifest.json', (string) json_encode(['company' => ['slug' => 'x']]));
        $zip->addFromString('company.json', (string) json_encode(['slug' => 'x']));
        $zip->addFromString('secrets.json', (string) json_encode(['mode' => 'raw', 'values' => []]));
        $zip->addFromString('setup/invoice_types.json', '{ this is not valid json');
        $zip->close();

        $threw = false;
        try {
            app(BundleArchive::class)->read($tmp);
        } catch (\RuntimeException) {
            $threw = true;
        } finally {
            @unlink($tmp);
        }

        $this->assertTrue($threw, 'read() must reject a corrupt table entry, not import it as empty');
    }

    public function test_import_mode_rejects_a_sealed_bundle_with_a_wrong_passphrase_before_migrating(): void
    {
        $this->fakeEmptyDb();
        // Build the bundle with a real APP_KEY (the exporter reads a secret
        // column), THEN blank it so the installer POST runs session/CSRF-free.
        $file = $this->sealedBundleUpload();
        config(['app.key' => '']);
        $token = app(InstallTokenManager::class)->issue();

        $payload = $this->baseForm($token) + [
            'install_mode' => 'import',
            'bundle_passphrase' => 'WRONG-PASSPHRASE',
            'bundle' => $file,
        ];

        $this->post('/install', $payload)
            ->assertStatus(422)
            ->assertSee('δεν διαβάζεται ή το συνθηματικό είναι λάθος');

        // The passphrase gate fires BEFORE migrate — nothing committed.
        $this->assertTrue(app(InstallState::class)->canInstall());
    }

    public function test_repointing_the_db_neutralises_a_boot_bound_sqlite_permission_cache(): void
    {
        // Reproduce the installer's pre-.env reality: Spatie's permission cache is
        // wired (at framework boot) to a database store on a sqlite connection
        // whose file does not exist. Repointing database.default to MariaDB fixes
        // the DB connection but NOT this already-resolved cache — so bundle-import
        // role provisioning committed the company to MariaDB, then aborted on
        // `delete from cache …` against the dead sqlite file, BEFORE .env was
        // written. That was the operator-facing failure.
        config([
            'cache.default' => 'database',
            'permission.cache.store' => 'default',
            'cache.stores.database.connection' => 'install_dead_sqlite',
            'database.connections.install_dead_sqlite' => [
                'driver' => 'sqlite',
                'database' => '/nonexistent/ekdosi-install-'.uniqid().'.sqlite',
                'foreign_key_constraints' => false,
            ],
        ]);
        app('cache')->forgetDriver('database');
        app(PermissionRegistrar::class)->initializeCache();

        // Precondition: flushing the permission cache hits the dead sqlite and
        // throws — exactly the error the operator saw.
        $threw = false;
        try {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } catch (\Throwable) {
            $threw = true;
        }
        $this->assertTrue($threw, 'precondition: the permission cache must be bound to the dead sqlite store');

        // Run the installer's cache neutralisation (the fix).
        $controller = app(InstallController::class);
        (new \ReflectionMethod($controller, 'resetBootstrappedCaches'))->invoke($controller);

        // The permission cache is now the in-memory array store: the flush is a
        // no-op that touches no database, so role provisioning can complete and
        // the install proceeds to write .env.
        $this->assertInstanceOf(ArrayStore::class, app(PermissionRegistrar::class)->getCacheStore());
        app(PermissionRegistrar::class)->forgetCachedPermissions(); // must not throw
    }
}
