<?php

namespace App\Services\Backup\Destinations;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Base for the REMOTE destinations (SFTP / FTP / S3). Each builds an on-the-fly
 * Laravel disk from its saved per-destination config via Storage::build() — no
 * entry in config/filesystems.php, because credentials are per-company and live
 * in company_backup_settings.destinations. Subclasses only map their config to a
 * disk-config array; push/prune/retention are inherited from DiskBackupDestination.
 */
abstract class FlysystemBackupDestination extends DiskBackupDestination
{
    /** Map the operator's saved config → a Laravel/Flysystem disk config array. */
    abstract protected function diskConfig(array $config): array;

    protected function disk(array $config): Filesystem
    {
        return Storage::build($this->diskConfig($config));
    }

    protected function locationLabel(Filesystem $disk, string $dir, string $file, array $config): string
    {
        // Remote path()s aren't meaningful for the run log — show a readable uri.
        $host = (string) ($config['host'] ?? $config['bucket'] ?? '');

        return $this->key().'://'.$host.'/'.$dir.'/'.$file;
    }
}
