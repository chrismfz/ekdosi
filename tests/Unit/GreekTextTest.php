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

    public function test_upper_strips_tonos_on_dialytika_plus_tonos_letters(): void
    {
        // ΐ/ΰ carry BOTH dialytika and tonos; uppercasing must drop only the tonos.
        $out = GreekText::upper('πρωτεΐνη');
        $this->assertStringNotContainsString("\u{0301}", $out); // no combining tonos left
        $this->assertStringContainsString('ΠΡΩΤΕ', $out);
        $this->assertStringContainsString('ΝΗ', $out);
    }

    public function test_fold_lowercases_and_strips_tonos_for_matching(): void
    {
        // Accented + unaccented spellings fold to the same key (final ς→σ too).
        $this->assertSame('πιστωση', GreekText::fold('Πίστωση'));
        $this->assertSame('μετρητοισ', GreekText::fold('Μετρητοίς'));
        $this->assertSame('καταθεση', GreekText::fold('Κατάθεση'));
        $this->assertSame(GreekText::fold('ΕΠΙΤΑΓΉ'), GreekText::fold('επιταγή'));
        $this->assertSame('', GreekText::fold(null));
    }
}
