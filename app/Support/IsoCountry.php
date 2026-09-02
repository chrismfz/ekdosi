<?php

namespace App\Support;

use Firebed\AadeMyData\Enums\CountryCode;
use RuntimeException;

/**
 * ISO-3166-1 alpha-2 country normalisation — the ONE place invoice and delivery
 * payloads agree on how a stored country ("GR", "EL", "Greece", "GRC", …) maps to
 * the 2-letter code AADE accepts.
 *
 * The alpha-2 result is validated against a real code table rather than a bare
 * `strlen === 2 && ctype_alpha` passthrough — the columns holding these values are
 * `varchar(2)`, so a passthrough would accept EVERY storable 2-letter value
 * ('ZZ', 'XX') and make the "unrecognised country" refusal unreachable.
 *
 * That table is firebed's CountryCode enum PLUS `EXTRA_ISO`: firebed's list is a
 * snapshot that predates several current ISO codes (SS, CW, SX, BQ), and AADE's
 * spec says the country comes from ISO 3166 — not from firebed's copy. Without the
 * union, validating here would REJECT real countries the old passthrough accepted,
 * i.e. tighten the invoice path into a regression.
 *
 * Two entry points:
 *   - normalise():    the mandatory-field path — throws on an unrecognised value
 *     rather than send gibberish (used by the monetary invoice counterpart).
 *   - tryNormalise(): the caller-decides path — null on blank/unrecognised, so a
 *     caller can refuse (never default a foreign party to GR) with its own message.
 */
final class IsoCountry
{
    /**
     * Current ISO-3166-1 alpha-2 codes missing from firebed's enum snapshot.
     * Keep in sync if firebed's list is refreshed (duplicates are harmless).
     */
    private const EXTRA_ISO = [
        'SS' => 'Νότιο Σουδάν',
        'CW' => 'Κουρασάο',
        'SX' => 'Άγιος Μαρτίνος (Ολλανδικός)',
        'BQ' => 'Ολλανδική Καραϊβική',
    ];

    /** Offered first in the picker — the countries our tenants actually ship to. */
    private const COMMON = [
        'GR', 'CY', 'BG', 'RO', 'IT', 'DE', 'FR', 'NL', 'ES', 'AT', 'BE', 'PL',
        'CZ', 'EE', 'SE', 'DK', 'IE', 'PT', 'HU', 'SK', 'SI', 'HR', 'FI', 'LT',
        'LV', 'LU', 'MT', 'GB', 'CH', 'NO', 'US', 'TR', 'AL', 'MK', 'RS',
    ];

