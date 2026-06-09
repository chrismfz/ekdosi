<?php

namespace App\Services\Backup\Destinations;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * The default destination: keep the bundle on a Laravel disk under
 * `company-backups/{slug}/`. This is the artifact the panel «Download» serves
 * and the retention target. Disk is `config('ekdosi.backup.local_disk')`.
 */
class LocalBackupDestination extends DiskBackupDestination
{
    public function key(): string
    {
        return 'local';
    }

    public function label(): string
    {
        return 'Τοπικά (λήψη από το panel)';
    }

    protected function disk(array $config): Filesystem
    {
        return Storage::disk((string) config('ekdosi.backup.local_disk', 'local'));
    }
}
