<?php

namespace Tests\Feature\Ops;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * OPS-6b — `ops:queue-drain`: the privilege-free queue drain used by
 * deploy/update.sh (and rollback.sh) when `systemctl stop` is unavailable or
 * denied — cPanel/DirectAdmin/shared hosting, a cron-driven worker, or a
 * deploy user without sudo. Exit 0 = idle, 1 = a job is still running.
 */
class QueueDrainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        parent::tearDown();
    }

    private function job(?string $reservedAt): int
    {
        return DB::table('jobs')->insertGetId([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => $reservedAt === null ? null : strtotime($reservedAt),
            'available_at' => time(),
            'created_at' => time(),
        ]);
    }

    public function test_an_idle_queue_passes_immediately(): void
    {
        config(['queue.default' => 'database']);
        $this->job(null); // waiting, not running — nothing to drain

        $this->artisan('ops:queue-drain --timeout=10')
            ->expectsOutputToContain('ήδη ήσυχη')
            ->assertExitCode(0);

        Sleep::assertSlept(fn () => true, 0);
    }

    public function test_a_sync_connection_needs_no_drain(): void
    {
        config(['queue.default' => 'sync']);

        $this->artisan('ops:queue-drain')
            ->expectsOutputToContain('δεν χρειάζεται drain')
            ->assertExitCode(0);
    }

    public function test_it_waits_for_an_in_flight_job_and_passes_when_it_finishes(): void
    {
        config(['queue.default' => 'database']);
        $id = $this->job('-1 minute');

        // The worker finishes the job while we are waiting.
        Sleep::whenFakingSleep(function () use ($id): void {
            DB::table('jobs')->where('id', $id)->delete();
        });

        $this->artisan('ops:queue-drain --timeout=30')
            ->expectsOutputToContain('Αναμονή να τελειώσουν 1 job')
            ->expectsOutputToContain('Η ουρά άδειασε')
            ->assertExitCode(0);
    }

    public function test_a_job_that_never_finishes_fails_so_the_deploy_aborts(): void
    {
        config(['queue.default' => 'database']);
        $this->job('-10 minutes'); // a long import, still held

        $this->artisan('ops:queue-drain --timeout=6')
            ->expectsOutputToContain('ΜΗΝ κάνεις migrate')
            ->assertExitCode(1);

        // It really waited the whole timeout rather than giving up at once.
        Sleep::assertSleptTimes(3); // 6s / POLL_SECONDS
    }

    public function test_a_stale_reserved_row_can_be_overridden_so_deploys_are_never_stuck(): void
    {
        // A hard-killed worker leaves reserved_at set until retry_after elapses;
        // without an override that row would block every deploy forever.
        config(['queue.default' => 'database']);
        $this->job('-10 minutes');

        $this->artisan('ops:queue-drain --timeout=4 --assume-idle')
            ->expectsOutputToContain('με δική σου ευθύνη')
            ->assertExitCode(0);
    }

    public function test_a_driver_we_cannot_inspect_refuses_rather_than_green_lighting_a_migrate(): void
    {
        config(['queue.default' => 'redis', 'queue.connections.redis.driver' => 'redis']);

        // We cannot PROVE the queue is idle → the deploy must not proceed…
        $this->artisan('ops:queue-drain --timeout=60')
            ->expectsOutputToContain('Δεν μπορώ να εγγυηθώ')
            ->assertExitCode(1);

        // …unless the operator explicitly accepts the risk.
        $this->artisan('ops:queue-drain --timeout=60 --assume-idle')
            ->expectsOutputToContain('assume-idle')
            ->assertExitCode(0);
    }
}
