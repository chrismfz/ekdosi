<?php

namespace Tests\Feature\OperatorHealth;

use App\Support\OperatorHealth\HealthRecorder;
use App\Support\OperatorHealth\OperatorHealthReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    public function exits_critical_when_the_worker_heartbeat_is_missing(): void
    {
        // Fresh env: no queue heartbeat recorded → worker looks down → critical.
        $this->artisan('ops:health')->assertExitCode(2);
    }

    #[Test]
    public function exits_ok_when_healthy(): void
    {
        app(HealthRecorder::class)->recordQueueHeartbeat();

        $this->artisan('ops:health')->assertExitCode(0);
    }

    #[Test]
    public function json_output_carries_the_same_exit_code(): void
    {
        $this->artisan('ops:health', ['--json' => true])->assertExitCode(2);
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
