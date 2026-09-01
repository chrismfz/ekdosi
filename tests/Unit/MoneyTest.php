<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_one_cent_is_within_tolerance_at_every_magnitude(): void
    {
        // The float-subtraction bug this replaces: 124.00-123.99 > 0.01 but
        // 1240.00-1239.99 < 0.01. Integer cents treats both as a one-cent gap.
        $this->assertFalse(Money::differsByCent(124.00, 123.99));
        $this->assertFalse(Money::differsByCent(1240.00, 1239.99));
        $this->assertFalse(Money::differsByCent(10000.00, 9999.99));
        $this->assertFalse(Money::differsByCent(124.00, 124.00));
    }

    public function test_two_cents_is_a_difference_at_every_magnitude(): void
    {
        $this->assertTrue(Money::differsByCent(124.00, 123.98));
        $this->assertTrue(Money::differsByCent(1240.00, 1239.98));
    }
}
