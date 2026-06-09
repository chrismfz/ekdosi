<?php

namespace Tests\Feature\Backup;

use App\Services\Backup\Destinations\FlysystemBackupDestination;
use App\Services\Backup\Destinations\FtpBackupDestination;
use App\Services\Backup\Destinations\S3BackupDestination;
use App\Services\Backup\Destinations\SftpBackupDestination;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class RemoteBackupDestinationTest extends TestCase
{
    /** @return array<string,mixed> */
    private function diskConfig(object $driver, array $input): array
    {
        $m = new ReflectionMethod($driver, 'diskConfig');
        $m->setAccessible(true);

        return $m->invoke($driver, $input);
    }

    #[Test]
    public function sftp_maps_config_to_a_flysystem_disk(): void
    {
        $cfg = $this->diskConfig(new SftpBackupDestination, [
            'host' => 'box.example', 'port' => '2222', 'username' => 'bk',
            'private_key' => '---KEY---', 'key_passphrase' => 'pp', 'password' => '',
        ]);

        $this->assertSame('sftp', $cfg['driver']);
        $this->assertSame('box.example', $cfg['host']);
        $this->assertSame(2222, $cfg['port']);
        $this->assertSame('bk', $cfg['username']);
        $this->assertSame('---KEY---', $cfg['privateKey']);
        $this->assertSame('pp', $cfg['passphrase']);
        $this->assertArrayNotHasKey('password', $cfg, 'empty password must be dropped, not sent blank');
    }

    #[Test]
    public function ftp_keeps_boolean_flags_and_defaults_passive(): void
    {
        $cfg = $this->diskConfig(new FtpBackupDestination, [
            'host' => 'ftp.example', 'username' => 'u', 'password' => 'p', 'ssl' => false,
        ]);

        $this->assertSame('ftp', $cfg['driver']);
        $this->assertSame(21, $cfg['port']);
        $this->assertFalse($cfg['ssl'], 'ssl=false must survive (not be filtered as falsy)');
        $this->assertTrue($cfg['passive']);
    }

    #[Test]
    public function s3_maps_endpoint_and_path_style_for_compatibles(): void
    {
        $cfg = $this->diskConfig(new S3BackupDestination, [
            'key' => 'AK', 'secret' => 'SK', 'bucket' => 'ekdosi-bk',
            'endpoint' => 'https://s3.eu.example', 'path_style' => true,
        ]);

        $this->assertSame('s3', $cfg['driver']);
        $this->assertSame('ekdosi-bk', $cfg['bucket']);
        $this->assertSame('us-east-1', $cfg['region'], 'region defaults when unset');
        $this->assertSame('https://s3.eu.example', $cfg['endpoint']);
        $this->assertTrue($cfg['use_path_style_endpoint']);
        $this->assertTrue($cfg['throw']);
    }

    #[Test]
    public function push_and_prune_work_against_any_disk_via_the_shared_base(): void
    {
        $disk = Storage::fake('remote-test');
        $driver = $this->fakeRemote($disk);
        $cfg = ['path' => 'offsite'];

        $older = tempnam(sys_get_temp_dir(), 'bk').'-older.zip';
        $newer = tempnam(sys_get_temp_dir(), 'bk').'-newer.zip';
        file_put_contents($older, 'old');
        file_put_contents($newer, 'new');

        // path config → per-company foldering under it; readable uri location.
        $location = $driver->push($older, 'acme', $cfg);
        $this->assertStringStartsWith('fake://', $location, 'remote location is a readable uri, not a local path');
        $driver->push($newer, 'acme', $cfg);
        $this->assertCount(2, $disk->files('offsite/acme'));

        // Make recency unambiguous (backdate the «older» bundle on disk), then
        // prune keep=1 must drop exactly it.
        touch($disk->path('offsite/acme/'.basename($older)), time() - 600);
        $removed = $driver->prune('acme', 1, null, $cfg);
        $this->assertSame(1, $removed);
        $remaining = $disk->files('offsite/acme');
        $this->assertCount(1, $remaining);
        $this->assertStringContainsString('-newer.zip', $remaining[0]);

        @unlink($older);
        @unlink($newer);
    }

    #[Test]
    public function push_throws_when_the_disk_reports_a_failed_write(): void
    {
        // putFileAs returns FALSE (not throws) on a disk without throw=true; the
        // shared base must surface that as an exception so the runner records the
        // destination as FAILED instead of silently «ok» (the off-site-backup trap).
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('putFileAs')->once()->andReturn(false);

        $tmp = tempnam(sys_get_temp_dir(), 'bk').'.zip';
        file_put_contents($tmp, 'zip-bytes');

        $this->expectException(RuntimeException::class);

        try {
            $this->fakeRemote($disk)->push($tmp, 'acme', ['path' => 'offsite']);
        } finally {
            @unlink($tmp);
        }
    }

    private function fakeRemote(Filesystem $disk): FlysystemBackupDestination
    {
        return new class($disk) extends FlysystemBackupDestination
        {
            public function __construct(private Filesystem $d) {}

            public function key(): string
            {
                return 'fake';
            }

            public function label(): string
            {
                return 'Fake';
            }

            protected function diskConfig(array $config): array
            {
                return [];
            }

            protected function disk(array $config): Filesystem
            {
                return $this->d;
            }
        };
    }
}
