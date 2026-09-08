<?php

namespace Tests\Feature\Updates;

use App\Models\UpdateRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * UPD-001…015 (triage 2026-09-02) — applying code from inside the app is OFF by
 * default; the supported upgrade is `deploy/update.sh <tag>` on the host.
 *
 * The CHECK stays on. It is read-only and useful, and the health page now says
 * exactly which command to run rather than leaving the operator hunting for a
 * button that is deliberately not there.
 *
 * ONE flag gates the button, the rollback action and the command that does the
 * work — these tests pin that the COMMAND is the real choke point, since it is
 * the only thing that can actually mutate the tree, and a stale row queued before
 * the flag was flipped must still be refused.
 */
class InAppApplyDisarmedTest extends TestCase
{
    use RefreshDatabase;

    private function queuedRun(array $override = []): UpdateRun
    {
        return UpdateRun::create(array_merge([
            'status' => UpdateRun::STATUS_QUEUED,
            'kind' => UpdateRun::KIND_UPDATE,
            'strategy' => UpdateRun::STRATEGY_PHP,
            'to_ref' => 'v9.9.9',
            'to_version' => '9.9.9',
        ], $override));
    }

    #[Test]
    public function in_app_apply_is_off_by_default(): void
    {
        $this->assertFalse(UpdateRun::inAppApplyEnabled());
        $this->assertFalse((bool) config('ekdosi.updates.allow_in_app_apply'));
    }

    #[Test]
    public function a_queued_run_is_refused_and_marked_failed_not_left_spinning(): void
    {
        // The scheduler fires every minute while anything is queued. A silent skip
        // would loop forever and never tell the operator why nothing happened, so
        // the row is failed with the command they should run instead.
        $run = $this->queuedRun();

        // Exit 0, NOT 1: the RUN failed (recorded on the row), but the scheduled
        // TASK did exactly what it should. A non-zero exit makes $trackSchedule's
        // onFailure stamp `self_update => failed`, and since the task never runs
        // again once nothing is queued, ops:health would report a failed scheduled
        // task and exit 1 forever over a correct, intentional state.
        $this->artisan('ekdosi:self-update', ['--run' => $run->id])
            ->assertExitCode(0);

        $fresh = $run->fresh();
        $this->assertSame(UpdateRun::STATUS_FAILED, $fresh->status);
        $this->assertNotNull($fresh->finished_at);
        $this->assertStringContainsString('deploy/update.sh v9.9.9', (string) $fresh->error_message);
        $this->assertStringContainsString('deploy/update.sh v9.9.9', (string) $fresh->output);
    }

    #[Test]
    public function the_scheduler_entry_point_is_refused_too(): void
    {
        // --pending is how cron reaches this; it must hit the same gate.
        $run = $this->queuedRun();

        $this->artisan('ekdosi:self-update', ['--pending' => true])
            ->assertExitCode(0);

        $this->assertSame(UpdateRun::STATUS_FAILED, $run->fresh()->status);
    }

    #[Test]
    public function a_terminal_run_is_still_left_untouched(): void
    {
        // Order matters: the not-queued guard must run FIRST. Failing an already
        // SUCCEEDED run would rewrite history to report a failure that never
        // happened.
        $run = $this->queuedRun(['status' => UpdateRun::STATUS_SUCCEEDED]);

        $this->artisan('ekdosi:self-update', ['--run' => $run->id])
            ->assertExitCode(0);

        $fresh = $run->fresh();
        $this->assertSame(UpdateRun::STATUS_SUCCEEDED, $fresh->status);
        $this->assertNull($fresh->error_message);
    }

    #[Test]
    public function a_queued_rollback_is_refused_by_the_same_gate(): void
    {
        // The UI hides «Επαναφορά» when disarmed, but the guarantee is not the
        // hidden button: a rollback run reaches the SAME applier, so even a row
        // created some other way is refused. deploy/rollback.sh is the host path.
        $original = UpdateRun::create([
            'status' => UpdateRun::STATUS_SUCCEEDED,
            'kind' => UpdateRun::KIND_UPDATE,
            'strategy' => UpdateRun::STRATEGY_PHP,
            'from_ref' => 'v1.0.0',
            'snapshot_file' => 'snap.sql.gz',
        ]);

        // canRollback() stays a purely STRUCTURAL question — arming does not change
        // whether a given run is reversible, so the flag is deliberately not in it.
        $this->assertTrue($original->canRollback());

        $rollback = $this->queuedRun([
            'kind' => UpdateRun::KIND_ROLLBACK,
            'rollback_of_id' => $original->id,
            'to_ref' => 'v1.0.0',
        ]);

        $this->artisan('ekdosi:self-update', ['--run' => $rollback->id])
            ->assertExitCode(0);

        $this->assertSame(UpdateRun::STATUS_FAILED, $rollback->fresh()->status);
        $this->assertSame('disabled', $rollback->fresh()->phase);

        // The host command differs by KIND. Telling the operator to run
        // deploy/update.sh here would check out the old code WITHOUT restoring the
        // pre-update DB snapshot — a worse state than the one they are leaving.
        $this->assertStringContainsString('deploy/rollback.sh', (string) $rollback->fresh()->error_message);
        $this->assertStringNotContainsString('deploy/update.sh', (string) $rollback->fresh()->error_message);
    }

    #[Test]
    public function arming_the_flag_puts_the_apply_back(): void
    {
        // The machinery is disarmed, not deleted — a deploy that has fixed
        // UPD-001…004 can turn it back on without restoring code.
        config()->set('ekdosi.updates.allow_in_app_apply', true);

        $this->assertTrue(UpdateRun::inAppApplyEnabled());

        $run = $this->queuedRun();

        // It gets past the gate and into the real apply, which needs git/composer
        // we are not going to run here — the assertion is only that it is no
        // longer refused with the "disabled" phase.
        try {
            $this->artisan('ekdosi:self-update', ['--run' => $run->id])->run();
        } catch (\Throwable) {
            // A real apply attempt in a test tree may fail for any number of
            // reasons; what matters is WHY it stopped.
        }

        $this->assertNotSame('disabled', $run->fresh()->phase);
    }
}
