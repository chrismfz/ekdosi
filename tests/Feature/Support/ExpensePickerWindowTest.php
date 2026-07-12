<?php

namespace Tests\Feature\Support;

use App\Filament\Support\ExpensePickerWindow;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Calendar-boundary math for the «Άντληση από myDATA» period presets. The
 * semester boundaries (built from startOfYear + addMonths, never a month()
 * setter) are the part most likely to drift, so pin both halves of the year.
 */
class ExpensePickerWindowTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: string, 1: string} d/m/Y from/to */
    private function fmt(string $period): array
    {
        [$from, $to] = ExpensePickerWindow::resolve($period);

        return [$from->format('d/m/Y'), $to->format('d/m/Y')];
    }

    public function test_presets_from_first_half_of_the_year(): void
    {
        Carbon::setTestNow('2026-03-15 10:30:00'); // Q1, H1

        $this->assertSame(['01/01/2026', '15/03/2026'], $this->fmt('quarter'));
        $this->assertSame(['01/10/2025', '31/12/2025'], $this->fmt('prev_quarter'));
        $this->assertSame(['01/01/2026', '15/03/2026'], $this->fmt('half'));      // H1 → today
        $this->assertSame(['01/07/2025', '31/12/2025'], $this->fmt('prev_half')); // prev = H2 last year
        $this->assertSame(['01/01/2026', '15/03/2026'], $this->fmt('year'));
        $this->assertSame(['01/01/2025', '31/12/2025'], $this->fmt('prev_year'));
    }

    public function test_presets_from_second_half_of_the_year(): void
    {
        Carbon::setTestNow('2026-09-20 10:30:00'); // Q3, H2

        $this->assertSame(['01/07/2026', '20/09/2026'], $this->fmt('quarter'));   // Q3 → today
        $this->assertSame(['01/04/2026', '30/06/2026'], $this->fmt('prev_quarter'));
        $this->assertSame(['01/07/2026', '20/09/2026'], $this->fmt('half'));      // H2 → today
        $this->assertSame(['01/01/2026', '30/06/2026'], $this->fmt('prev_half')); // prev = H1 this year
        $this->assertSame(['01/01/2026', '20/09/2026'], $this->fmt('year'));
        $this->assertSame(['01/01/2025', '31/12/2025'], $this->fmt('prev_year'));
    }

    public function test_semester_boundary_does_not_overflow_on_a_31st(): void
    {
        // 31 Aug: a naive month(6) setter would overflow June → 1 Jul. Guard it.
        Carbon::setTestNow('2026-08-31 23:00:00'); // H2

        $this->assertSame(['01/01/2026', '30/06/2026'], $this->fmt('prev_half'));
    }

    public function test_unknown_preset_falls_back_to_current_quarter(): void
    {
        Carbon::setTestNow('2026-05-10 09:00:00'); // Q2

        $this->assertSame(['01/04/2026', '10/05/2026'], $this->fmt('bogus'));
    }
}
