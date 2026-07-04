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
        // OPS-7: the dump stays DB-AGNOSTIC (no --databases embedding the db name);
        // the clean-slate DROP+CREATE lives in db-restore instead.
        $this->assertNotContains('--databases', $cmd);
        $this->assertNotContains('--add-drop-database', $cmd);
        $this->assertSame('ekdosi_prod', end($cmd));
        // The password is NEVER in argv (it travels via MYSQL_PWD).
        $this->assertStringNotContainsString('s3cr3t', implode(' ', $cmd));
    }

    public function test_commands_use_socket_when_configured(): void
    {
        $cfg = ['driver' => 'mariadb', 'unix_socket' => '/run/mysqld/mysqld.sock',
            'host' => '127.0.0.1', 'port' => 3306, 'username' => 'ekdosi',
            'database' => 'ekdosi', 'charset' => 'utf8mb4'];

        $dump = DbSnapshot::dumpCommand($cfg, '/tmp/o.sql');
        $this->assertContains('--socket=/run/mysqld/mysqld.sock', $dump);
        $this->assertNotContains('--host=127.0.0.1', $dump);   // socket wins, no TCP

        $restore = DbRestore::restoreCommand($cfg);
        $this->assertContains('--socket=/run/mysqld/mysqld.sock', $restore);
        $this->assertNotContains('--port=3306', $restore);
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

    public function test_recreate_step_drops_and_creates_the_target_db(): void
    {
        // OPS-7: db-restore starts from a clean schema so an orphan table from a
        // half-applied migration can't survive. The DROP/CREATE targets the
        // RESTORE connection's db (not a name baked into the snapshot).
        $sql = DbRestore::recreateDatabaseSql($this->cfg());
        $this->assertStringContainsString('DROP DATABASE IF EXISTS `ekdosi_prod`', $sql);
        $this->assertStringContainsString('CREATE DATABASE `ekdosi_prod` CHARACTER SET utf8mb4', $sql);

        // The recreate mysql invocation carries NO positional db (it may not exist yet).
        $cmd = DbRestore::recreateCommand($this->cfg());
        $this->assertSame('mysql', $cmd[0]);
        $this->assertContains('--host=db.internal', $cmd);
        $this->assertNotContains('ekdosi_prod', $cmd);
        $this->assertStringNotContainsString('s3cr3t', implode(' ', $cmd));
    }

    public function test_recreate_sql_includes_collation_when_configured(): void
    {
        $sql = DbRestore::recreateDatabaseSql($this->cfg() + ['collation' => 'utf8mb4_unicode_ci']);
        $this->assertStringContainsString('COLLATE utf8mb4_unicode_ci', $sql);
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
