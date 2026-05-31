<?php

namespace Tests\Unit;

use App\Support\MyData\Codes;
use PHPUnit\Framework\TestCase;

/**
 * The §8.2 rate-validity rule used by the ETL post-import warning, the
 * VatCategories table flag, and (semantically) MyDataSubmitter::vatCategoryFor.
 * Pure logic — no DB.
 */
class VatRateIsValidTest extends TestCase
{
    public function test_accepts_the_aade_8_2_rates(): void
    {
        foreach ([0, 4, 6, 9, 13, 17, 24] as $rate) {
            $this->assertTrue(Codes::vatRateIsValid($rate), "rate {$rate}% should be valid");
            $this->assertTrue(Codes::vatRateIsValid((float) $rate));
            $this->assertTrue(Codes::vatRateIsValid((string) $rate));
        }
    }

    public function test_rejects_non_aade_rates(): void
    {
        // The exact bug from the screenshot: a "9%" category typo'd as 10%.
        $this->assertFalse(Codes::vatRateIsValid(10));
        // An old pre-24% rate is NOT a valid TEMPLATE rate (historical invoice
        // lines keep it; new categories shouldn't use it).
        $this->assertFalse(Codes::vatRateIsValid(23));
        $this->assertFalse(Codes::vatRateIsValid(25));
        $this->assertFalse(Codes::vatRateIsValid(8));
    }

    public function test_null_or_empty_is_invalid(): void
    {
        $this->assertFalse(Codes::vatRateIsValid(null));
        $this->assertFalse(Codes::vatRateIsValid(''));
    }

    public function test_tolerant_float_compare(): void
    {
        $this->assertTrue(Codes::vatRateIsValid(24.00));
        $this->assertTrue(Codes::vatRateIsValid('24.004'));   // within 0.01
        $this->assertFalse(Codes::vatRateIsValid(24.5));
    }
}
