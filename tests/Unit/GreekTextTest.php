<?php

namespace Tests\Unit;

use App\Support\GreekText;
use Tests\TestCase;

class GreekTextTest extends TestCase
{
    public function test_upper_drops_the_tonos(): void
    {
        $this->assertSame('ΠΟΣΟΤΗΤΑ', GreekText::upper('Ποσότητα'));
        $this->assertSame('ΤΙΜΟΛΟΓΙΟ', GreekText::upper('Τιμολόγιο'));
        $this->assertSame('ΣΤΟΙΧΕΙΑ ΠΕΛΑΤΗ', GreekText::upper('Στοιχεία Πελάτη'));
        $this->assertSame('ΔΕΛΤΙΟ ΑΠΟΣΤΟΛΗΣ', GreekText::upper('Δελτίο Αποστολής'));
    }

    public function test_upper_leaves_accent_less_and_latin_text_intact(): void
    {
        $this->assertSame('ΜΕ ΦΠΑ', GreekText::upper('Με ΦΠΑ'));
        $this->assertSame('ABC', GreekText::upper('abc'));
        $this->assertSame('', GreekText::upper(null));
    }

    public function test_upper_keeps_dialytika(): void
    {
        // Dialytika ARE valid on Greek capitals — only the tonos is dropped.
        $this->assertSame('ΠΡΟΪΟΝ', GreekText::upper('προϊόν'));
    }
}
