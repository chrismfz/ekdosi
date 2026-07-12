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
    public function dry_run_previews_without_writing(): void
    {
        // Drive the PREVIEW branch: seed a non-empty [Unreleased] so the command
        // reaches the dry-run preview (an empty one aborts earlier). Assert it
        // exits 0 and writes NOTHING. Restore the real CHANGELOG in finally so a
        // failing assertion never leaves the repo file mutated.
        $this->withoutMockingConsoleOutput();
        $path = base_path('CHANGELOG.md');
        $original = (string) file_get_contents($path);
        $config = (string) file_get_contents(config_path('app.php'));

        try {
            $seeded = preg_replace(
                '/## \[Unreleased\]\n/',
                "## [Unreleased]\n\n### Fixed\n- seeded fixture entry\n\n",
                $original,
                1,
            );
            file_put_contents($path, $seeded);

            $exit = $this->artisan('ekdosi:release', ['--patch' => true, '--dry-run' => true]);

            $this->assertSame(0, $exit);
            $this->assertSame($seeded, file_get_contents($path));         // preview wrote nothing
            $this->assertSame($config, file_get_contents(config_path('app.php')));
        } finally {
            file_put_contents($path, $original);
        }
    }

    #[Test]
    public function auto_mode_infers_and_previews_the_level_end_to_end(): void
    {
        // No flag → handle() must run inferLevel and preview the inferred bump.
        // Seed an «Added» so it should choose minor; restore in finally.
        $path = base_path('CHANGELOG.md');
        $original = (string) file_get_contents($path);

        try {
            $seeded = preg_replace(
                '/## \[Unreleased\]\n/',
                "## [Unreleased]\n\n### Added\n- a brand new feature\n\n",
                $original,
                1,
            );
            file_put_contents($path, $seeded);

            // The next version is level-specific: minor of 1.x.y is 1.(x+1).0,
            // which patch/major could never produce — so seeing it in the preview
            // proves auto mode inferred MINOR from the seeded «Added».
            $next = Release::bump((string) config('app.version', '0.0.0'), 'minor');

            $this->artisan('ekdosi:release', ['--dry-run' => true])
                ->expectsOutputToContain($next)
                ->assertExitCode(0);

            $this->assertSame($seeded, file_get_contents($path));  // dry-run wrote nothing
        } finally {
            file_put_contents($path, $original);
        }
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
    public function a_bare_item_without_a_subsection_falls_back_to_patch(): void
    {
        // Someone skipped the ### discipline and wrote a bare entry. rollChangelog
        // would still roll it, so inferLevel must NOT report «nothing» — it falls
        // back to patch rather than silently aborting the release.
        $md = "# CL\n\n## [Unreleased]\n- a bare, unstructured note\n\n## [1.0.0] - 2026-01-01\n- x\n";

        [$level, $reason] = Release::inferLevel($md);

        $this->assertSame('patch', $level);
        $this->assertStringContainsString('μη-δομημένες', $reason);
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
