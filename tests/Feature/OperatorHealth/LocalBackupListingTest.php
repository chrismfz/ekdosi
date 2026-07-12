<?php

namespace Tests\Feature\OperatorHealth;

use App\Support\OperatorHealth\OperatorHealthReport;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The health screen lists the whole-DB (spatie) backup ARTIFACTS — dir, count,
 * total size, and each `.zip` with size + timestamp (newest first) — so the
 * operator sees WHAT exists, not just that the last one is fresh.
 */
class LocalBackupListingTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        // Isolated backup folder so we never touch a real one.
        config(['backup.backup.name' => 'test-backups-'.uniqid()]);
        $this->dir = Storage::disk('local')->path(config('backup.backup.name'));
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function backup(): array
    {
        return (new OperatorHealthReport)->build()['backup'];
    }

    #[Test]
    public function it_lists_backups_with_size_and_timestamp_newest_first(): void
    {
        File::put($this->dir.'/old.zip', str_repeat('a', 1000));
        File::put($this->dir.'/new.zip', str_repeat('b', 2000));
        touch($this->dir.'/old.zip', now()->subDays(2)->timestamp);
        touch($this->dir.'/new.zip', now()->subHour()->timestamp);
        // A non-zip must be ignored.
        File::put($this->dir.'/notes.txt', 'x');

        $b = $this->backup();

        $this->assertSame(2, $b['local_count']);
        $this->assertSame(3000, $b['local_total_bytes']);
        $this->assertSame($this->dir, $b['local_dir']);

        // Newest first.
        $this->assertSame('new.zip', $b['local_files'][0]['name']);
        $this->assertSame(2000, $b['local_files'][0]['size_bytes']);
        $this->assertSame('old.zip', $b['local_files'][1]['name']);

        // The latest-* summary reflects the newest file.
        $this->assertSame(2000, $b['latest_backup_size_bytes']);
    }

    #[Test]
    public function an_empty_or_missing_dir_reports_zero_not_an_error(): void
    {
        // setUp created an empty dir; also cover the truly-missing case.
        $b = $this->backup();
        $this->assertSame(0, $b['local_count']);
        $this->assertSame(0, $b['local_total_bytes']);
        $this->assertSame([], $b['local_files']);
        $this->assertNull($b['latest_backup_size_bytes']);
    }
}
