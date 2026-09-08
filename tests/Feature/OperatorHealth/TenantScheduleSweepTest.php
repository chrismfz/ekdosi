<?php

namespace Tests\Feature\OperatorHealth;

use App\Support\OperatorHealth\TenantScheduleSweep;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * OPS-13: the resilience guarantee — one tenant's uncaught exception must NOT
 * abort the sweep for the rest, and must be handed to $onError so a per-tenant
 * failure is recorded instead of leaving a stale «ok». This is the whole point
 * of the item; without a test a future refactor could silently drop the
 * isolation and the suite would stay green.
 */
class TenantScheduleSweepTest extends TestCase
{
    private function tenant(string $slug): object
    {
        return new class($slug)
        {
            public function __construct(public string $slug) {}
        };
    }

    #[Test]
    public function one_tenants_uncaught_exception_does_not_abort_the_others(): void
    {
        Cache::forever('sweep_probe_ran', 0);

        // A probe command that throws for the «boom» tenant, succeeds otherwise.
        Artisan::command('test:sweep-probe {--tenant=}', function () {
            if ($this->option('tenant') === 'boom') {
                throw new \RuntimeException('kaboom');
            }
            Cache::increment('sweep_probe_ran');
        });

        $errors = [];
        $failed = app(TenantScheduleSweep::class)->run(
            [$this->tenant('boom'), $this->tenant('ok-1'), $this->tenant('ok-2')],
            'test:sweep-probe',
            function (object $t) use (&$errors) {
                $errors[] = $t->slug;
            },
        );

        // The thrower was recorded via $onError…
        $this->assertSame(['boom'], $errors);
        $this->assertSame(1, $failed);
        // …and the sweep still ran BOTH later tenants (didn't abort on «boom»).
        $this->assertSame(2, Cache::get('sweep_probe_ran'));
    }

    #[Test]
    public function a_clean_sweep_returns_zero_and_calls_on_error_never(): void
    {
        Artisan::command('test:sweep-ok {--tenant=}', fn () => 0);

        $errors = [];
        $failed = app(TenantScheduleSweep::class)->run(
            [$this->tenant('a'), $this->tenant('b')],
            'test:sweep-ok',
            function (object $t) use (&$errors) {
                $errors[] = $t->slug;
            },
        );

        $this->assertSame(0, $failed);
        $this->assertSame([], $errors);
    }
}
