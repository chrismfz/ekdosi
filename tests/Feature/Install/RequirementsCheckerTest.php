<?php

namespace Tests\Feature\Install;

use App\Support\Install\Requirement;
use App\Support\Install\RequirementsChecker;
use Tests\TestCase;

/**
 * Unit coverage for the installer preflight ({@see RequirementsChecker}):
 * hard requirements block, optional ones only warn, and the ini/version
 * thresholds behave. Everything is driven through the seam-overriding
 * {@see ConfigurableRequirementsChecker} so it never depends on the CI runtime.
 */
class RequirementsCheckerTest extends TestCase
{
    /** @return array<string, Requirement> keyed by requirement key */
    private function byKey(RequirementsChecker $checker): array
    {
        $out = [];
        foreach ($checker->check() as $requirement) {
            $out[$requirement->key] = $requirement;
        }

        return $out;
    }

    public function test_a_healthy_host_has_no_blockers_and_all_required_pass(): void
    {
        $checker = new ConfigurableRequirementsChecker;

        $this->assertFalse($checker->hasBlockers($checker->check()));

        foreach ($checker->check() as $requirement) {
            if ($requirement->required) {
                $this->assertTrue($requirement->passed, "required {$requirement->key} should pass on a healthy host");
            }
        }
    }

    /**
     * Since the v2.0.2 migration squash, a fresh install builds the schema by
     * LOADING database/schema/mariadb-schema.sql, and Laravel shells out to the
     * `mariadb` client binary to do it (MariaDbSchemaState::load()). A host
     * without that binary used to sail through a green preflight and then die
     * mid-migrate — and because loadSchemaState() deletes the migration
     * repository BEFORE loading, the retry failed identically. Block instead.
     */
    public function test_a_missing_mariadb_client_binary_blocks(): void
    {
        $checker = new ConfigurableRequirementsChecker;
        $checker->dbClient = false;

        $this->assertTrue($checker->hasBlockers($checker->check()));
        $this->assertTrue($this->byKey($checker)['db_client']->blocks());
    }

    /** Same reason: no proc_open → no shell-out → no schema load → no install. */
    public function test_disabled_proc_open_blocks(): void
    {
        $checker = new ConfigurableRequirementsChecker;
        $checker->procOpen = false;

        $this->assertTrue($checker->hasBlockers($checker->check()));
        $this->assertTrue($this->byKey($checker)['proc_open']->blocks());
    }

    /**
     * With proc_open off we cannot probe the PATH at all, so the db_client row
     * must not tell the operator to install a client that may already be there
     * (they would install it, re-run, and still see red).
     */
    public function test_the_db_client_row_says_it_could_not_be_checked_when_proc_open_is_off(): void
    {
        $checker = new ConfigurableRequirementsChecker;
        $checker->procOpen = false;
        $checker->dbClient = true;

        $row = $this->byKey($checker)['db_client'];

        $this->assertStringContainsString('ΔΕΝ ΕΛΕΓΧΘΗΚΕ', $row->detail);
        $this->assertStringNotContainsString('apt install', $row->fix);
    }

    public function test_a_missing_required_extension_blocks(): void
    {
        $checker = new ConfigurableRequirementsChecker;
        $checker->absentExtensions = ['soap'];

        $reqs = $this->byKey($checker);

        $this->assertTrue($reqs['ext_soap']->blocks(), 'missing soap must block');
        $this->assertSame('error', $reqs['ext_soap']->severity());
        $this->assertTrue($checker->hasBlockers($checker->check()));
    }

    public function test_a_missing_optional_extension_only_warns(): void
    {
        $checker = new ConfigurableRequirementsChecker;
        $checker->absentExtensions = ['pdo_firebird'];

        $reqs = $this->byKey($checker);

        $this->assertFalse($reqs['ext_pdo_firebird']->passed);
        $this->assertFalse($reqs['ext_pdo_firebird']->blocks(), 'firebird is optional — must not block');
        $this->assertSame('warn', $reqs['ext_pdo_firebird']->severity());
        $this->assertFalse($checker->hasBlockers($checker->check()), 'an optional miss alone is not a blocker');
    }

    public function test_gd_is_optional_because_qr_degrades_gracefully(): void
    {
        $checker = new ConfigurableRequirementsChecker;
        $checker->absentExtensions = ['gd'];

        $reqs = $this->byKey($checker);

        $this->assertFalse($reqs['ext_gd']->passed);
        $this->assertFalse($reqs['ext_gd']->blocks(), 'gd missing must NOT block — the invoice still issues without the QR image');
        $this->assertSame('warn', $reqs['ext_gd']->severity());
        $this->assertFalse($checker->hasBlockers($checker->check()));
    }

