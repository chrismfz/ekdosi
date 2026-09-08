<?php

namespace Tests\Feature\OperatorHealth;

use App\Support\OperatorHealth\OperatorHealthSeverity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * OPS-4 (AUDIT): ops:health distils the full report into ONE severity + exit
 * code so the deploy gate and cron `ops:health || alert` stop being dead code.
 * Pure function → no DB.
 */
class OperatorHealthSeverityTest extends TestCase
{
    /** A fully-healthy report skeleton; individual tests dirty one slice. */
    private function healthy(): array
    {
        return [
            'cron' => ['status' => 'ok', 'age_minutes' => 1],
            'queue' => ['worker_heartbeat_status' => 'ok', 'worker_heartbeat_age_minutes' => 2, 'failed_jobs_24h' => 0],
            'backup' => [
                'monitor' => ['status' => 'ok'],
                'companies' => ['offsite_gap' => false, 'books_gap' => false],
            ],
            'scheduler' => [
                'whmcs_fetch' => ['label' => 'WHMCS fetch', 'status' => 'ok'],
                'backup_run' => ['label' => 'backup run', 'status' => 'missing'], // never ran ≠ failed
            ],
            'mail' => ['failed_24h' => 0, 'stuck_queued_or_sending' => 0],
            'whmcs' => [['tenant' => 'a', 'status' => 'ok']],
            'mydata' => [['tenant' => 'a', 'status' => 'ok', 'discrepancies' => 0]],
            'disk' => ['storage' => ['free_bytes' => 50, 'total_bytes' => 100]],
        ];
    }

    #[Test]
    public function all_clear_is_ok_exit_zero(): void
    {
        $s = OperatorHealthSeverity::evaluate($this->healthy());

        $this->assertSame('ok', $s['level']);
        $this->assertSame(0, $s['exit_code']);
        $this->assertSame([], $s['critical']);
        $this->assertSame([], $s['warnings']);
    }

    #[Test]
    public function missing_heartbeat_is_only_a_warning(): void
    {
        // A never-seen heartbeat (fresh box / cache cleared) is not proof of an
        // outage → warning, not critical.
        $data = $this->healthy();
        $data['queue']['worker_heartbeat_status'] = 'missing';
        $data['queue']['worker_heartbeat_age_minutes'] = null;

        $s = OperatorHealthSeverity::evaluate($data);

        $this->assertSame('warning', $s['level']);
        $this->assertSame(1, $s['exit_code']);
    }

    #[Test]
    public function briefly_stale_heartbeat_is_a_warning_but_long_silence_is_critical(): void
    {
        // Right after a deploy the worker was stopped for the window → a briefly
        // stale beat is expected (warning). Only a long silence (>30 min) is a
        // real down (critical).
        $warn = $this->healthy();
        $warn['queue']['worker_heartbeat_status'] = 'stale';
        $warn['queue']['worker_heartbeat_age_minutes'] = 15;
        $this->assertSame('warning', OperatorHealthSeverity::evaluate($warn)['level']);

        $crit = $this->healthy();
        $crit['queue']['worker_heartbeat_status'] = 'stale';
        $crit['queue']['worker_heartbeat_age_minutes'] = 45;
        $this->assertSame('critical', OperatorHealthSeverity::evaluate($crit)['level']);
    }

    #[Test]
    public function missing_cron_tick_is_only_a_warning(): void
    {
        // OPS-001: a never-recorded scheduler tick (fresh box, cron not wired) is a
        // warning, not proof of an outage.
        $data = $this->healthy();
        $data['cron'] = ['status' => 'missing', 'age_minutes' => null];

        $s = OperatorHealthSeverity::evaluate($data);

        $this->assertSame('warning', $s['level']);
        $this->assertSame(1, $s['exit_code']);
    }

    #[Test]
    public function long_silent_cron_tick_is_critical(): void
    {
        // The OS cron stopped calling schedule:run → nothing scheduled runs.
        $data = $this->healthy();
        $data['cron'] = ['status' => 'stale', 'age_minutes' => 45];

        $s = OperatorHealthSeverity::evaluate($data);

        $this->assertSame('critical', $s['level']);
        $this->assertSame(2, $s['exit_code']);
    }

