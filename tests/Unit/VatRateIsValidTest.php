<?php

namespace Tests\Unit;

use App\Services\EInvoice\AadeInvoiceDocument;
use App\Support\MyData\Codes;
use PHPUnit\Framework\TestCase;

/**
 * The §8.2 rate-validity rule used by the ETL post-import warning, the
 * VatCategories table flag, and (semantically) AadeInvoiceDocument::vatCategoryFor.
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

    public function test_3pct_is_not_fileable_yet(): void
    {
        // Codes::VAT_CATEGORY_RATES lists code 9 = 3% (ν.5057/2023), but
        // AadeInvoiceDocument::vatCategoryFor has no 3% arm → it would throw at
        // filing. So the validity check must REJECT 3% to stay honest with
        // what actually files (the bug the review caught). If a 3% arm is
        // ever added to vatCategoryFor + FILEABLE_VAT_RATES, flip this.
        $this->assertFalse(Codes::vatRateIsValid(3));
    }

    public function test_fileable_set_matches_what_the_submitter_maps(): void
    {
        // Guard against drift: every FILEABLE rate must map in vatCategoryFor
        // without throwing, and a non-fileable rate (3%) must throw. Uses
        // reflection since vatCategoryFor is private — this is the lock that
        // keeps Codes::FILEABLE_VAT_RATES and the submitter in sync.
        $document = (new \ReflectionClass(AadeInvoiceDocument::class))
            ->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod($document, 'vatCategoryFor');
        $m->setAccessible(true);

        foreach (Codes::FILEABLE_VAT_RATES as $rate) {
            $cat = $m->invoke($document, $rate);
            $this->assertIsInt($cat, "rate {$rate}% should map to an AADE vatCategory");
        }

        $this->expectException(\RuntimeException::class);
        $m->invoke($document, 3.0);   // not in FILEABLE_VAT_RATES → must throw
    }

    public function test_3pct_is_fileable_only_with_a_valid_override(): void
    {
        // MYD-8: 3% is not fileable by rate alone (vatCategoryFor throws)…
        $this->assertFalse(Codes::vatRateFileable(3, null));
        // …but IS once the VatCategory carries a valid §8.2 override (3%→9).
        $this->assertTrue(Codes::vatRateFileable(3, 9));
        // A bogus override code doesn't unlock it.
        $this->assertFalse(Codes::vatRateFileable(3, 99));
        // Nor does a VALID §8.2 code whose OWN rate isn't 3% — code 8 (no-VAT) and
        // code 6 (4%) must NOT green-light a 3% row (they'd file a wrong category).
        $this->assertFalse(Codes::vatRateFileable(3, 8));
        $this->assertFalse(Codes::vatRateFileable(3, 6));
        // 4% is already directly fileable (category 6) — no override needed.
        $this->assertTrue(Codes::vatRateFileable(4, null));
        // A normal rate is fileable; an override can't rescue a genuinely bad rate.
        $this->assertTrue(Codes::vatRateFileable(24, null));
        $this->assertFalse(Codes::vatRateFileable(23, 9));
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
