<?php

namespace App\Support;

/**
 * Greek typography helper for PDF/print contexts.
 *
 * Greek convention: ALL-CAPS text drops the τόνος (e.g. ΤΙΜΟΛΟΓΙΟ, not ΤΙΜΟΛΌΓΙΟ).
 * CSS `text-transform: uppercase` does NOT do this — it keeps the accent on the
 * capital, which is both wrong and renders oddly in DomPDF. `upper()` uppercases
 * AND strips the tonos the uppercasing leaves behind (dialytika are kept — they're
 * valid on Greek capitals). Exposed to Blade as `@gup(...)`.
 */
class GreekText
{
    /** Tonos-bearing Greek capitals → their plain form (dialytika Ϊ/Ϋ intentionally kept). */
    private const ACCENTED_CAPS = [
        'Ά' => 'Α', 'Έ' => 'Ε', 'Ή' => 'Η', 'Ί' => 'Ι',
        'Ό' => 'Ο', 'Ύ' => 'Υ', 'Ώ' => 'Ω',
    ];

    /** Uppercase + drop the τόνος (accent-less ALL-CAPS, the correct Greek form). */
    public static function upper(?string $text): string
    {
        return strtr(mb_strtoupper((string) $text, 'UTF-8'), self::ACCENTED_CAPS);
    }
}
