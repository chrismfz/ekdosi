<?php

namespace App\Services\Backup\Destinations;

use App\Contracts\BackupDestination;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Shared push/prune for any destination backed by a Laravel filesystem disk —
 * Local (a configured disk) and the remote drivers (SFTP/FTP/S3, each building a
 * disk on the fly from its saved config). Subclasses only supply the disk, the
 * base directory, and how to describe a stored location in the run log. The
 * retention/prune rule (newest-N + older-than-days, deterministic tie-break)
 * lives here once.
 */
abstract class DiskBackupDestination implements BackupDestination
{
    abstract public function key(): string;

    abstract public function label(): string;

    /** The disk to read/write, built from the destination's saved config. */
    abstract protected function disk(array $config): Filesystem;

    /** Base folder on that disk; per-company bundles live under baseDir/{slug}. */
    protected function baseDir(array $config): string
    {
        $path = trim((string) ($config['path'] ?? ''), '/');

        return $path === '' ? 'company-backups' : $path;
    }

    public function push(string $bundlePath, string $slug, array $config): string
    {
        $disk = $this->disk($config);
        $dir = $this->dir($config, $slug);

        // putFileAs returns FALSE (not throws) on failure unless the disk is
        // configured `throw=true` — the local disk and any disk we don't force
        // aren't. Without this check a failed upload (bad SFTP creds, host down,
        // S3 permission denied) would be silently recorded as a successful
        // backup by the runner, which only treats THROWN errors as failures.
        if ($disk->putFileAs($dir, new File($bundlePath), basename($bundlePath)) === false) {
            throw new RuntimeException(sprintf(
                'Backup upload to «%s» failed (%s).', $this->label(), $dir
            ));
        }

        return $this->locationLabel($disk, $dir, basename($bundlePath), $config);
    }

    public function prune(string $slug, int $keep, ?int $days, array $config): int
    {
        $disk = $this->disk($config);
        $dir = $this->dir($config, $slug);

        $files = collect($disk->files($dir))
            ->filter(static fn (string $p) => str_ends_with($p, '.zip'))
            // Newest first, filename as a DETERMINISTIC tie-break so same-second
            // bundles never order arbitrarily (keep=1 must not drop the current).
            ->sortByDesc(static fn (string $p) => sprintf('%020d|%s', $disk->lastModified($p), $p))
            ->values();

        $cutoff = $days !== null ? CarbonImmutable::now()->subDays($days)->getTimestamp() : null;

        $removed = 0;
        $files->each(function (string $path, int $i) use ($disk, $keep, $cutoff, &$removed) {
            $tooMany = $keep > 0 && $i >= $keep;
            $tooOld = $cutoff !== null && $disk->lastModified($path) < $cutoff;
            if ($tooMany || $tooOld) {
                $disk->delete($path);
                $removed++;
            }
        });

        return $removed;
    }

    protected function dir(array $config, string $slug): string
    {
        return trim($this->baseDir($config), '/').'/'.$slug;
    }

    /** Human-readable stored location for the run log. Remotes override to a uri. */
    protected function locationLabel(Filesystem $disk, string $dir, string $file, array $config): string
    {
        return $disk->path($dir.'/'.$file);
    }
}
