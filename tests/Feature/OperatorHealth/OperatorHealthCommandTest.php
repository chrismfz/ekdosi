<?php

namespace Tests\Feature\OperatorHealth;

use App\Support\OperatorHealth\HealthKeys;
use App\Support\OperatorHealth\HealthRecorder;
use App\Support\OperatorHealth\OperatorHealthReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * OPS-4 (AUDIT): `ops:health` must return a real exit code (0/1/2), not a
 * constant 0 — otherwise the deploy gate and cron `ops:health || alert` are
 * dead code.
 */
class OperatorHealthCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function exits_warning_when_the_worker_heartbeat_is_missing(): void
    {
        // Cron alive but no worker heartbeat yet → warning (not critical — could be
        // a fresh box). Record the scheduler tick so the missing beat is attributed
        // to the WORKER, not the cron.
        app(HealthRecorder::class)->recordSchedulerHeartbeat();

        $this->artisan('ops:health')->assertExitCode(1);
    }

    #[Test]
    public function exits_critical_when_the_worker_heartbeat_is_long_silent(): void
    {
        // Cron IS firing (fresh scheduler tick) but the worker heartbeat is silent
        // >30 min → a real worker outage → critical.
        app(HealthRecorder::class)->recordSchedulerHeartbeat();
        Cache::forever(HealthKeys::QUEUE_HEARTBEAT, now()->subMinutes(45)->toIso8601String());

        $this->artisan('ops:health')->assertExitCode(2);
    }

    #[Test]
    public function exits_critical_when_the_cron_tick_is_long_silent(): void
    {
        // OPS-001: the OS cron stopped calling schedule:run (tick silent >30 min) —
        // nothing scheduled runs → critical, independently of the worker.
        Cache::forever(HealthKeys::SCHEDULER_HEARTBEAT, now()->subMinutes(45)->toIso8601String());
        app(HealthRecorder::class)->recordQueueHeartbeat();

        $this->artisan('ops:health')->assertExitCode(2);
    }

    #[Test]
    public function exits_warning_when_the_cron_tick_was_never_recorded(): void
    {
        // A never-seen scheduler tick (fresh box, cron not wired yet) is a warning,
        // not a crash — and it must not falsely blame the worker.
        app(HealthRecorder::class)->recordQueueHeartbeat();

        $this->artisan('ops:health')->assertExitCode(1);
    }

    #[Test]
    public function exits_ok_when_healthy(): void
    {
        app(HealthRecorder::class)->recordSchedulerHeartbeat();
        app(HealthRecorder::class)->recordQueueHeartbeat();

        $this->artisan('ops:health')->assertExitCode(0);
    }

    #[Test]
    public function json_output_carries_the_same_exit_code(): void
    {
        // No heartbeat → warning exit 1.
        $this->artisan('ops:health', ['--json' => true])->assertExitCode(1);
    }

    #[Test]
    public function newly_tracked_scheduled_tasks_are_visible_in_the_report(): void
    {
        // OPS-8: the unattended AADE filer + the per-tenant backups were invisible
        // to health. They must now appear in the scheduler slice with a label.
        $scheduler = app(OperatorHealthReport::class)->build()['scheduler'];

        foreach (['whmcs_auto_issue', 'company_backups', 'resend_failed_emails', 'service_dunning'] as $key) {
            $this->assertArrayHasKey($key, $scheduler, "scheduler missing tracked task {$key}");
            $this->assertNotEmpty($scheduler[$key]['label']);
        }
    }
}
