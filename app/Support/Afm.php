<?php

namespace App\Support;

/**
 * Greek ΑΦΜ (VAT number) canonicalisation — one rule, so every comparison agrees
 * on when two AFMs are "the same". Both forms strip everything but digits (an
 * 'EL'/'GR' prefix or stray punctuation must not read as a difference); they
 * differ only in how they report "no digits at all".
 */
final class Afm
{
    /** Digits only; '' when the input carries none. */
    public static function digits(?string $raw): string
    {
        return preg_replace('/\D+/', '', (string) $raw) ?? '';
    }

    /** Digits only; null when the input carries none (for nullable AFM columns). */
    public static function normalise(?string $raw): ?string
    {
        $digits = self::digits($raw);

        return $digits === '' ? null : $digits;
    }
}
