<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Cut a release: bump the SemVer version (app semantics — major = milestone,
 * minor = a new feature, patch = fixes), roll the CHANGELOG's `[Unreleased]`
 * section under a dated `[X.Y.Z]` heading, and update `config/app.php`'s version.
 *
 * The LEVEL is inferred from the CHANGELOG so you don't have to judge it: an
 * «### Added» in `[Unreleased]` → minor, otherwise (only Fixed/Changed/Security…)
 * → patch. A MILESTONE (major) is the one thing a machine can't tell from a
 * changelog, so `--major` stays explicit. `--minor`/`--patch` still override.
 *
 *   php artisan ekdosi:release                    # auto: reads [Unreleased], picks minor/patch
 *   php artisan ekdosi:release --check            # preflight: are there unreleased changes? (non-destructive)
 *   php artisan ekdosi:release --major            # milestone (always explicit)
 *   php artisan ekdosi:release --commit --tag     # also git-commit the roll + create vX.Y.Z (no push)
 *   php artisan ekdosi:release --minor --dry-run  # preview an override
 */
class Release extends Command
{
    protected $signature = 'ekdosi:release
        {--major : Milestone/epoch bump (X.0.0) — always explicit}
        {--minor : Override: new-feature bump (x.Y.0)}
        {--patch : Override: fixes/tweaks (x.x.Z)}
        {--check : Non-destructive: report whether there are unreleased changes + the level they would cut}
        {--commit : After rolling, git-commit CHANGELOG.md + config/app.php}
        {--tag : After rolling, create the vX.Y.Z git tag (implies --commit). Never pushes.}
        {--date= : Release date (default: today, Y-m-d)}
        {--dry-run : Show the result without writing}';

    protected $description = 'Cut a release: infer the level from CHANGELOG [Unreleased], bump the version + roll it under a dated heading.';

    public function handle(): int
    {
        $changelogPath = base_path('CHANGELOG.md');
        $configPath = config_path('app.php');
        $changelog = (string) file_get_contents($changelogPath);
        $config = (string) file_get_contents($configPath);
        // Read the CURRENT version from the file we're about to bump — not from
        // config('app.version'), which is the CACHED config and goes stale on a
        // deploy box (the cause of the 1.12.0 → «1.11.1» downgrade). Fall back to
        // config() only if the literal is somehow missing.
        $current = self::currentVersion($config) ?? (string) config('app.version', '0.0.0');

        // --check — deploy preflight / reminder, writes nothing.
        if ($this->option('check')) {
            return $this->runCheck($changelog, $current);
        }

        // Level: an explicit flag wins; otherwise infer from [Unreleased].
        $explicit = array_values(array_filter(['major', 'minor', 'patch'], fn ($l) => $this->option($l)));
        if (count($explicit) > 1) {
            $this->error('Δώσε ΤΟ ΠΟΛΥ ένα από --major / --minor / --patch (ή κανένα για αυτόματη επιλογή).');

            return self::FAILURE;
        }

        if (count($explicit) === 1) {
            $level = $explicit[0];
            $reason = "ρητό --{$level}";
        } else {
            [$level, $reason] = self::inferLevel($changelog);
            if ($level === null) {
                $this->warn("Δεν κόπηκε έκδοση: {$reason}. (Για milestone δώσε ρητά --major.)");

                return self::FAILURE;
            }
        }

        $next = self::bump($current, $level);
        $date = (string) ($this->option('date') ?: now()->format('Y-m-d'));

        [$newChangelog, $hadEntries] = self::rollChangelog($changelog, $next, $date);
        $newConfig = self::bumpConfig($config, $next);

        if (! $hadEntries) {
            $this->warn('Το [Unreleased] είναι κενό — δεν υπάρχει τίποτα να εκδοθεί.');

            return self::FAILURE;
        }
        if ($newConfig === $config) {
            $this->error("Δεν βρέθηκε 'version' => '…' στο config/app.php — δεν ενημερώθηκε.");

            return self::FAILURE;
        }

        $this->line("Έκδοση: <fg=gray>{$current}</> → <fg=green;options=bold>{$next}</>  ({$level} — {$reason}, {$date})");

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->line(self::extractSection($newChangelog, $next));
            $this->warn('Dry-run: τίποτα δεν γράφτηκε.');

            return self::SUCCESS;
        }

        file_put_contents($changelogPath, $newChangelog);
        file_put_contents($configPath, $newConfig);
        $this->info("✓ CHANGELOG rolled + config/app.php → {$next}.");

        if ($this->option('commit') || $this->option('tag')) {
            return $this->commitAndTag($next);
        }

        $this->newLine();
        $this->line('Επόμενα βήματα (review πρώτα):');
        $this->line("  <fg=cyan>git add CHANGELOG.md config/app.php && git commit -m \"release: v{$next}\"</>");
        $this->line("  <fg=cyan>git tag v{$next} && git push && git push --tags</>");

