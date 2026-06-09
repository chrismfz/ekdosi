<?php

namespace App\Services\Backup\Destinations;

use App\Contracts\BackupDestination;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\File\File;

/**
 * The default destination: keep the bundle on a Laravel disk under
 * `company-backups/{slug}/`. This is the artifact the panel «Download» serves
 * and the retention target. Disk is `config('ekdosi.backup.local_disk')`.
 */
class LocalBackupDestination implements BackupDestination
{
    public function key(): string
    {
        return 'local';
    }

    public function label(): string
    {
        return 'Τοπικά (λήψη από το panel)';
    }

    public function push(string $bundlePath, string $slug, array $config): string
    {
        $disk = $this->disk();
        $dir = $this->dir($slug);
        $disk->putFileAs($dir, new File($bundlePath), basename($bundlePath));

        return $disk->path($dir.'/'.basename($bundlePath));
    }

    public function prune(string $slug, int $keep, ?int $days, array $config): int
    {
        $disk = $this->disk();
        $dir = $this->dir($slug);

        $files = collect($disk->files($dir))
            ->filter(static fn (string $p) => str_ends_with($p, '.zip'))
            // Newest first, with the filename as a DETERMINISTIC tie-break so two
            // bundles written in the same second never order arbitrarily (which
            // could otherwise prune a still-current bundle at keep=1).
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

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('ekdosi.backup.local_disk', 'local'));
    }

    private function dir(string $slug): string
    {
        return 'company-backups/'.$slug;
    }
}
