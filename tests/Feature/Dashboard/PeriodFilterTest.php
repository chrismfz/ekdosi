<?php

namespace Tests\Feature\Dashboard;

use App\Support\Dashboard\PeriodFilter;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The dashboard period-filter resolver — the single mapping from the
 * filter form's state to a concrete [start, end] window. Drives the
 * period cards + both charts.
 */
class PeriodFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-15 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_null_state_defaults_to_current_month(): void
    {
        $p = PeriodFilter::fromState(null);

        $this->assertSame('2026-05-01', $p->start->toDateString());
        $this->assertSame('2026-05-31', $p->end->toDateString());
        $this->assertSame('Τρέχων μήνας', $p->label);
        $this->assertSame(2026, $p->anchorYear());
    }

    public function test_last_month_quarter_and_year_windows(): void
    {
        $last = PeriodFilter::fromState(['period' => 'last_month']);
        $this->assertSame('2026-04-01', $last->start->toDateString());
        $this->assertSame('2026-04-30', $last->end->toDateString());

        $q = PeriodFilter::fromState(['period' => 'quarter']);
        $this->assertSame('2026-04-01', $q->start->toDateString());   // Q2
        $this->assertSame('2026-06-30', $q->end->toDateString());

        $y = PeriodFilter::fromState(['period' => 'year']);
        $this->assertSame('2026-01-01', $y->start->toDateString());
        $this->assertSame('2026-12-31', $y->end->toDateString());
        $this->assertSame('2026', $y->label);
    }

    public function test_custom_range_uses_given_dates(): void
    {
        $p = PeriodFilter::fromState(['period' => 'custom', 'from' => '2025-02-10', 'to' => '2025-08-20']);

        $this->assertSame('2025-02-10', $p->start->toDateString());
        $this->assertSame('2025-08-20', $p->end->toDateString());
        $this->assertSame(2025, $p->anchorYear());   // end year drives YoY anchor
    }

    public function test_custom_range_reversed_dates_are_swapped(): void
    {
        $p = PeriodFilter::fromState(['period' => 'custom', 'from' => '2025-08-20', 'to' => '2025-02-10']);

        $this->assertSame('2025-02-10', $p->start->toDateString());
        $this->assertSame('2025-08-20', $p->end->toDateString());
        $this->assertTrue($p->start->lt($p->end));
    }
}
