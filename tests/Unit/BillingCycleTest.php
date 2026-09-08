<?php

namespace Tests\Unit;

use App\Enums\BillingCycle;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class BillingCycleTest extends TestCase
{
    public function test_one_time_never_advances(): void
    {
        $this->assertNull(BillingCycle::OneTime->months());
        $this->assertNull(BillingCycle::OneTime->advance(Carbon::parse('2026-01-15')));
        $this->assertFalse(BillingCycle::OneTime->isRecurring());
        $this->assertTrue(BillingCycle::Monthly->isRecurring());
    }

    public function test_months_per_cycle(): void
    {
        $this->assertSame(1, BillingCycle::Monthly->months());
        $this->assertSame(3, BillingCycle::Quarterly->months());
        $this->assertSame(6, BillingCycle::SemiAnnual->months());
        $this->assertSame(12, BillingCycle::Annual->months());
        $this->assertSame(24, BillingCycle::Biennial->months());
        $this->assertSame(36, BillingCycle::Triennial->months());
    }

    public function test_advance_does_not_overflow_short_months(): void
    {
        // 31 Jan + 1 month must be 28 Feb (2026 not leap), NOT 3 Mar.
        $this->assertSame('2026-02-28', BillingCycle::Monthly->advance(Carbon::parse('2026-01-31'))->toDateString());
        // 29 Feb (leap) + 1 year → 28 Feb next year.
        $this->assertSame('2025-02-28', BillingCycle::Annual->advance(Carbon::parse('2024-02-29'))->toDateString());
    }

    public function test_advance_plain_cases(): void
    {
        $this->assertSame('2026-04-15', BillingCycle::Quarterly->advance(Carbon::parse('2026-01-15'))->toDateString());
        $this->assertSame('2028-01-15', BillingCycle::Biennial->advance(Carbon::parse('2026-01-15'))->toDateString());
    }
}
