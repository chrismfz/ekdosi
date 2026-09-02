<?php

namespace Tests\Unit;

use App\Support\IsoCountry;
use Firebed\AadeMyData\Enums\CountryCode;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The ONE country normaliser shared by the monetary-invoice and delivery-note
 * payloads (MYD-011). The delivery copy used to lack the EL/UK aliases, so the
 * two surfaces disagreed on the same stored value.
 */
class IsoCountryTest extends TestCase
{
    public function test_passes_through_a_valid_alpha_2_code(): void
    {
        $this->assertSame('GR', IsoCountry::normalise('GR'));
        $this->assertSame('DE', IsoCountry::normalise('de'));
        $this->assertSame('CY', IsoCountry::normalise('  cy  '));
    }

    public function test_resolves_the_vat_aliases_that_differ_from_iso(): void
    {
        // 'EL' is the EU VAT prefix for Greece and 'UK' the common form of GB —
        // both are valid-looking 2-letter codes, so they MUST be resolved before
        // the alpha-2 passthrough or they file as the wrong country.
        $this->assertSame('GR', IsoCountry::normalise('EL'));
        $this->assertSame('GR', IsoCountry::normalise('el'));
        $this->assertSame('GB', IsoCountry::normalise('UK'));
    }

    public function test_resolves_free_text_country_names(): void
    {
        $this->assertSame('GR', IsoCountry::normalise('Greece'));
        $this->assertSame('GR', IsoCountry::normalise('Ελλάδα'));
        $this->assertSame('GR', IsoCountry::normalise('GRC'));
        $this->assertSame('EE', IsoCountry::normalise('Estonia'));
        $this->assertSame('DE', IsoCountry::normalise('Deutschland'));
    }

    public function test_resolves_the_greek_country_names_the_legacy_import_left_behind(): void
    {
        // Greek labels come from the vendor enum (all 247), matched through an
        // accent-folded key: the legacy rows hold «ΙΤΑΛΙΑ» while the label is
        // «Ιταλία», so a raw uppercase comparison would miss every one of them.
        // This is load-bearing, not cosmetic — the delivery submitter REFUSES an
        // unresolvable country, so a miss here makes a legitimate note unissuable.
        $this->assertSame('IT', IsoCountry::normalise('ΙΤΑΛΙΑ'));
        $this->assertSame('IT', IsoCountry::normalise('Ιταλία'));
        $this->assertSame('FR', IsoCountry::normalise('ΓΑΛΛΙΑ'));
        $this->assertSame('CY', IsoCountry::normalise('ΚΥΠΡΟΣ'));
        $this->assertSame('BG', IsoCountry::normalise('Βουλγαρία'));
        $this->assertSame('GB', IsoCountry::normalise('ΗΝΩΜΕΝΟ ΒΑΣΙΛΕΙΟ'));

        // Spellings the vendor label doesn't carry.
        $this->assertSame('GR', IsoCountry::normalise('ΕΛΛΑΣ'));
        $this->assertSame('GR', IsoCountry::normalise('Ελλάδα'));
    }

    public function test_resolves_latin_names_and_alpha_3_codes(): void
    {
        $this->assertSame('IT', IsoCountry::normalise('Italy'));
        $this->assertSame('IT', IsoCountry::normalise('ITA'));
        $this->assertSame('FR', IsoCountry::normalise('France'));
        $this->assertSame('NL', IsoCountry::normalise('Holland'));
        $this->assertSame('GB', IsoCountry::normalise('United Kingdom'));
        $this->assertSame('US', IsoCountry::normalise('USA'));
    }

    public function test_folds_greek_accents_including_the_two_char_combining_forms(): void
    {
        // 'ΐ'/'ΰ' expand under mb_strtoupper into a base letter PLUS a combining
        // acute; folding after that uppercase let the punctuation scrub split the
        // token in two, so «ΑΐΤΗ» stopped matching while «ΑΪΤΗ» matched.
        $this->assertSame('HT', IsoCountry::normalise('ΑΪΤΗ'));
        $this->assertSame('HT', IsoCountry::normalise('ΑΐΤΗ'));
        $this->assertSame('HT', IsoCountry::normalise('Αϊτή'));
    }

    public function test_resolves_the_colloquial_names_operators_actually_type(): void
    {
        // Each miss here is a delivery note the submitter REFUSES, so the everyday
        // spellings matter as much as the official label.
        $this->assertSame('GB', IsoCountry::normalise('ΑΓΓΛΙΑ'));
        $this->assertSame('US', IsoCountry::normalise('ΗΠΑ'));
        $this->assertSame('MK', IsoCountry::normalise('ΣΚΟΠΙΑ'));
        $this->assertSame('NL', IsoCountry::normalise('ΟΛΛΑΝΔΙΑ'));
        $this->assertSame('NL', IsoCountry::normalise('Κάτω Χώρες'));
        $this->assertSame('CZ', IsoCountry::normalise('Τσεχική Δημοκρατία'));
    }

