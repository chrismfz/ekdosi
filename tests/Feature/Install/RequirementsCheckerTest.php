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
