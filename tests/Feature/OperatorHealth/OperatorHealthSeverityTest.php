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
            'queue' => ['worker_heartbeat_status' => 'ok', 'failed_jobs' => 0],
            'backup' => [
                'monitor' => ['status' => 'ok'],
                'companies' => ['offsite_gap' => false, 'books_gap' => false],
            ],
            'scheduler' => [
                'whmcs_fetch' => ['label' => 'WHMCS fetch', 'status' => 'ok'],
                'backup_run' => ['label' => 'backup run', 'status' => 'missing'], // never ran ≠ failed
            ],
            'mail' => ['failed_24h' => 0],
            'whmcs' => [['tenant' => 'a', 'status' => 'ok']],
            'mydata' => [['tenant' => 'a', 'status' => 'ok', 'discrepancies' => 0]],
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
    public function missing_worker_heartbeat_is_critical_exit_two(): void
    {
        $data = $this->healthy();
        $data['queue']['worker_heartbeat_status'] = 'missing';

        $s = OperatorHealthSeverity::evaluate($data);

        $this->assertSame('critical', $s['level']);
        $this->assertSame(2, $s['exit_code']);
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
        $data['queue']['failed_jobs'] = 3;

        $s = OperatorHealthSeverity::evaluate($data);

        $this->assertSame('warning', $s['level']);
        $this->assertSame(1, $s['exit_code']);
    }

    #[Test]
    public function critical_outranks_warning(): void
    {
        $data = $this->healthy();
        $data['queue']['worker_heartbeat_status'] = 'stale'; // critical
        $data['mail']['failed_24h'] = 5;                     // warning

        $s = OperatorHealthSeverity::evaluate($data);

        $this->assertSame('critical', $s['level']);
        $this->assertSame(2, $s['exit_code']);
        $this->assertNotEmpty($s['warnings']); // warnings still collected alongside
    }
}