    public function test_no_country_name_is_shadowed_by_another(): void
    {
        // The index is built from three sources (vendor labels, EXTRA_ISO, aliases).
        // If two fold to one key, a country silently resolves to the WRONG one.
        foreach (CountryCode::cases() as $case) {
            $this->assertSame(
                $case->value,
                IsoCountry::tryNormalise($case->label()),
                "«{$case->label()}» must resolve to {$case->value}"
            );
        }
    }

    public function test_strips_a_non_breaking_space(): void
    {
        // PHP's trim() only strips ASCII whitespace. WIN1253 0xA0 is NBSP, so the
        // legacy import can leave one on a country column — and the refusal turns
        // that invisible character into an unissuable delivery note.
        $this->assertSame('GR', IsoCountry::normalise("GR\u{00A0}"));
        $this->assertSame('GR', IsoCountry::normalise("\u{00A0}GR"));
        $this->assertSame('IT', IsoCountry::normalise("ΙΤΑΛΙΑ\u{00A0}"));
        $this->assertNull(IsoCountry::tryNormalise("\u{00A0}"));
    }

    public function test_a_country_name_is_still_not_a_licence_to_guess(): void
    {
        // The name index must not turn the refusal into a passthrough: anything it
        // doesn't actually know stays null so the caller can refuse.
        $this->assertNull(IsoCountry::tryNormalise('Neverland'));
        $this->assertNull(IsoCountry::tryNormalise('Ουτοπία'));
        $this->assertNull(IsoCountry::tryNormalise('N/A'));
        $this->assertNull(IsoCountry::tryNormalise('-'));
    }

    public function test_normalise_throws_on_an_unrecognised_value(): void
    {
        $this->expectException(RuntimeException::class);
        IsoCountry::normalise('Neverland');
    }

    public function test_rejects_two_letter_values_that_are_not_real_iso_codes(): void
    {
        // A bare `strlen === 2 && ctype_alpha` passthrough would accept these and
        // file them to AADE, which bounces with an opaque [24x]. The alpha-2 check
        // is against firebed's full ISO table instead.
        $this->assertNull(IsoCountry::tryNormalise('ZZ'));
        $this->assertNull(IsoCountry::tryNormalise('XX'));
        $this->assertNull(IsoCountry::tryNormalise('QQ'));
    }

    public function test_options_expose_the_full_iso_table_not_a_shortlist(): void
    {
        $options = IsoCountry::options();

        // Common countries carry a Greek name and come first…
        $this->assertSame('GR — Ελλάδα', $options['GR']);
        $this->assertSame('DE — Γερμανία', $options['DE']);

        // …but the whole table is selectable: the submitter refuses a country it
        // can't resolve, so an omitted one would be unissuable.
        $this->assertArrayHasKey('JP', $options);
        $this->assertArrayHasKey('CA', $options);
        $this->assertArrayHasKey('AU', $options);
        $this->assertArrayHasKey('AE', $options);
        $this->assertGreaterThan(200, count($options));

        // Every offered key must itself normalise (no unselectable/bogus entries).
        foreach (array_keys($options) as $code) {
            $this->assertSame($code, IsoCountry::tryNormalise($code), "option {$code} must be a valid ISO code");
        }
    }

    public function test_accepts_iso_codes_missing_from_the_vendor_enum_snapshot(): void
    {
        // firebed's CountryCode list predates several current ISO codes. Validating
        // ONLY against it would REJECT real countries the old alpha-2 passthrough
        // accepted — tightening the invoice path into a regression.
        $this->assertSame('SS', IsoCountry::normalise('SS'));   // South Sudan
        $this->assertSame('CW', IsoCountry::normalise('CW'));   // Curaçao
        $this->assertSame('SX', IsoCountry::normalise('SX'));
        $this->assertSame('BQ', IsoCountry::normalise('BQ'));
    }

    public function test_options_label_every_country_by_name_not_just_the_code(): void
    {
        $options = IsoCountry::options();

        // Names come from the vendor enum (all 247), so searchable() matches on the
        // name — an operator shipping to Japan need not know the ISO code.
        $this->assertStringContainsString('Ιαπωνία', $options['JP']);
        $this->assertStringContainsString('Καναδάς', $options['CA']);
        $this->assertStringContainsString('Ελλάδα', $options['GR']);
        $this->assertStringContainsString('Νότιο Σουδάν', $options['SS']);

        // Memoised — the table is a compile-time constant.
        $this->assertSame($options, IsoCountry::options());
    }

    public function test_try_normalise_returns_null_instead_of_throwing(): void
    {
        // The caller-decides path: a delivery note uses this to REFUSE an external
        // recipient rather than defaulting it to GR.
        $this->assertNull(IsoCountry::tryNormalise('Neverland'));
        $this->assertNull(IsoCountry::tryNormalise(''));
        $this->assertNull(IsoCountry::tryNormalise('   '));
        $this->assertNull(IsoCountry::tryNormalise(null));

        $this->assertSame('GR', IsoCountry::tryNormalise('EL'));
    }
}
