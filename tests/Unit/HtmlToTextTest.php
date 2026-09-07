<?php

namespace Tests\Unit;

use App\Support\HtmlToText;
use PHPUnit\Framework\TestCase;

class HtmlToTextTest extends TestCase
{
    public function test_it_strips_tags_and_keeps_readable_text(): void
    {
        $html = '<p>Γεια σας,</p><p>Δεν μπορώ να <strong>μπω</strong>.</p>';
        $this->assertSame("Γεια σας,\n\nΔεν μπορώ να μπω.", HtmlToText::convert($html));
    }

    public function test_it_turns_br_and_lists_into_lines(): void
    {
        $html = 'Πρώτη<br>Δεύτερη<ul><li>Α</li><li>Β</li></ul>';
        $out = HtmlToText::convert($html);
        $this->assertStringContainsString("Πρώτη\nΔεύτερη", $out);
        $this->assertStringContainsString('• Α', $out);
        $this->assertStringContainsString('• Β', $out);
    }

    public function test_it_drops_script_and_style_and_decodes_entities(): void
    {
        $html = '<style>p{color:red}</style><script>alert(1)</script><p>Ποσό&nbsp;10&euro; &amp; ΦΠΑ</p>';
        $out = HtmlToText::convert($html);
        $this->assertStringNotContainsString('alert', $out);
        $this->assertStringNotContainsString('color:red', $out);
        $this->assertStringContainsString('&', $out);       // &amp; decoded
        $this->assertStringContainsString('€', $out);       // &euro; decoded
    }

    public function test_it_collapses_excess_blank_lines_and_trims(): void
    {
        $html = '  <p>ένα</p><br><br><br><p>δύο</p>  ';
        $this->assertSame("ένα\n\nδύο", HtmlToText::convert($html));
    }

    public function test_blank_input_is_empty(): void
    {
        $this->assertSame('', HtmlToText::convert(null));
        $this->assertSame('', HtmlToText::convert('   '));
    }
}
