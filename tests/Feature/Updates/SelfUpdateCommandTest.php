<?php

namespace Tests\Feature\Updates;

use App\Models\UpdateRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The out-of-band applier's SAFE control paths — the early returns that run
 * BEFORE any git/composer/maintenance mutation. A full apply mutates the repo, so
 * it isn't exercised here; these guard the entry logic AND load the command class
 * (which is exactly what surfaced the `Command::fail()` visibility clash).
 */
class SelfUpdateCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_is_a_no_op_when_nothing_is_queued(): void
    {
        $this->artisan('ekdosi:self-update')
            ->expectsOutputToContain('Καμία εκκρεμής ενημέρωση')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_skips_a_run_that_is_not_queued_without_touching_it(): void
    {
        $run = UpdateRun::create([
            'status' => UpdateRun::STATUS_SUCCEEDED,
            'kind' => UpdateRun::KIND_UPDATE,
            'strategy' => UpdateRun::STRATEGY_PHP,
        ]);

        $this->artisan('ekdosi:self-update', ['--run' => $run->id])
            ->assertExitCode(0);

        // A non-queued run is left exactly as it was (no apply, no status change).
        $this->assertSame(UpdateRun::STATUS_SUCCEEDED, $run->fresh()->status);
    }
}
