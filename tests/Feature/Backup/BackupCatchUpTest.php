<?php

namespace Tests\Feature\Backup;

use App\Support\Backup\BackupOverdue;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A deploy (maintenance mode) at 02:00 made the scheduler skip that night's backup:run
 * (2026-09-26). The hourly catch-up runs it late — only when the monitor would call it stale.
 */
class BackupCatchUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['backup.backup.destination.disks' => ['local']]);
    }

    private function backupAt(string $stamp): void
    {
        Storage::disk('local')->put(config('backup.backup.name').'/'.$stamp.'.zip', 'x');
    }

    private function catchUp(): Event
    {
        return collect(app(Schedule::class)->events())->firstOrFail(fn (Event $e) => $e->description === 'backup-run-catch-up');
    }

    public function test_no_backup_at_all_is_overdue(): void
    {
        $this->assertTrue(BackupOverdue::check());
    }

    public function test_a_normal_night_never_triggers_it_a_skipped_one_does(): void
    {
        $this->backupAt('2026-09-25-02-00-14');

        $this->travelTo('2026-09-26 01:59');   // the next run hasn't even been due yet
        $this->assertFalse(BackupOverdue::check());
        $this->travelTo('2026-09-26 02:20');   // 02:00 still running / just done — no double backup
        $this->assertFalse(BackupOverdue::check());
        $this->travelTo('2026-09-26 03:20');   // 02:00 was skipped (deploy) → caught up at 03:20
        $this->assertTrue(BackupOverdue::check());

        $this->backupAt('2026-09-26-03-20-05');
        $this->assertFalse(BackupOverdue::check());
    }

    public function test_the_catch_up_is_hourly_and_follows_the_backup_switch(): void
    {
        $event = $this->catchUp();
        $this->assertSame('20 * * * *', $event->expression);
        $this->assertStringContainsString('backup:run', $event->command);

        $this->backupAt('2026-09-25-02-00-14');
        $this->travelTo('2026-09-25 23:00');
        $this->assertFalse($event->filtersPass($this->app), 'fresh enough → nothing to do');

        $this->travelTo('2026-09-26 03:20');
        $this->assertTrue($event->filtersPass($this->app));
        $this->travelTo('2026-09-26 04:20');
        $this->assertFalse($event->filtersPass($this->app), 'a failing backup is retried every 6 h, not hourly');
        $this->travelTo('2026-09-26 09:20');
        $this->assertTrue($event->filtersPass($this->app));
        $this->assertSame('framework/schedule-ekdosi-backup-run', $event->mutexName());
        $this->assertSame($event->mutexName(), collect(app(Schedule::class)->events())
            ->firstOrFail(fn (Event $e) => $e->description === 'backup-run')->mutexName(), 'never two backup:run at once');

        Cache::flush();
        config(['ekdosi.schedule.backup_run_enabled' => false]);
        $this->assertFalse($event->filtersPass($this->app), 'backups switched off → no catch-up either');
    }

    public function test_scratch_dirs_stay_out_of_the_backup(): void
    {
        $exclude = config('backup.backup.source.files.exclude');
        $this->assertContains(storage_path('app/tmp'), $exclude);
        $this->assertContains(storage_path('app/livewire-tmp'), $exclude);
    }
}