    #[Test]
    public function a_stale_worker_beat_is_not_blamed_on_the_worker_when_cron_is_down(): void
    {
        // The queue heartbeat is dispatched BY schedule:run, so when cron is down a
        // stale worker beat is a consequence, not independent proof of a dead worker.
        // Only the cron finding should fire (critical), with no separate «worker down».
        $data = $this->healthy();
        $data['cron'] = ['status' => 'stale', 'age_minutes' => 45];
        $data['queue']['worker_heartbeat_status'] = 'stale';
        $data['queue']['worker_heartbeat_age_minutes'] = 45;

        $s = OperatorHealthSeverity::evaluate($data);

        $this->assertSame('critical', $s['level']);
        // Exactly one critical (the cron), not two.
        $this->assertCount(1, $s['critical']);
        $this->assertStringContainsString('cron', $s['critical'][0]);
    }

    #[Test]
    public function a_stale_worker_beat_i_s_the_worker_when_cron_is_alive(): void
    {
        // Cron ticking (ok) but the worker heartbeat silent >30 min → the worker is
        // genuinely down → critical, attributed to the worker.
        $data = $this->healthy();
        $data['queue']['worker_heartbeat_status'] = 'stale';
        $data['queue']['worker_heartbeat_age_minutes'] = 45;

        $s = OperatorHealthSeverity::evaluate($data);

        $this->assertSame('critical', $s['level']);
        $this->assertCount(1, $s['critical']);
        $this->assertStringContainsString('worker', $s['critical'][0]);
    }

    #[Test]
    public function a_worker_outage_older_than_the_cron_gap_stays_critical(): void
    {
        // Regression guard (review finding #1): the worker crashed 45 min ago
        // (critical); the cron only fell behind 20 min ago (a WARNING, age ≤ 30).
        // The worker beat (45') is staler than the cron gap (20') → the worker was
        // already failing while cron was alive → a real worker outage that must NOT
        // be downgraded to a warning just because the cron is also late.
        $data = $this->healthy();
        $data['cron'] = ['status' => 'stale', 'age_minutes' => 20];
        $data['queue']['worker_heartbeat_status'] = 'stale';
        $data['queue']['worker_heartbeat_age_minutes'] = 45;

        $s = OperatorHealthSeverity::evaluate($data);

        $this->assertSame('critical', $s['level']);
        $this->assertSame(2, $s['exit_code']);
        // Both the cron (warning) and the worker (critical) are reported.
        $this->assertCount(1, $s['critical']);
        $this->assertStringContainsString('worker', strtolower($s['critical'][0]));
    }

    #[Test]
    public function a_sustained_cron_outage_does_not_false_alarm_the_worker(): void
    {
        // Review round-3: during a long cron outage a perfectly healthy (idle) worker's
        // beat naturally sits at ~cronAge + (0..5) min — its last beat was up to one
        // 5-min dispatch old when cron died. That must NOT be reported as a worker
        // outage (that's the exact «blame the worker for a dead cron» bug this feature
        // removes). Only the cron critical fires; no second worker critical.
        $data = $this->healthy();
        $data['cron'] = ['status' => 'stale', 'age_minutes' => 40];   // cron down 40'
        $data['queue']['worker_heartbeat_status'] = 'stale';
        $data['queue']['worker_heartbeat_age_minutes'] = 43;          // = cronAge + 3 (healthy idle)

        $s = OperatorHealthSeverity::evaluate($data);

        $this->assertSame('critical', $s['level']);       // the CRON is critically down
        $this->assertCount(1, $s['critical']);            // …and ONLY the cron — not a phantom worker
        $this->assertStringContainsString('cron', $s['critical'][0]);
    }

    #[Test]
    public function failed_backup_monitor_is_critical(): void
    {
        $data = $this->healthy();
        $data['backup']['monitor']['status'] = 'failed';

        $s = OperatorHealthSeverity::evaluate($data);

        $this->assertSame('critical', $s['level']);
        $this->assertSame(2, $s['exit_code']);
    }

