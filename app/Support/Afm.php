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
     * The VAT identifier as it should be FILED: separators and stray whitespace
     * removed, letters kept — and a Greek EU-VAT prefix («EL»/«GR», Latin or the
     * Greek-letter «ΕΛ») dropped when what remains is a bare nine-digit ΑΦΜ, which
     * is the form AADE expects for a domestic counterpart.
     *
     * `invoices.vat_no` is free text: a bare TextInput on the form and an ETL copy
     * of the legacy WIN1253 column. Filing it verbatim (MYD-009 first cut) sent
     * «IT 12345678901» and «EL123456789» to AADE and earned an opaque rejection —
     * the previous code filed `customers.afm`, which the customer form and the
     * GSIS/VIES lookups keep canonical.
     *
     * The nine-digit test is what keeps a foreign id intact: stripping «IT» from
     * «IT12345678901» leaves eleven digits, so the prefix stays.
     */
    public static function canonicalVat(?string $raw): ?string
    {
        $value = preg_replace('/[\s.\-]+/u', '', trim((string) $raw)) ?? '';
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(EL|GR|ΕΛ)(\d{9})$/ui', $value, $m) === 1) {
            return $m[2];
        }

        return $value;
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
