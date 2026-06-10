<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Cut a release: bump the SemVer version (app semantics — major = milestone,
 * minor = a new feature, patch = fixes), roll the CHANGELOG's `[Unreleased]`
 * section under a dated `[X.Y.Z]` heading, and update `config/app.php`'s version.
 *
 * The major/minor/patch LEVEL is your judgement (a machine can't tell a milestone
 * from a fix); this only mechanises the tedious roll. Prints the git tag command
 * to run — it does NOT commit or tag for you.
 *
 *   php artisan ekdosi:release --minor            # new feature(s) since last tag
 *   php artisan ekdosi:release --patch            # only fixes/tweaks
 *   php artisan ekdosi:release --major --dry-run  # preview the milestone cut
 */
class Release extends Command
{
    protected $signature = 'ekdosi:release
        {--major : Milestone/epoch bump (X.0.0)}
        {--minor : New-feature bump (x.Y.0) — use when [Unreleased] has an Added}
        {--patch : Fixes/tweaks only (x.x.Z)}
        {--date= : Release date (default: today, Y-m-d)}
        {--dry-run : Show the result without writing}';

    protected $description = 'Cut a release: bump the version + roll the CHANGELOG [Unreleased] → dated heading.';

    public function handle(): int
    {
        $levels = array_filter(['major', 'minor', 'patch'], fn ($l) => $this->option($l));
        if (count($levels) !== 1) {
            $this->error('Δώσε ΑΚΡΙΒΩΣ ένα από --major / --minor / --patch.');
            $this->line('  major = milestone · minor = νέο feature (υπάρχει «Added») · patch = μόνο fixes.');

            return self::FAILURE;
        }
        $level = reset($levels);

        $current = (string) config('app.version', '0.0.0');
        $next = self::bump($current, $level);
        $date = (string) ($this->option('date') ?: now()->format('Y-m-d'));

        $changelogPath = base_path('CHANGELOG.md');
        $configPath = config_path('app.php');
        $changelog = (string) file_get_contents($changelogPath);
        $config = (string) file_get_contents($configPath);

        [$newChangelog, $hadEntries] = self::rollChangelog($changelog, $next, $date);
        $newConfig = self::bumpConfig($config, $next);

        if (! $hadEntries) {
            $this->warn('Το [Unreleased] είναι κενό — δεν υπάρχει τίποτα να εκδοθεί.');
        }
        if ($newConfig === $config) {
            $this->error("Δεν βρέθηκε 'version' => '…' στο config/app.php — δεν ενημερώθηκε.");

            return self::FAILURE;
        }

        $this->line("Έκδοση: <fg=gray>{$current}</> → <fg=green;options=bold>{$next}</>  ({$level}, {$date})");

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->line(self::extractSection($newChangelog, $next));
            $this->warn('Dry-run: τίποτα δεν γράφτηκε.');

            return self::SUCCESS;
        }

        file_put_contents($changelogPath, $newChangelog);
        file_put_contents($configPath, $newConfig);

        $this->info("✓ CHANGELOG rolled + config/app.php → {$next}.");
        $this->newLine();
        $this->line('Επόμενα βήματα (review πρώτα):');
        $this->line("  <fg=cyan>git add CHANGELOG.md config/app.php && git commit -m \"release: v{$next}\"</>");
        $this->line("  <fg=cyan>git tag v{$next} && git push && git push --tags</>");

        return self::SUCCESS;
    }

    /** Bump a SemVer string by level. Pads short/missing parts; never negative. */
    public static function bump(string $current, string $level): string
    {
        $parts = array_map('intval', array_slice(array_pad(explode('.', $current), 3, '0'), 0, 3));
        [$x, $y, $z] = $parts;

        return match ($level) {
            'major' => ($x + 1).'.0.0',
            'minor' => $x.'.'.($y + 1).'.0',
            'patch' => $x.'.'.$y.'.'.($z + 1),
            default => $current,
        };
    }

    /**
     * Move the `## [Unreleased]` body under a new `## [version] - date` heading,
     * leaving an empty `## [Unreleased]` on top. Older versioned sections untouched.
     *
     * @return array{0:string, 1:bool}  [new changelog, had-entries]
     */
    public static function rollChangelog(string $md, string $version, string $date): array
    {
        $hadEntries = false;

        $new = preg_replace_callback(
            '/^## \[Unreleased\][^\n]*\n(.*?)(?=^## \[|\z)/ms',
            function (array $m) use ($version, $date, &$hadEntries): string {
                $body = trim($m[1], "\n");
                $hadEntries = $body !== '';
                $dated = "## [{$version}] - {$date}";

                return $body === ''
                    ? "## [Unreleased]\n\n{$dated}\n\n"
                    : "## [Unreleased]\n\n{$dated}\n\n{$body}\n\n";
            },
            $md,
            1,
        );

        return [$new ?? $md, $hadEntries];
    }

    /** Replace the `'version' => '…'` literal in config/app.php (first match). */
    public static function bumpConfig(string $config, string $version): string
    {
        return (string) preg_replace(
            "/('version'\\s*=>\\s*)'[^']*'/",
            "\$1'{$version}'",
            $config,
            1,
        );
    }

    /** Pull just the `## [version] …` section out (for the dry-run preview). */
    private static function extractSection(string $md, string $version): string
    {
        $v = preg_quote($version, '/');
        if (preg_match('/^## \['.$v.'\][^\n]*\n.*?(?=^## \[|\z)/ms', $md, $m)) {
            return trim($m[0]);
        }

        return '(δεν βρέθηκε section)';
    }
}
