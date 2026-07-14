<?php

namespace Tests\Feature\Install;

use App\Support\Install\EnvWriter;
use App\Support\Install\InstallState;
use App\Support\Install\InstallTokenManager;
use Tests\TestCase;

/**
 * The installer's security spine, isolated: the fail-closed «installed?» oracle,
 * the filesystem token gate, and the `.env` renderer/atomic writer.
 */
class InstallSupportTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scratch = sys_get_temp_dir().'/ekdosi-install-'.bin2hex(random_bytes(4));
        @mkdir($this->scratch, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->scratch.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->scratch);
        parent::tearDown();
    }

    /**
     * InstallState with overridable marker + env paths (so we test the marker/key
     * logic in isolation, never touching real storage or a dev box's real `.env`).
     */
    private function state(string $markerPath): InstallState
    {
        return new class($markerPath, $this->scratch.'/.env-absent') extends InstallState
        {
            public function __construct(private string $marker, private string $env) {}

            public function markerPath(): string
            {
                return $this->marker;
            }

            public function envFilePath(): string
            {
                return $this->env;
            }
        };
    }

    public function test_empty_app_key_means_can_install(): void
    {
        config(['app.key' => '']);
        $state = $this->state($this->scratch.'/installed.json');

        $this->assertTrue($state->canInstall());
        $this->assertFalse($state->isInstalled());
    }

    public function test_configured_app_key_means_installed(): void
    {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $state = $this->state($this->scratch.'/installed.json');

        $this->assertTrue($state->isInstalled());
        $this->assertFalse($state->canInstall());
    }

    public function test_marker_file_locks_the_installer_even_without_a_key(): void
    {
        config(['app.key' => '']);
        $marker = $this->scratch.'/installed.json';
        $state = $this->state($marker);

        $this->assertTrue($state->canInstall());

        $state->markInstalled(['installed_at' => 'now']);

        $this->assertFileExists($marker);
        $this->assertTrue($state->isInstalled());
        $this->assertFalse($state->canInstall());
    }

    // ── token gate ────────────────────────────────────────────────────────────

    private function tokenManager(string $path): InstallTokenManager
    {
        return new class($path) extends InstallTokenManager
        {
            public function __construct(private string $p) {}

            public function path(): string
            {
                return $this->p;
            }
        };
    }

    public function test_token_issue_is_idempotent_and_verifies(): void
    {
        $mgr = $this->tokenManager($this->scratch.'/verify-token.txt');

        $token = $mgr->issue();
        $this->assertNotNull($token);
        $this->assertSame($token, $mgr->issue(), 'issue() must be idempotent');
        $this->assertSame($token, $mgr->token());

        $this->assertTrue($mgr->verify($token));
        $this->assertTrue($mgr->verify(' '.$token.' '), 'surrounding whitespace is trimmed');
        $this->assertFalse($mgr->verify('wrong'));
        $this->assertFalse($mgr->verify(null));
        $this->assertFalse($mgr->verify(''));
    }

    public function test_token_clear_removes_the_file_and_verification_then_fails(): void
    {
        $path = $this->scratch.'/verify-token.txt';
        $mgr = $this->tokenManager($path);
        $token = $mgr->issue();

        $mgr->clear();

        $this->assertFileDoesNotExist($path);
        $this->assertNull($mgr->token());
        $this->assertFalse($mgr->verify($token));
    }

    public function test_token_body_leads_with_a_comment_and_token_is_the_first_bare_line(): void
    {
        $path = $this->scratch.'/verify-token.txt';
        $mgr = $this->tokenManager($path);
        $token = $mgr->issue();

        $contents = file_get_contents($path);
        $this->assertStringStartsWith('#', $contents, 'file leads with the human explanation');
        $this->assertStringContainsString($token, $contents);
        // token() must skip the comment lines and return the bare token.
        $this->assertSame($token, $mgr->token());
    }

    // ── .env renderer / writer ─────────────────────────────────────────────────

    public function test_generate_app_key_is_a_valid_laravel_key(): void
    {
        $key = (new EnvWriter)->generateAppKey();

        $this->assertStringStartsWith('base64:', $key);
        $this->assertSame(32, strlen(base64_decode(substr($key, 7))), 'AES-256 needs 32 bytes');
    }

    public function test_render_contains_core_keys_and_prod_defaults(): void
    {
        $body = (new EnvWriter)->render([
            'app_name' => 'My Co',
            'app_env' => 'production',
            'app_key' => 'base64:abc',
            'app_url' => 'https://ekdosi.example.gr',
            'app_locale' => 'el',
            'app_timezone' => 'Europe/Athens',
            'db_host' => '127.0.0.1',
            'db_port' => '3306',
            'db_database' => 'ekdosi',
            'db_username' => 'ekdosi_user',
            'db_password' => 'p@ss word#1',
            'mail_mailer' => 'log',
            'mail_encryption' => 'null',
            'mail_from_address' => 'no-reply@example.gr',
            'mail_from_name' => 'My Co',
        ]);

        $this->assertStringContainsString('APP_KEY=base64:abc', $body);
        $this->assertStringContainsString('APP_ENV=production', $body);
        $this->assertStringContainsString('APP_DEBUG=false', $body);            // prod → false
        $this->assertStringContainsString('APP_URL=https://ekdosi.example.gr', $body);
        $this->assertStringContainsString('APP_NAME="My Co"', $body);           // space → quoted
        $this->assertStringContainsString('DB_CONNECTION=mariadb', $body);
        $this->assertStringContainsString('DB_DATABASE=ekdosi', $body);
        $this->assertStringContainsString('DB_PASSWORD="p@ss word#1"', $body);  // special chars → quoted
        $this->assertStringContainsString('LOG_STACK=daily', $body);            // prod rotation
        $this->assertStringContainsString('SESSION_SECURE_COOKIE=true', $body); // prod + https
        $this->assertStringContainsString('EKDOSI_SECRETS_PLAINTEXT_ACKNOWLEDGED=true', $body);
        // No per-tenant secrets ever leak into .env.
        $this->assertStringNotContainsString('MYDATA', $body);
        $this->assertStringNotContainsString('WHMCS', $body);
    }

    public function test_render_quotes_host_values_to_prevent_env_injection(): void
    {
        // A host field carrying whitespace/newline/# must be quoted, never spill
        // onto a second .env line (mail_host is never probed, so this is the one
        // place such a value could reach the file verbatim).
        $body = (new EnvWriter)->render([
            'app_name' => 'ekdosi', 'app_env' => 'production', 'app_key' => 'base64:abc',
            'app_url' => 'https://x.gr', 'app_locale' => 'el', 'app_timezone' => 'Europe/Athens',
            'db_host' => "127.0.0.1\nMALICIOUS=1", 'db_port' => '3306', 'db_database' => 'ekdosi',
            'db_username' => 'u', 'db_password' => '',
            'mail_mailer' => 'smtp', 'mail_host' => "smtp.evil\nAPP_DEBUG=true", 'mail_port' => '587',
            'mail_encryption' => 'tls', 'mail_from_address' => 'a@b.gr', 'mail_from_name' => 'ekdosi',
        ]);

        $this->assertStringNotContainsString("\nMALICIOUS=1", $body);
        $this->assertStringNotContainsString("\nAPP_DEBUG=true", $body);
        // The values survive, quoted (newline escaped inside the quoted string).
        $this->assertStringContainsString('DB_HOST="127.0.0.1', $body);
        $this->assertStringContainsString('MAIL_HOST="smtp.evil', $body);
    }

    public function test_render_local_env_and_plain_http_omit_prod_only_lines(): void
    {
        $body = (new EnvWriter)->render([
            'app_name' => 'ekdosi',
            'app_env' => 'local',
            'app_key' => 'base64:abc',
            'app_url' => 'http://localhost',
            'app_locale' => 'el',
            'app_timezone' => 'Europe/Athens',
            'db_host' => '127.0.0.1', 'db_port' => '3306', 'db_database' => 'ekdosi',
            'db_username' => 'u', 'db_password' => '',
            'mail_mailer' => 'log', 'mail_encryption' => 'null',
            'mail_from_address' => 'a@b.gr', 'mail_from_name' => 'ekdosi',
        ]);

        $this->assertStringContainsString('APP_DEBUG=true', $body);       // local → true
        $this->assertStringContainsString('LOG_STACK=single', $body);     // local → no rotation
        $this->assertStringNotContainsString('SESSION_SECURE_COOKIE', $body); // plain http → omit
        $this->assertStringContainsString('DB_PASSWORD=', $body);          // empty stays empty
    }

    public function test_write_is_atomic_and_leaves_no_temp_file(): void
    {
        $target = $this->scratch.'/.env';
        $writer = new class($target) extends EnvWriter
        {
            public function __construct(private string $t) {}

            public function path(): string
            {
                return $this->t;
            }
        };

        $writer->write("APP_KEY=base64:xyz\n");

        $this->assertFileExists($target);
        $this->assertStringContainsString('APP_KEY=base64:xyz', file_get_contents($target));
        // No leftover *.tmp.* siblings.
        $this->assertSame([], glob($this->scratch.'/.env.tmp.*') ?: []);
    }
}
