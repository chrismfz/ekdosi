<?php

namespace App\Support;

/**
 * Best-effort Greek → Latin transliteration for international transport
 * documents (the CMR must be in Latin script). Uses the intl Transliterator
 * (UNGEGN, close to ΕΛΟΤ 743) + Latin-ASCII when available, falling back to a
 * small manual map so it never hard-depends on ext-intl.
 *
 * This is a DRAFT aid only — the operator reviews/corrects the result (official
 * company names often have their own registered Latin form) before printing.
 */
class TransliterateGreek
{
    public static function toLatin(?string $text): string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }

        // Already plain ASCII? Leave it (it's likely an official Latin name).
        if (preg_match('/^[\x20-\x7E]*$/', $text)) {
            return $text;
        }

        if (class_exists(\Transliterator::class)) {
            $tr = \Transliterator::create('Greek-Latin/UNGEGN; Latin-ASCII');
            if ($tr !== null) {
                $out = $tr->transliterate($text);
                if (is_string($out) && $out !== '') {
                    return $out;
                }
            }
        }

        return self::manual($text);
    }

    private static function manual(string $text): string
    {
        // Digraph-free single-char map (accents stripped first). Good enough for
        // the fallback path; the intl path above is the normal one.
        $map = [
            'α' => 'a', 'β' => 'v', 'γ' => 'g', 'δ' => 'd', 'ε' => 'e', 'ζ' => 'z',
            'η' => 'i', 'θ' => 'th', 'ι' => 'i', 'κ' => 'k', 'λ' => 'l', 'μ' => 'm',
            'ν' => 'n', 'ξ' => 'x', 'ο' => 'o', 'π' => 'p', 'ρ' => 'r', 'σ' => 's',
            'ς' => 's', 'τ' => 't', 'υ' => 'y', 'φ' => 'f', 'χ' => 'ch', 'ψ' => 'ps',
            'ω' => 'o',
        ];

        $accents = [
            'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω',
            'ϊ' => 'ι', 'ϋ' => 'υ', 'ΐ' => 'ι', 'ΰ' => 'υ',
        ];

        $lower = mb_strtolower($text);
        $lower = strtr($lower, $accents);
        $out = strtr($lower, $map);

        return mb_convert_case($out, MB_CASE_TITLE);
    }
}
