<?php

namespace Tests\Feature\Console;

use App\Console\Commands\DbRestore;
use App\Console\Commands\DbSnapshot;
use Tests\TestCase;

/**
 * Unit-level coverage for the snapshot/restore commands: the mysqldump/mysql
 * argv builders + the guard paths (no live MariaDB needed — the actual dump /
 * restore is validated on the deploy host). The test DB driver is sqlite, which
 * the commands correctly refuse.
 */
class DbSnapshotRestoreTest extends TestCase
{
    private function cfg(): array
    {
        return [
            'driver' => 'mariadb',
            'host' => 'db.internal',
            'port' => 3307,
            'username' => 'ekdosi',
            'password' => 's3cr3t',
            'database' => 'ekdosi_prod',
            'charset' => 'utf8mb4',
        ];
    }

    public function test_dump_command_builds_expected_args(): void
    {
        $cmd = DbSnapshot::dumpCommand($this->cfg(), '/tmp/out.sql');

        $this->assertSame('mysqldump', $cmd[0]);
        $this->assertContains('--host=db.internal', $cmd);
        $this->assertContains('--port=3307', $cmd);
        $this->assertContains('--user=ekdosi', $cmd);
        $this->assertContains('--single-transaction', $cmd);
        $this->assertContains('--result-file=/tmp/out.sql', $cmd);
        $this->assertSame('ekdosi_prod', end($cmd));
        // The password is NEVER in argv (it travels via MYSQL_PWD).
        $this->assertStringNotContainsString('s3cr3t', implode(' ', $cmd));
    }

    public function test_restore_command_builds_expected_args(): void
    {
        $cmd = DbRestore::restoreCommand($this->cfg());

        $this->assertSame('mysql', $cmd[0]);
        $this->assertContains('--host=db.internal', $cmd);
        $this->assertContains('--user=ekdosi', $cmd);
        $this->assertSame('ekdosi_prod', end($cmd));
        $this->assertStringNotContainsString('s3cr3t', implode(' ', $cmd));
    }

    public function test_snapshot_refuses_non_mysql_driver(): void
    {
        // Test connection is sqlite → the command must refuse cleanly.
        $this->artisan('ekdosi:db-snapshot')
            ->assertFailed();
    }

    public function test_restore_requires_an_existing_file(): void
    {
        $this->artisan('ekdosi:db-restore', ['--file' => '/no/such/file.sql.gz'])
            ->assertExitCode(2);   // Command::INVALID
    }

    public function test_restore_refuses_non_mysql_driver(): void
    {
        // A real file, but sqlite driver → refuse (driver check after file check).
        $tmp = tempnam(sys_get_temp_dir(), 'snap').'.sql';
        file_put_contents($tmp, 'SELECT 1;');

        $this->artisan('ekdosi:db-restore', ['--file' => $tmp, '--force' => true])
            ->assertFailed();

        @unlink($tmp);
    }
}
