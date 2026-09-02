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
    /** Fewer digits than this is free text, never a VAT identity (IE1234567T = 7). */
    public const MIN_IDENTITY_DIGITS = 7;

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
     * The IDENTITY key behind `customers.afm_key` (unique per tenant):
     *   - upper-case alphanumerics only («EL 123-456-789» → «EL123456789»);
     *   - a Greek ΑΦΜ (9 digits, optional EL/GR prefix) collapses to its digits,
     *     so «EL123456789», «123 456 789» and «123456789» are one customer;
     *   - a foreign VAT keeps its letters («CY10259033P», «EE123456789»);
     *   - placeholders (all-same-digit: 000000000, 999999999), blanks and free
     *     text («N/A», «ΔΕΝ ΕΧΕΙ 0» — fewer than 7 digits, the shortest EU VAT
     *     form) → null, i.e. NOT an identity — many retail customers may share them.
     */
    public static function uniqueKey(?string $raw): ?string
    {
        // A Greek-keyboard slip on the country prefix («ΕL», «ΕΛ», «EΛ» with a
        // Greek Ε/Λ) must not mint a new identity: fold a LEADING one to «EL»
        // (after any label/separators — «ΑΦΜ: ΕΛ123…»). Only the prefix: any
        // other Greek text («123456789 ΕΛΛΑΔΑ», an «ΑΦΜ» label) is simply
        // dropped below, never folded into Latin letters.
        $upper = mb_strtoupper((string) $raw);
        $upper = preg_replace('/^[^\p{L}\d]*(?:ΑΦΜ)?[^\p{L}\d]*(?:ΕΛ|ΕL|EΛ)/u', 'EL', $upper) ?? $upper;
        $key = preg_replace('/[^A-Z0-9]+/', '', $upper) ?? '';
        // Every real ΑΦΜ/VAT carries at least 7 digits (IE1234567T is the
        // shortest EU form); letters-only text («N/A», «NONE», a bare «EL») or
        // free text with a stray digit («ΔΕΝ ΕΧΕΙ 0», «N/A 000») is a
        // placeholder, not an identity — minting a key from it would make two
        // retail customers with the same note collide.
        if (strlen(preg_replace('/\D+/', '', $key) ?? '') < self::MIN_IDENTITY_DIGITS) {
            return null;
        }

        if (preg_match('/^(?:EL|GR)?(\d{9})$/', $key, $m) === 1) {
            $key = $m[1];
        }

        return self::isPlaceholder($key) ? null : $key;
    }

    /**
     * What `leads.afm` stores: the identity key when there is one (so
     * LeadMatcher compares like with like), else the operator's text as typed
     * (a lead is a notebook — «000000000» / «N/A» is an answer, not data loss).
     */
    public static function leadAfm(?string $raw): ?string
    {
        $text = trim((string) $raw);

        return self::uniqueKey($text) ?? ($text === '' ? null : $text);
    }

    /** A dummy ΑΦΜ (000000000, 999999999, …) that identifies nobody. */
    public static function isPlaceholder(string $key): bool
    {
        return ctype_digit($key) && $key !== '' && count(array_unique(str_split($key))) === 1;
    }
}