        return self::SUCCESS;
    }

    /**
     * Non-destructive preflight: is there anything to release, and at what level?
     * Wired into the deploy (clean.sh) so a forgotten `ekdosi:release` — code
     * shipped while the version stayed put — surfaces as a reminder. Never fails
     * the deploy (exit 0 always): it's advisory.
     */
    private function runCheck(string $changelog, string $current): int
    {
        [$level, $reason] = self::inferLevel($changelog);

        if ($level === null) {
            $this->info("Έκδοση v{$current} · καμία αδημοσίευτη αλλαγή ({$reason}).");

            return self::SUCCESS;
        }

        $next = self::bump($current, $level);
        $this->warn('⚠ Υπάρχουν αδημοσίευτες αλλαγές στο CHANGELOG [Unreleased].');
        $this->line("  Τρέχουσα v{$current} → θα γινόταν v{$next}  ({$level} — {$reason}).");
        $this->line('  Κόψε έκδοση: <fg=cyan>php artisan ekdosi:release</> (ή --major για milestone).');

        return self::SUCCESS;
    }

    /**
     * git-commit the two release files (ONLY those, via pathspec — never sweeps up
     * other staged work) and optionally create the vX.Y.Z tag. Never pushes
     * (outward-facing — the operator does that). `--tag` implies the commit, since
     * a tag must point at the commit that carries the version bump.
     */
    private function commitAndTag(string $next): int
    {
        $tag = "v{$next}";

        $commit = Process::path(base_path())->run(['git', 'commit', '-m', "release: {$tag}", '--', 'CHANGELOG.md', 'config/app.php']);
        if (! $commit->successful()) {
            $this->error('git commit απέτυχε: '.trim($commit->errorOutput() ?: $commit->output()));

            return self::FAILURE;
        }
        $this->info("✓ git commit: release: {$tag}");

        if ($this->option('tag')) {
            $existing = Process::path(base_path())->run(['git', 'tag', '-l', $tag]);
            if (trim($existing->output()) !== '') {
                $this->error("Το tag {$tag} υπάρχει ήδη — δεν ξαναδημιουργείται (η έκδοση κόπηκε, μόνο το tag παραλείφθηκε).");

                return self::FAILURE;
            }

            $tagProc = Process::path(base_path())->run(['git', 'tag', $tag]);
            if (! $tagProc->successful()) {
                $this->error('git tag απέτυχε: '.trim($tagProc->errorOutput() ?: $tagProc->output()));

                return self::FAILURE;
            }
            $this->info("✓ git tag: {$tag}");
        }

        $this->newLine();
        $this->line('Push όταν είσαι έτοιμος: <fg=cyan>git push && git push --tags</>');

        return self::SUCCESS;
    }

    /**
     * Infer the release LEVEL from the CHANGELOG's [Unreleased] subsections (the
     * rule from CLAUDE.md): a non-empty «### Added» → minor (new feature); only
     * Fixed/Changed/Security/Removed/Deprecated → patch. Major is a milestone a
     * machine can't detect → not inferred (returns via explicit --major only).
     *
     * @return array{0: ?string, 1: string} [level|null, human reason]
     */
    public static function inferLevel(string $md): array
    {
        if (! preg_match('/^## \[Unreleased\][^\n]*\n(.*?)(?=^## \[|\z)/ms', $md, $m)) {
            return [null, 'δεν βρέθηκε ενότητα [Unreleased]'];
        }

        $body = trim($m[1]);
        if ($body === '') {
            return [null, 'το [Unreleased] είναι κενό'];
        }

        // Which ### subsections actually carry ≥1 `- ` item (an empty heading
        // doesn't count).
        preg_match_all('/^### (\w+)[^\n]*\n(.*?)(?=^### |\z)/ms', $body."\n", $sections, PREG_SET_ORDER);
        $withItems = [];
        foreach ($sections as $s) {
            if (preg_match('/^\s*-\s+\S/m', $s[2])) {
                $withItems[] = ucfirst(strtolower($s[1]));
            }
        }

        if ($withItems === []) {
            // No structured `### X` subsection carried an item. If there's still a
            // bare `- item` under [Unreleased] (someone skipped the ### discipline),
            // don't silently abort — rollChangelog() WOULD roll it, so treat it as a
            // patch to keep the two "is there anything to release" views in agreement.
            if (preg_match('/^\s*-\s+\S/m', $body)) {
                return ['patch', 'μη-δομημένες αλλαγές (χωρίς ### subsection)'];
            }

            return [null, 'το [Unreleased] δεν έχει καταχωρήσεις'];
        }
        if (in_array('Added', $withItems, true)) {
            return ['minor', 'το [Unreleased] έχει «Added» (νέο feature)'];
        }

        return ['patch', 'μόνο '.implode('/', $withItems).' — καμία «Added»'];
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
     * @return array{0:string, 1:bool} [new changelog, had-entries]
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

    /**
     * The current version parsed FROM the config/app.php contents — NOT from
     * `config('app.version')`, which reads the cached config (`config:cache`) and
     * on a deploy box is stale relative to the file we're about to bump. Reading
     * the same file we write keeps the base version correct regardless of cache
     * (a stale cache once made `ekdosi:release` try to bump 1.12.0 → «1.11.1»).
     * Returns null if no literal is found (caller falls back to config()).
     */
    public static function currentVersion(string $config): ?string
    {
        return preg_match("/'version'\\s*=>\\s*'([^']*)'/", $config, $m) ? $m[1] : null;
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
