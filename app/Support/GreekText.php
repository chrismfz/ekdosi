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

    /** Lower-case Greek + strip τόνος (and final ς→σ), for accent-insensitive MATCHING. */
    private const FOLD_MAP = [
        'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω',
        'ϊ' => 'ι', 'ϋ' => 'υ', 'ΐ' => 'ι', 'ΰ' => 'υ', 'ς' => 'σ',
    ];

    /** Uppercase + drop the τόνος (accent-less ALL-CAPS, the correct Greek form). */
    public static function upper(?string $text): string
    {
        $upper = strtr(mb_strtoupper((string) $text, 'UTF-8'), self::ACCENTED_CAPS);

        // ΐ/ΰ (tonos+dialytika) uppercase to a base letter + combining diaeresis +
        // combining tonos, which the precomposed map above can't reach. Drop the
        // leftover combining tonos (U+0301) — the diaeresis (U+0308) stays, valid
        // on Greek capitals.
        return str_replace("\u{0301}", '', $upper);
    }

    /**
     * Lower-case + strip the τόνος (and normalise final ς→σ) so accented and
     * unaccented spellings compare equal. For keyword/label MATCHING, not display —
     * shared by the myDATA classification/config suggesters.
     */
    public static function fold(?string $text): string
    {
        return strtr(mb_strtolower(trim((string) $text), 'UTF-8'), self::FOLD_MAP);
    }
}
