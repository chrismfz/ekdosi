<?php

namespace Tests\Feature\OperatorHealth;

use App\Models\ScheduledTaskRun;
use App\Support\OperatorHealth\HealthRecorder;
use App\Support\OperatorHealth\OperatorHealthReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Slice 2: the durable `scheduled_task_runs` log behind the cache snapshot.
 * A 'running' open + a terminal close = one finished row with a duration; the
 * report surfaces it (and the queue pending count).
 */
class ScheduledTaskRunHistoryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function running_then_ok_produces_one_closed_run(): void
    {
        $recorder = app(HealthRecorder::class);
        $recorder->recordScheduledRun('whmcs_fetch', 'running');
        $recorder->recordScheduledRun('whmcs_fetch', 'ok', 0);

        $this->assertSame(1, ScheduledTaskRun::where('task', 'whmcs_fetch')->count());
        $run = ScheduledTaskRun::where('task', 'whmcs_fetch')->first();
        $this->assertSame('ok', $run->status);
        $this->assertSame(0, $run->exit_code);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $this->assertNotNull($run->duration_ms);
    }

    #[Test]
    public function a_terminal_status_without_an_open_row_stands_alone(): void
    {
        app(HealthRecorder::class)->recordScheduledRun('backup_monitor', 'failed', 1);

        $run = ScheduledTaskRun::where('task', 'backup_monitor')->sole();
        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->finished_at); // closed immediately
    }

    #[Test]
    public function history_is_pruned_per_task(): void
    {
        $recorder = app(HealthRecorder::class);
        for ($i = 0; $i < 55; $i++) {
            $recorder->recordScheduledRun('mail_sweep', 'ok', 0);
        }

        // RUN_HISTORY_KEEP = 50 — keeps exactly the newest 50 (without prune it'd be 55).
        $this->assertSame(50, ScheduledTaskRun::where('task', 'mail_sweep')->count());
    }

    #[Test]
    public function the_report_exposes_pending_jobs_and_recent_runs(): void
    {
        app(HealthRecorder::class)->recordScheduledRun('mydata_reconcile', 'running');
        app(HealthRecorder::class)->recordScheduledRun('mydata_reconcile', 'ok', 0);

        $report = app(OperatorHealthReport::class)->build();

        $this->assertArrayHasKey('pending_jobs', $report['queue']);
        $this->assertNotEmpty($report['recent_runs']);
        $this->assertSame('myDATA reconcile', $report['recent_runs'][0]['label']);
    }
}
