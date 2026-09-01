<?php

namespace Tests\Unit;

use App\Support\Afm;
use PHPUnit\Framework\TestCase;

class AfmTest extends TestCase
{
    public function test_digits_strips_everything_but_digits(): void
    {
        $this->assertSame('123456789', Afm::digits('EL123456789'));
        $this->assertSame('123456789', Afm::digits('123.456.789'));
        $this->assertSame('123456789', Afm::digits('  123 456 789 '));
        $this->assertSame('', Afm::digits('EL'));
        $this->assertSame('', Afm::digits(''));
        $this->assertSame('', Afm::digits(null));
    }

    public function test_normalise_returns_null_when_no_digits(): void
    {
        $this->assertSame('123456789', Afm::normalise('GR123456789'));
        $this->assertNull(Afm::normalise('EL'));
        $this->assertNull(Afm::normalise(''));
        $this->assertNull(Afm::normalise(null));
    }
}
