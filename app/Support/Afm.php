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

    /**
     * Comparison key for "are these two parties the same?": separators and case
     * folded away, but LETTERS KEPT.
     *
     * Deliberately NOT digits(), which strips everything non-numeric: that turns the
     * German VAT id «DE811234567» into «811234567», which then matches a Greek
     * customer's ΑΦΜ — so a foreign party reads as "this is our customer" and
     * inherits that customer's country/name. A country prefix is evidence, not
     * noise. (MYD-011 for delivery notes, MYD-009 for invoices — one definition so
     * the two identity checks cannot drift.)
     */
    public static function comparisonKey(?string $raw): string
    {
        return mb_strtoupper(preg_replace('/[\s.\-]+/u', '', trim((string) $raw)) ?? '');
    }
}
