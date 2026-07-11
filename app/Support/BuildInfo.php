<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The DEPLOYED build's identity, kept distinct from the SemVer app version:
 *
 *   - version()    = the human SemVer (config('app.version'), e.g. 1.1.0) — what
 *                    KIND of release this is. Bumped deliberately by ekdosi:release.
 *   - buildStamp() = which EXACT build is on this box, as Y.m.d-His in the app
 *                    timezone (e.g. 2026.07.11-150101) + short commit sha. This is
 *                    the support/diagnostics identity and the updater's compare key.
 *
 * The build stamp is DERIVED from git at deploy time, never hand-committed per PR
 * (that would be churn + wrong the moment you deploy at another time). Source, in
 * order: `storage/app/build.json` (written by deploy/update.sh) → live `git` (dev
 * only) → nothing (stamp hidden, version still shown). All lookups are memoised so
 * repeated reads (badge + health page) don't re-hit disk/git.
 */
class BuildInfo
{
    /** @var array{sha: ?string, committed_at: ?string, ref: ?string}|null */
    private static ?array $memo = null;

    /** The canonical human SemVer — `config('app.version')`. */
    public function version(): string
    {
        return (string) config('app.version', '0.0.0');
    }

    /** Short commit sha of the deployed build, or null when unknown. */
    public function sha(): ?string
    {
        return $this->data()['sha'];
    }

    /** The deployed ref (tag/branch) recorded at deploy, or null. */
    public function ref(): ?string
    {
        return $this->data()['ref'];
    }

    /** Commit timestamp of the deployed build (app timezone), or null. */
    public function committedAt(): ?Carbon
    {
        $iso = $this->data()['committed_at'];
        if ($iso === null) {
            return null;
        }
        try {
            return Carbon::parse($iso)->timezone((string) config('app.timezone', 'UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The build stamp: Y.m.d-His in the app timezone (e.g. 2026.07.11-150101),
     * or null when no commit date is known.
     */
    public function buildStamp(): ?string
    {
        return $this->committedAt()?->format('Y.m.d-His');
    }

    /**
     * Human display line, e.g. «v1.1.0 · 2026.07.11-150101 (a1b2c3d)». Degrades to
     * just «v1.1.0» when the build stamp isn't available (no deploy file / no git).
     */
    public function label(): string
    {
        $label = 'v'.$this->version();
        if ($stamp = $this->buildStamp()) {
            $label .= ' · '.$stamp;
        }
        if ($sha = $this->sha()) {
            $label .= ' ('.$sha.')';
        }

        return $label;
    }

    /** Machine-readable snapshot for JSON surfaces (ops:health, CLI --json). */
    public function toArray(): array
    {
        return [
            'version' => $this->version(),
            'build_stamp' => $this->buildStamp(),
            'sha' => $this->sha(),
            'ref' => $this->ref(),
            'committed_at' => $this->committedAt()?->toIso8601String(),
        ];
    }

    /** Reset the process-level memo (tests set/clear the deploy file between cases). */
    public static function flush(): void
    {
        self::$memo = null;
    }

    /**
     * Resolve {sha, committed_at, ref} once: the deploy-written file first (the
     * production path), then a live git read (local dev convenience only), else all
     * nulls. Never throws — a missing/corrupt source just means «stamp unknown».
     *
     * @return array{sha: ?string, committed_at: ?string, ref: ?string}
     */
    private function data(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        return self::$memo = $this->fromFile() ?? $this->fromGit() ?? [
            'sha' => null,
            'committed_at' => null,
            'ref' => null,
        ];
    }

    /** @return array{sha: ?string, committed_at: ?string, ref: ?string}|null */
    private function fromFile(): ?array
    {
        $path = storage_path('app/build.json');
        if (! is_file($path)) {
            return null;
        }
        try {
            $json = json_decode((string) file_get_contents($path), true);
        } catch (Throwable) {
            return null;
        }
        if (! is_array($json)) {
            return null;
        }

        return [
            'sha' => $this->str($json['sha'] ?? null),
            'committed_at' => $this->str($json['committed_at'] ?? null),
            'ref' => $this->str($json['ref'] ?? null),
        ];
    }

    /**
     * Live git read — DEV ONLY. Shelling out on every prod request is wrong (that's
     * what the deploy file is for) and git may be absent, so this is gated to the
     * local/development environments and wrapped so a failure is silent.
     *
     * @return array{sha: ?string, committed_at: ?string, ref: ?string}|null
     */
    private function fromGit(): ?array
    {
        if (! app()->environment('local', 'development')) {
            return null;
        }
        if (! is_dir(base_path('.git'))) {
            return null;
        }

        try {
            $process = new Process(['git', 'log', '-1', '--format=%h%x1f%cI'], base_path());
            $process->setTimeout(3);
            $process->run();
            if (! $process->isSuccessful()) {
                return null;
            }
            [$sha, $iso] = array_pad(explode("\x1f", trim($process->getOutput())), 2, null);

            return [
                'sha' => $this->str($sha),
                'committed_at' => $this->str($iso),
                'ref' => null,
            ];
        } catch (ProcessFailedException|Throwable) {
            return null;
        }
    }

    private function str(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }
}