    public function test_curl_is_optional_because_guzzle_has_a_stream_fallback(): void
    {
        $checker = new ConfigurableRequirementsChecker;
        $checker->absentExtensions = ['curl'];

        $reqs = $this->byKey($checker);

        $this->assertFalse($reqs['ext_curl']->blocks());
        $this->assertFalse($checker->hasBlockers($checker->check()));
    }

    public function test_intl_is_required_because_panel_money_columns_need_it(): void
    {
        $checker = new ConfigurableRequirementsChecker;
        $checker->absentExtensions = ['intl'];

        $reqs = $this->byKey($checker);

        $this->assertTrue($reqs['ext_intl']->blocks(), 'intl missing must block — Filament ->money() throws without it');
        $this->assertSame('error', $reqs['ext_intl']->severity());
        $this->assertTrue($checker->hasBlockers($checker->check()));
    }

    public function test_old_php_blocks(): void
    {
        $checker = new ConfigurableRequirementsChecker;
        $checker->php = '8.2.10';

        $reqs = $this->byKey($checker);

        $this->assertTrue($reqs['php_version']->blocks());
        $this->assertTrue($checker->hasBlockers($checker->check()));
    }

    public function test_unwritable_storage_blocks(): void
    {
        $checker = new ConfigurableRequirementsChecker;
        $checker->writable = false;

        $reqs = $this->byKey($checker);

        $this->assertTrue($reqs['storage_writable']->blocks());
        $this->assertTrue($reqs['cache_writable']->blocks());
    }

    public function test_unwritable_app_root_blocks_before_the_db_is_touched(): void
    {
        $checker = new ConfigurableRequirementsChecker;
        $checker->envWritable = false;

        $reqs = $this->byKey($checker);

        // The installer writes .env in the app root AFTER migrating the DB, so
        // an unwritable root must be a hard blocker caught at preflight.
        $this->assertTrue($reqs['env_writable']->blocks());
        $this->assertSame('error', $reqs['env_writable']->severity());
        $this->assertTrue($checker->hasBlockers($checker->check()));
    }

    public function test_env_probe_passes_on_a_writable_dir_and_writes_nothing(): void
    {
        $dir = sys_get_temp_dir().'/ekdosi-envprobe-'.bin2hex(random_bytes(4));
        mkdir($dir, 0o777, true);

        try {
            $checker = new class($dir) extends RequirementsChecker
            {
                public function __construct(private string $dir) {}

                protected function envTargetDir(): string
                {
                    return $this->dir;
                }

                public function probe(): bool
                {
                    return $this->envTargetWritable();
                }
            };

            $this->assertTrue($checker->probe(), 'a writable dir must pass the .env preflight');
            // The preflight is READ-ONLY — it must never create a file (incl. a
            // dotfile) in the target dir; it runs on every wizard render.
            $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
            $this->assertSame([], $entries, 'the env-writable preflight must not write anything');
        } finally {
            @rmdir($dir);
        }
    }

    public function test_env_probe_fails_on_a_missing_directory(): void
    {
        $checker = new class extends RequirementsChecker
        {
            protected function envTargetDir(): string
            {
                return '/nonexistent-'.bin2hex(random_bytes(4)).'/ekdosi';
            }

            public function probe(): bool
            {
                return $this->envTargetWritable();
            }
        };

        $this->assertFalse($checker->probe());
    }

    public function test_low_upload_limit_warns_but_does_not_block(): void
    {
        $checker = new ConfigurableRequirementsChecker;
        $checker->iniValue = '2M';

        $reqs = $this->byKey($checker);

        $this->assertFalse($reqs['ini_upload_max_filesize']->passed);
        $this->assertFalse($reqs['ini_upload_max_filesize']->blocks());
        $this->assertFalse($checker->hasBlockers($checker->check()));
    }

    public function test_unlimited_memory_limit_passes(): void
    {
        $checker = new ConfigurableRequirementsChecker;
        $checker->iniValue = '-1';

        $reqs = $this->byKey($checker);

        $this->assertTrue($reqs['ini_memory_limit']->passed, '-1 = no limit should pass');
    }

    public function test_non_https_request_warns_only(): void
    {
        $checker = new ConfigurableRequirementsChecker;
        $checker->secure = false;

        $reqs = $this->byKey($checker);

        $this->assertFalse($reqs['https']->passed);
        $this->assertFalse($reqs['https']->blocks());
    }
}