    /**
     * Country-name spellings the vendor enum does NOT carry: Latin-script names,
     * alpha-3 codes, and the handful of Greek variants that differ from the vendor
     * label («ΕΛΛΑΣ» vs its «Ελλάδα»). The other ~247 Greek names are not listed —
     * nameIndex() reads them straight off the enum, so they can't drift.
     */
    private const NAME_ALIASES = [
        'GREECE' => 'GR', 'HELLAS' => 'GR', 'GRC' => 'GR', 'ΕΛΛΑΣ' => 'GR',
        'ΕΛΛΗΝΙΚΗ ΔΗΜΟΚΡΑΤΙΑ' => 'GR',
        // Colloquial Greek names that differ from the official vendor label. Each
        // one that misses is a note the submitter REFUSES, so these are not polish.
        'ΑΓΓΛΙΑ' => 'GB', 'ΜΕΓΑΛΗ ΒΡΕΤΑΝΙΑ' => 'GB', 'ΒΡΕΤΑΝΙΑ' => 'GB',
        'ΗΠΑ' => 'US', 'ΑΜΕΡΙΚΗ' => 'US', 'ΗΝΩΜΕΝΕΣ ΠΟΛΙΤΕΙΕΣ' => 'US',
        'ΣΚΟΠΙΑ' => 'MK', 'ΠΓΔΜ' => 'MK', 'ΒΟΡΕΙΑ ΜΑΚΕΔΟΝΙΑ' => 'MK',
        'ΚΑΤΩ ΧΩΡΕΣ' => 'NL', 'ΟΛΛΑΝΔΙΑ' => 'NL',
        'ΤΣΕΧΙΚΗ ΔΗΜΟΚΡΑΤΙΑ' => 'CZ', 'ΤΣΕΧΙΑ' => 'CZ',
        'ΕΛΒΕΤΙΑ' => 'CH', 'ΙΡΛΑΝΔΙΑ' => 'IE', 'ΟΥΓΓΑΡΙΑ' => 'HU',
        'ESTONIA' => 'EE', 'EESTI' => 'EE', 'EST' => 'EE',
        'CYPRUS' => 'CY', 'CYP' => 'CY',
        'GERMANY' => 'DE', 'DEUTSCHLAND' => 'DE', 'DEU' => 'DE',
        'ITALY' => 'IT', 'ITALIA' => 'IT', 'ITA' => 'IT',
        'FRANCE' => 'FR', 'FRA' => 'FR',
        'SPAIN' => 'ES', 'ESPANA' => 'ES', 'ESP' => 'ES',
        'BULGARIA' => 'BG', 'BGR' => 'BG',
        'ROMANIA' => 'RO', 'ROU' => 'RO',
        'NETHERLANDS' => 'NL', 'HOLLAND' => 'NL', 'NLD' => 'NL',
        'BELGIUM' => 'BE', 'BEL' => 'BE',
        'AUSTRIA' => 'AT', 'AUT' => 'AT',
        'POLAND' => 'PL', 'POL' => 'PL',
        'PORTUGAL' => 'PT', 'PRT' => 'PT',
        'SWEDEN' => 'SE', 'SWE' => 'SE',
        'DENMARK' => 'DK', 'DNK' => 'DK',
        'IRELAND' => 'IE', 'IRL' => 'IE',
        'FINLAND' => 'FI', 'FIN' => 'FI',
        'SWITZERLAND' => 'CH', 'CHE' => 'CH',
        'UNITED KINGDOM' => 'GB', 'GREAT BRITAIN' => 'GB', 'ENGLAND' => 'GB', 'GBR' => 'GB',
        'UNITED STATES' => 'US', 'UNITED STATES OF AMERICA' => 'US', 'USA' => 'US',
    ];

    /** @var array<string, string>|null memoised — the table is a compile-time constant */
    private static ?array $options = null;

    /** @var array<string, string>|null folded country name => alpha-2, memoised */
    private static ?array $nameIndex = null;

    /**
     * @throws RuntimeException on a blank or unrecognised value
     */
    public static function normalise(string $raw): string
    {
        return self::tryNormalise($raw) ?? throw new RuntimeException(
            "Cannot normalise country '{$raw}' to ISO-3166-1 alpha-2. ".
            'Use a valid 2-letter code, or extend App\\Support\\IsoCountry.'
        );
    }

    /**
     * The same mapping, but null (never a throw) for a blank/unrecognised value —
     * for callers that must decide what an unknown country means themselves.
     */
    public static function tryNormalise(?string $raw): ?string
    {
        // Unicode-aware trim: PHP's trim() only strips ASCII whitespace, so a value
        // carrying a non-breaking space failed the code lookup and was refused. WIN1253
        // 0xA0 is NBSP, so the legacy import can leave one on a country column.
        $trimmed = preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', mb_strtoupper((string) $raw)) ?? '';
        if ($trimmed === '') {
            return null;
        }

        // VAT/common 2-letter aliases that DIFFER from ISO-3166 alpha-2 — resolved
        // BEFORE the alpha-2 lookup ('EL' is the EU VAT prefix for Greece, 'UK' the
        // common form of GB); left alone they are simply the wrong country.
        $alias = ['EL' => 'GR', 'UK' => 'GB'][$trimmed] ?? null;
        if ($alias !== null) {
            return $alias;
        }

        if (self::isKnownCode($trimmed)) {
            return $trimmed;
        }

        // Free-text country NAME. This matters more than it looks: both the legacy
        // Firebird import and the party forms store `country` as free text, so a
        // caller that REFUSES an unresolvable country (the delivery submitter does,
        // MYD-011) would otherwise strand every record holding «ΙΤΑΛΙΑ» or 'Italy'.
        // Resolving them is what keeps the refusal narrow enough to be safe.
        // foldKey() gets the RAW value, not $trimmed: the uppercasing above already
        // expands 'ΐ' into 'Ϊ' + a combining acute, which foldKey's punctuation scrub
        // would then split into two tokens.
        return self::nameIndex()[self::foldKey(trim((string) $raw))] ?? null;
    }

