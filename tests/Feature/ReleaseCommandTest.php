<?php

namespace Tests\Feature;

use App\Console\Commands\Release;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The `ekdosi:release` version-bump + CHANGELOG-roll logic (the parts that must be
 * exactly right — they edit the changelog + the version source of truth).
 */
class ReleaseCommandTest extends TestCase
{
    #[Test]
    public function it_bumps_semver_per_level(): void
    {
        $this->assertSame('2.0.0', Release::bump('1.4.2', 'major'));
        $this->assertSame('1.5.0', Release::bump('1.4.2', 'minor'));
        $this->assertSame('1.4.3', Release::bump('1.4.2', 'patch'));
        // First cut from the baseline.
        $this->assertSame('1.0.0', Release::bump('0.0.0', 'major'));
        // Tolerates short/padded versions.
        $this->assertSame('1.1.0', Release::bump('1', 'minor'));
    }

    #[Test]
    public function it_rolls_unreleased_under_a_dated_heading_and_keeps_unreleased_empty(): void
    {
        $md = <<<'MD'
        # Changelog

        ## [Unreleased]
        ### Added
        - Feature A
        ### Fixed
        - Bug B

        ## [0.9.0] - 2026-05-01
        ### Added
        - Old thing
        MD;

        [$new, $had] = Release::rollChangelog($md, '1.0.0', '2026-06-10');

        $this->assertTrue($had);
        // Empty [Unreleased] stays on top, immediately above the new dated heading.
        $this->assertMatchesRegularExpression('/## \[Unreleased\]\s+## \[1\.0\.0\] - 2026-06-10/', $new);
        // The moved entries live under the dated heading.
        $this->assertStringContainsString("## [1.0.0] - 2026-06-10\n\n### Added\n- Feature A", $new);
        // The older section is untouched + still present.
        $this->assertStringContainsString('## [0.9.0] - 2026-05-01', $new);
        $this->assertStringContainsString('- Old thing', $new);
        // Exactly one [Unreleased] remains.
        $this->assertSame(1, substr_count($new, '## [Unreleased]'));
    }

    #[Test]
    public function an_empty_unreleased_reports_no_entries(): void
    {
        $md = "# Changelog\n\n## [Unreleased]\n\n## [1.0.0] - 2026-06-10\n- x\n";
        [, $had] = Release::rollChangelog($md, '1.1.0', '2026-06-11');
        $this->assertFalse($had);
    }

    #[Test]
    public function it_updates_the_config_version_literal(): void
    {
        $config = "<?php\nreturn [\n    'name' => 'x',\n    'version' => '0.0.0',\n];\n";
        $this->assertStringContainsString("'version' => '1.2.0'", Release::bumpConfig($config, '1.2.0'));
    }

    #[Test]
    public function dry_run_writes_nothing(): void
    {
        $this->withoutMockingConsoleOutput();
        $changelog = file_get_contents(base_path('CHANGELOG.md'));
        $config = file_get_contents(config_path('app.php'));

        // Dry-run must never touch disk — regardless of whether [Unreleased] has
        // anything to release (empty → it exits with a «nothing to release»
        // notice, non-empty → it previews; neither writes).
        $this->artisan('ekdosi:release', ['--patch' => true, '--dry-run' => true]);

        $this->assertSame($changelog, file_get_contents(base_path('CHANGELOG.md')));
        $this->assertSame($config, file_get_contents(config_path('app.php')));
    }

    #[Test]
    public function it_infers_minor_when_unreleased_has_an_added(): void
    {
        $md = "# CL\n\n## [Unreleased]\n\n### Added\n- A new filter\n\n### Fixed\n- a bug\n\n## [1.0.0] - 2026-01-01\n- x\n";

        [$level, $reason] = Release::inferLevel($md);

        $this->assertSame('minor', $level);
        $this->assertStringContainsString('Added', $reason);
    }

    #[Test]
    public function it_infers_patch_when_unreleased_has_only_fixes(): void
    {
        $md = "# CL\n\n## [Unreleased]\n\n### Fixed\n- a bug\n\n### Security\n- a hole\n\n## [1.0.0] - 2026-01-01\n- x\n";

        [$level, $reason] = Release::inferLevel($md);

        $this->assertSame('patch', $level);
        $this->assertStringContainsString('Fixed', $reason);
    }

    #[Test]
    public function it_infers_nothing_for_an_empty_unreleased(): void
    {
        $md = "# CL\n\n## [Unreleased]\n\n## [1.0.0] - 2026-01-01\n- x\n";

        [$level] = Release::inferLevel($md);

        $this->assertNull($level);
    }

    #[Test]
    public function an_empty_added_heading_with_no_items_does_not_force_minor(): void
    {
        // A stray «### Added» with nothing under it must NOT bump minor — only a
        // real feature (an item) counts. Here only Fixed has an item → patch.
        $md = "# CL\n\n## [Unreleased]\n\n### Added\n\n### Fixed\n- a bug\n\n## [1.0.0] - 2026-01-01\n- x\n";

        [$level] = Release::inferLevel($md);

        $this->assertSame('patch', $level);
    }

    #[Test]
    public function check_mode_writes_nothing_and_exits_zero(): void
    {
        $this->withoutMockingConsoleOutput();
        $changelog = file_get_contents(base_path('CHANGELOG.md'));
        $config = file_get_contents(config_path('app.php'));

        $exit = $this->artisan('ekdosi:release', ['--check' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame($changelog, file_get_contents(base_path('CHANGELOG.md')));
        $this->assertSame($config, file_get_contents(config_path('app.php')));
    }

    #[Test]
    public function it_rejects_more_than_one_explicit_level(): void
    {
        $this->withoutMockingConsoleOutput();

        $exit = $this->artisan('ekdosi:release', ['--minor' => true, '--patch' => true]);

        $this->assertSame(1, $exit);
    }
}
