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
    /**
     * The country prefixes that actually appear in front of a VAT identifier: the
     * EU member states (EL for Greece), plus GB/XI, CH and NO. Deliberately NOT
     * "any ISO-3166 code" — see countryPrefix().
     */
    private const VAT_PREFIXES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'GR',
        'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE',
        'SI', 'SK', 'GB', 'XI', 'CH', 'NO',
    ];

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
            $value = $m[2];
        }

        // An all-zeros value («0», «000000000») is a PLACEHOLDER meaning "no ΑΦΜ" —
        // the convention the delivery-note sentinel already uses — not an identity.
        // Returning it let «0» become a reported counterpart, and made every identity
        // comparison call that document a different party from its own customer.
        return trim($value, '0') === '' ? null : $value;
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
        // Canonicalise FIRST: «EL997073525» and «997073525» are the same taxpayer, and
        // `customers.afm` legitimately carries the prefix (the VIES form-fill seeds it
        // as a full VAT id). Comparing the raw strings called them different parties
        // and refused an invoice that used to file — while canonicalVat() was
        // simultaneously stripping that same prefix on the way out, so one commit
        // contradicted itself. A FOREIGN prefix survives canonicalisation, so
        // «DE811234567» still does not match a Greek «811234567».
        return mb_strtoupper(self::canonicalVat($raw) ?? '');
    }

    /**
     * Is this value the ALL-ZEROS placeholder — «0», «000000000» — as opposed to
     * merely unusable junk like «-» or «.»?
     *
     * The two must not be conflated. All-zeros is a DECLARATION ("this party has no
     * ΑΦΜ", the convention AADE gives ενδοδιακίνηση); junk is an accident, and the
     * right response to an accident is to fall through to whatever real identity is
     * available rather than to declare something.
     */
    public static function isZeroPlaceholder(?string $raw): bool
    {
        // Strip a Greek VAT prefix FIRST, exactly as canonicalVat() does: «EL000000000»
        // is the same declaration as «000000000». Testing the raw value let the two
        // helpers disagree, so a prefixed placeholder took the "junk" path and was
        // replaced by the linked customer's real ΑΦΜ — the placeholder filed as an
        // identity, which is the conflation this helper exists to prevent.
        $value = preg_replace('/[\s.\-]+/u', '', trim((string) $raw)) ?? '';
        if (preg_match('/^(EL|GR|ΕΛ)(\d+)$/ui', $value, $m) === 1) {
            $value = $m[2];
        }

        return $value !== '' && trim($value, '0') === '';
    }

    /**
     * The ISO-3166-1 alpha-2 country prefix carried by a VAT identifier, when it has
     * one that names a real country — «IT12345678901» → «IT». Null for a bare ΑΦΜ.
     *
     * This is EVIDENCE about the party, not decoration: an invoice whose only
     * counterpart data is «IT…» must never be filed as a domestic Greek document
     * just because no country column happens to be populated.
     */
    public static function countryPrefix(?string $raw): ?string
    {
        $value = self::canonicalVat($raw);

        // A real EU VAT id is NOT «two letters then digits»: AT is ATU12345678, CY is
        // CY12345678L, NL is NL123456789B01, IE is IE1234567FA, ES is ESX1234567X.
        // Matching only the digits-only shape let half of Europe be filed as GR.
        if ($value === null || preg_match('/^([A-Za-z]{2})([A-Za-z0-9]+)$/u', $value, $m) !== 1) {
            return null;
        }

        // Only prefixes that are actually USED as VAT prefixes count. Accepting any
        // ISO-2 code made ordinary domestic values claim a country — «AE997073525»
        // (a real nine-digit ΑΦΜ with two stray letters) read as the UAE, «INV…» as
        // India, «SA 1» as Saudi Arabia — and once that evidence could refuse a
        // filing, a perfectly good domestic invoice became unissuable.
        $prefix = strtoupper($m[1]);
        if (! in_array($prefix, self::VAT_PREFIXES, true)) {
            return null;
        }

        // …and the body must still look like an identifier rather than free text
        // («LTD 12» would otherwise read as Lithuania). Six digits is a deliberately
        // ASYMMETRIC trade: a false positive REFUSES a good domestic invoice, while a
        // false negative only falls back to the recorded country (or, with nothing
        // recorded, to the same GR default this code has always used). So the short
        // real shapes — a two-digit Romanian id, an old IE format, a GB government
        // id — are knowingly given up to keep junk out.
        if (preg_match_all('/\d/', $m[2]) < 6) {
            return null;
        }

        // «XI» is a VAT jurisdiction (Northern Ireland), not an ISO-3166 country, so
        // IsoCountry does not know it and the allowlist entry was inert — the round-5
        // commit cited XI as a reason to trust the recorded country while the code
        // never produced that prefix at all. Its country IS GB.
        return $prefix === 'XI' ? 'GB' : IsoCountry::tryNormalise($prefix);
    }
}
