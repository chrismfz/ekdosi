<?php

namespace Tests\Feature\Backup;

use App\Support\Backup\MinimumBackupSizeInKilobytes;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Exceptions\InvalidHealthCheck;
use Tests\TestCase;

class MinimumBackupSizeHealthCheckTest extends TestCase
{
    private function destinationWithNewest(?Backup $newest): BackupDestination
    {
        $dest = \Mockery::mock(BackupDestination::class);
        $dest->shouldReceive('newestBackup')->andReturn($newest);

        return $dest;
    }

    private function backupOfBytes(int $bytes): Backup
    {
        $backup = \Mockery::mock(Backup::class);
        $backup->shouldReceive('sizeInBytes')->andReturn((float) $bytes);

        return $backup;
    }

    public function test_passes_for_a_healthy_sized_backup(): void
    {
        $check = new MinimumBackupSizeInKilobytes(100);
        $check->checkHealth($this->destinationWithNewest($this->backupOfBytes(1_200_000))); // 1.2 MB

        $this->assertTrue(true); // no exception thrown
    }

    public function test_fails_for_an_empty_looking_backup(): void
    {
        $this->expectException(InvalidHealthCheck::class);

        $check = new MinimumBackupSizeInKilobytes(100);
        $check->checkHealth($this->destinationWithNewest($this->backupOfBytes(9_700))); // 9.7 KB
    }

    public function test_fails_when_there_are_no_backups(): void
    {
        $this->expectException(InvalidHealthCheck::class);

        $check = new MinimumBackupSizeInKilobytes(100);
        $check->checkHealth($this->destinationWithNewest(null));
    }
}
