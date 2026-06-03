<?php

namespace Tests\Feature\MyData;

use App\Filament\Pages\Concerns\ResolvesReconcileWindow;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The myDATA-console preset selector resolves to CALENDAR (= φορολογικά)
 * boundaries. Pinned to a fixed "now" so the quarter math is deterministic.
 */
class ReconcileWindowPresetTest extends TestCase
{
    /** Harness exposing the protected trait method. */
    private object $page;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-05-15 → Q2 (Apr–Jun); previous quarter Q1 (Jan–Mar).
        Carbon::setTestNow('2026-05-15 10:00:00');

        $this->page = new class
        {
            use ResolvesReconcileWindow;

            /** @param array<string,mixed> $data @return array{0:string,1:string} */
            public function resolve(array $data): array
            {
                return $this->resolveWindow($data);
            }
        };
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_month_runs_from_start_of_month_to_today(): void
    {
        $this->assertSame(['2026-05-01', '2026-05-15'], $this->page->resolve(['preset' => 'month']));
    }

    public function test_quarter_runs_from_start_of_quarter_to_today(): void
    {
        $this->assertSame(['2026-04-01', '2026-05-15'], $this->page->resolve(['preset' => 'quarter']));
    }

    public function test_previous_quarter_is_the_full_prior_calendar_quarter(): void
    {
        $this->assertSame(['2026-01-01', '2026-03-31'], $this->page->resolve(['preset' => 'prev_quarter']));
    }

    public function test_year_runs_from_start_of_year_to_today(): void
    {
        $this->assertSame(['2026-01-01', '2026-05-15'], $this->page->resolve(['preset' => 'year']));
    }

    public function test_custom_uses_the_supplied_dates(): void
    {
        $this->assertSame(
            ['2026-02-10', '2026-02-20'],
            $this->page->resolve(['preset' => 'custom', 'from' => '2026-02-10', 'to' => '2026-02-20']),
        );
    }

    public function test_unknown_preset_falls_back_to_quarter(): void
    {
        $this->assertSame(['2026-04-01', '2026-05-15'], $this->page->resolve(['preset' => 'bogus']));
    }
}
