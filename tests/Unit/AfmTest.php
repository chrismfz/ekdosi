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

    public function test_unique_key_is_the_identity_and_null_when_there_is_none(): void
    {
        $this->assertSame('123456789', Afm::uniqueKey('GR123456789'));
        $this->assertSame('CY10259033P', Afm::uniqueKey('cy10259033p'));
        $this->assertNull(Afm::uniqueKey('EL'));
        $this->assertNull(Afm::uniqueKey(''));
        $this->assertNull(Afm::uniqueKey(null));
    }
}
