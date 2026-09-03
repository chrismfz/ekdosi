<?php

namespace Tests\Feature\OperatorHealth;

use App\Support\OperatorHealth\HealthRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * OPS-001 / OPS-003: `ops:cron` prints the host-specific crontab + worker recipe
 * so a fresh cPanel/VPS install knows exactly what to paste.
 */
class OpsCronCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_prints_the_cron_line_and_worker_recipe(): void
    {
        $this->artisan('ops:cron')
            ->assertExitCode(0)
            ->expectsOutputToContain('artisan schedule:run')
            ->expectsOutputToContain('queue:work');
    }

    #[Test]
    public function json_carries_the_real_php_binary_app_path_and_cron_line(): void
    {
        Artisan::call('ops:cron', ['--json' => true]);
        $out = json_decode(Artisan::output(), true);

        $this->assertIsArray($out);
        $this->assertSame(PHP_BINARY ?: 'php', $out['php_binary']);
        $this->assertSame(base_path(), $out['app_path']);
        $this->assertStringContainsString('schedule:run', $out['cron_line']);
        $this->assertStringContainsString('--stop-when-empty', $out['shared_hosting_worker_cron']);
        // The live state is echoed so the operator sees what's missing.
        $this->assertArrayHasKey('cron', $out['state']);
        $this->assertArrayHasKey('queue', $out['state']);
    }

    #[Test]
    public function json_state_reflects_a_recorded_scheduler_tick(): void
    {
        app(HealthRecorder::class)->recordSchedulerHeartbeat();

        Artisan::call('ops:cron', ['--json' => true]);
        $out = json_decode(Artisan::output(), true);

        $this->assertSame('ok', $out['state']['cron']['status']);
    }
}