    /**
     * Comparison key for a free-text country name: uppercased, Greek accents and
     * final sigma folded away, punctuation dropped, whitespace collapsed. Legacy
     * rows hold «ΙΤΑΛΙΑ» (no accent) while the vendor label is «Ιταλία» (accented),
     * so a raw uppercase comparison misses exactly the values we need to resolve.
     */
    private static function foldKey(string $value): string
    {
        // Fold BEFORE uppercasing: mb_strtoupper('ΐ') expands to 'Ϊ' + a combining
        // acute, which the punctuation scrub below then turns into a SPACE, splitting
        // the token («ΑΐΤΗ» stopped matching while «ΑΪΤΗ» matched).
        $folded = mb_strtoupper(strtr($value, [
            'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω',
            'ϊ' => 'ι', 'ϋ' => 'υ', 'ΐ' => 'ι', 'ΰ' => 'υ', 'ς' => 'σ',
            'Ά' => 'Α', 'Έ' => 'Ε', 'Ή' => 'Η', 'Ί' => 'Ι', 'Ό' => 'Ο', 'Ύ' => 'Υ', 'Ώ' => 'Ω',
            'Ϊ' => 'Ι', 'Ϋ' => 'Υ',
        ]));

        // Drop punctuation the two spellings disagree on ('Ηνωμένο Βασίλειο' vs
        // 'ΗΝΩΜΕΝΟ-ΒΑΣΙΛΕΙΟ') and collapse runs of whitespace.
        $folded = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $folded) ?? $folded;

        return trim(preg_replace('/\s+/u', ' ', $folded) ?? $folded);
    }

    /**
     * folded name => alpha-2, built once from the vendor enum's Greek labels (all
     * 247 — hand-duplicating them would drift) plus the Latin-script names above.
     *
     * @return array<string, string>
     */
    private static function nameIndex(): array
    {
        if (self::$nameIndex !== null) {
            return self::$nameIndex;
        }

        $index = [];
        foreach (CountryCode::cases() as $case) {
            $index[self::foldKey($case->label())] ??= $case->value;
        }
        foreach (self::EXTRA_ISO as $code => $label) {
            $index[self::foldKey($label)] ??= $code;
        }
        foreach (self::NAME_ALIASES as $name => $code) {
            $index[self::foldKey($name)] = $code;
        }

        return self::$nameIndex = $index;
    }

    public static function isKnownCode(string $alpha2): bool
    {
        return CountryCode::tryFrom($alpha2) !== null || isset(self::EXTRA_ISO[$alpha2]);
    }

    /**
     * The whole code table as Filament Select options — common countries first,
     * every entry labelled with its Greek name so `searchable()` matches on the
     * name, not only the code. The full table is offered on purpose: the delivery
     * submitter REFUSES an external recipient whose country it cannot resolve, so a
     * shortlist would make a shipment to an omitted country unissuable.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return self::$options ??= self::buildOptions();
    }

    /** @return array<string, string> */
    private static function buildOptions(): array
    {
        // Greek names come from the firebed enum (it already carries all 247) —
        // hand-duplicating them here would drift.
        $labels = [];
        foreach (CountryCode::cases() as $case) {
            $labels[$case->value] = $case->label();
        }
        $labels += self::EXTRA_ISO;

        $out = [];
        foreach (self::COMMON as $code) {
            if (isset($labels[$code])) {
                $out[$code] = $code.' — '.$labels[$code];
            }
        }
        foreach ($labels as $code => $name) {
            $out[$code] ??= $code.' — '.$name;
        }

        return $out;
    }
}
