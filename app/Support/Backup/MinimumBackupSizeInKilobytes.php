<?php

namespace App\Support\Backup;

use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Tasks\Monitor\HealthCheck;

/**
 * Marks the backup destination UNHEALTHY when the newest backup is suspiciously
 * small — an empty/near-empty dump (e.g. the 9.7 KB one produced after a DB
 * wipe) otherwise passes spatie's default age/storage checks and gives a false
 * sense of safety while retention quietly evicts the good ones.
 */
class MinimumBackupSizeInKilobytes extends HealthCheck
{
    public function __construct(protected int $minimumSizeInKilobytes = 100) {}

    public function checkHealth(BackupDestination $backupDestination): void
    {
        $newest = $backupDestination->newestBackup();

        $this->failIf($newest === null, 'There are no backups.');

        $sizeInKilobytes = (int) round($newest->sizeInBytes() / 1024);

        $this->failIf(
            $sizeInKilobytes < $this->minimumSizeInKilobytes,
            "The newest backup is only {$sizeInKilobytes} KB (minimum {$this->minimumSizeInKilobytes} KB) — it looks empty or corrupt.",
        );
    }
}