    #[Test]
    public function books_gap_and_offsite_gap_are_warnings_exit_one(): void
    {
        $data = $this->healthy();
        $data['backup']['companies']['books_gap'] = true;
        $data['backup']['companies']['offsite_gap'] = true;

        $s = OperatorHealthSeverity::evaluate($data);

        $this->assertSame('warning', $s['level']);
        $this->assertSame(1, $s['exit_code']);
        $this->assertCount(2, $s['warnings']);
    }

    #[Test]
    public function failed_scheduled_task_and_failed_jobs_warn(): void
    {
        $data = $this->healthy();
        $data['scheduler']['whmcs_fetch']['status'] = 'failed';
        $data['queue']['failed_jobs_24h'] = 3;

        $s = OperatorHealthSeverity::evaluate($data);

        $this->assertSame('warning', $s['level']);
        $this->assertSame(1, $s['exit_code']);
    }

    #[Test]
    public function a_stale_per_tenant_sweep_warns(): void
    {
        // OPS-13: an ENABLED sweep that stopped recording is stale → warning,
        // even though its last status was «ok».
        $data = $this->healthy();
        $data['whmcs'] = [['tenant' => 'a', 'status' => 'ok', 'stale' => true]];
        $data['mydata'] = [['tenant' => 'b', 'status' => 'ok', 'discrepancies' => 0, 'stale' => true]];

        $s = OperatorHealthSeverity::evaluate($data);

        $this->assertSame('warning', $s['level']);
        $this->assertSame(1, $s['exit_code']);
        $this->assertCount(2, $s['warnings']); // one per surface
    }

    #[Test]
    public function failed_takes_precedence_over_stale(): void
    {
        // A row that last recorded a failure long ago is warned as «failed»
        // (actionable), not double-counted as stale.
        $data = $this->healthy();
        $data['mydata'] = [['tenant' => 'b', 'status' => 'failed', 'discrepancies' => 0, 'stale' => true]];

        $s = OperatorHealthSeverity::evaluate($data);

        $this->assertSame('warning', $s['level']);
        $this->assertCount(1, $s['warnings']);
        $this->assertStringContainsString('απέτυχε', $s['warnings'][0]);
    }

    #[Test]
    public function nearly_full_disk_warns_and_full_disk_is_critical(): void
    {
        $warn = $this->healthy();
        $warn['disk']['storage'] = ['free_bytes' => 4, 'total_bytes' => 100]; // 4% free
        $this->assertSame('warning', OperatorHealthSeverity::evaluate($warn)['level']);

        $crit = $this->healthy();
        $crit['disk']['storage'] = ['free_bytes' => 1, 'total_bytes' => 100]; // 1% free
        $this->assertSame('critical', OperatorHealthSeverity::evaluate($crit)['level']);
    }

    #[Test]
    public function worst_disk_area_drives_the_verdict(): void
    {
        $data = $this->healthy();
        $data['disk'] = [
            'storage' => ['free_bytes' => 90, 'total_bytes' => 100], // fine
            'backups' => ['free_bytes' => 1, 'total_bytes' => 100],  // full (separate mount)
        ];

        $this->assertSame('critical', OperatorHealthSeverity::evaluate($data)['level']);
    }

    #[Test]
    public function stuck_mail_queue_warns(): void
    {
        $data = $this->healthy();
        $data['mail']['stuck_queued_or_sending'] = 12;

        $s = OperatorHealthSeverity::evaluate($data);

        $this->assertSame('warning', $s['level']);
        $this->assertSame(1, $s['exit_code']);
    }

    #[Test]
    public function critical_outranks_warning(): void
    {
        $data = $this->healthy();
        $data['queue']['worker_heartbeat_status'] = 'stale'; // critical (long silence)
        $data['queue']['worker_heartbeat_age_minutes'] = 60;
        $data['mail']['failed_24h'] = 5;                     // warning

        $s = OperatorHealthSeverity::evaluate($data);

        $this->assertSame('critical', $s['level']);
        $this->assertSame(2, $s['exit_code']);
        $this->assertNotEmpty($s['warnings']); // warnings still collected alongside
    }
}
