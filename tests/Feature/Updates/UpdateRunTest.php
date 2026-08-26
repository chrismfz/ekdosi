<?php

namespace Tests\Feature\Updates;

use App\Models\UpdateRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The `UpdateRun` state machine — the gates the scheduler + UI rely on
 * (`hasPending`, `hasActive`, `canRollback`), including the "only the LATEST
 * finished update is reversible" safety rule.
 */
class UpdateRunTest extends TestCase
{
    use RefreshDatabase;

    private function make(array $attrs = []): UpdateRun
    {
        return UpdateRun::create(array_merge([
            'status' => UpdateRun::STATUS_QUEUED,
            'kind' => UpdateRun::KIND_UPDATE,
            'strategy' => UpdateRun::STRATEGY_PHP,
        ], $attrs));
    }

    #[Test]
    public function has_pending_is_true_only_while_a_run_is_queued(): void
    {
        $this->assertFalse(UpdateRun::hasPending());

        $run = $this->make(['status' => UpdateRun::STATUS_QUEUED]);
        $this->assertTrue(UpdateRun::hasPending());

        $run->update(['status' => UpdateRun::STATUS_RUNNING]);
        $this->assertFalse(UpdateRun::hasPending());   // running is not "pending"

        $run->update(['status' => UpdateRun::STATUS_SUCCEEDED]);
        $this->assertFalse(UpdateRun::hasPending());
    }

    #[Test]
    public function has_active_covers_queued_and_running_but_not_terminal(): void
    {
        $run = $this->make(['status' => UpdateRun::STATUS_QUEUED]);
        $this->assertTrue(UpdateRun::hasActive());

        $run->update(['status' => UpdateRun::STATUS_RUNNING]);
        $this->assertTrue(UpdateRun::hasActive());

        $run->update(['status' => UpdateRun::STATUS_SUCCEEDED]);
        $this->assertFalse(UpdateRun::hasActive());
    }

    #[Test]
    public function rollback_active_and_terminal_flags(): void
    {
        $update = $this->make(['status' => UpdateRun::STATUS_RUNNING, 'kind' => UpdateRun::KIND_UPDATE]);
        $this->assertFalse($update->isRollback());
        $this->assertTrue($update->isActive());
        $this->assertFalse($update->isTerminal());

        $rollback = $this->make(['status' => UpdateRun::STATUS_SUCCEEDED, 'kind' => UpdateRun::KIND_ROLLBACK]);
        $this->assertTrue($rollback->isRollback());
        $this->assertFalse($rollback->isActive());
        $this->assertTrue($rollback->isTerminal());
    }

    #[Test]
    public function can_rollback_only_the_latest_finished_update(): void
    {
        $older = $this->make([
            'status' => UpdateRun::STATUS_SUCCEEDED,
            'snapshot_file' => '/tmp/snap-1.sql.gz',
            'from_ref' => 'v1.0.0',
        ]);
        $this->assertTrue($older->canRollback());

        // A newer UPDATE exists → the older one is no longer reversible (its restore
        // would rewind the whole DB past the newer update too).
        $newer = $this->make([
            'status' => UpdateRun::STATUS_SUCCEEDED,
            'snapshot_file' => '/tmp/snap-2.sql.gz',
            'from_ref' => 'v1.1.0',
        ]);
        $this->assertFalse($older->fresh()->canRollback());
        $this->assertTrue($newer->canRollback());
    }

    #[Test]
    public function can_rollback_needs_a_snapshot_and_a_source_ref(): void
    {
        $noSnap = $this->make(['status' => UpdateRun::STATUS_SUCCEEDED, 'from_ref' => 'v1', 'snapshot_file' => null]);
        $this->assertFalse($noSnap->canRollback());

        $noRef = $this->make(['status' => UpdateRun::STATUS_SUCCEEDED, 'snapshot_file' => '/tmp/s.sql.gz', 'from_ref' => null]);
        $this->assertFalse($noRef->canRollback());
    }

    #[Test]
    public function can_rollback_is_blocked_while_another_run_is_active(): void
    {
        $done = $this->make([
            'status' => UpdateRun::STATUS_SUCCEEDED,
            'snapshot_file' => '/tmp/s.sql.gz',
            'from_ref' => 'v1',
        ]);

        $this->make(['status' => UpdateRun::STATUS_RUNNING]);   // single-flight in progress

        $this->assertFalse($done->fresh()->canRollback());
    }

    #[Test]
    public function a_rollback_kind_run_is_never_itself_rollbackable(): void
    {
        $rb = $this->make([
            'status' => UpdateRun::STATUS_SUCCEEDED,
            'kind' => UpdateRun::KIND_ROLLBACK,
            'snapshot_file' => '/tmp/s.sql.gz',
            'from_ref' => 'v1',
        ]);
        $this->assertFalse($rb->canRollback());
    }

    #[Test]
    public function duration_seconds_needs_both_timestamps(): void
    {
        $run = $this->make([
            'status' => UpdateRun::STATUS_SUCCEEDED,
            'started_at' => now()->subSeconds(30),
            'finished_at' => now(),
        ]);
        $this->assertEqualsWithDelta(30, $run->durationSeconds(), 2);

        $open = $this->make(['status' => UpdateRun::STATUS_RUNNING, 'started_at' => now()]);
        $this->assertNull($open->durationSeconds());
    }
}
