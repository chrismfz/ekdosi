<?php

namespace App\Support\MyData;

/**
 * Suggests a §8.1 myDATA invoice-type code for an existing invoice type that
 * has none — a DISPLAY-ONLY hint for the operator (list badge + form helper),
 * never auto-applied. The seeder back-fills only the exact starter codes it
 * knows (ΤΙΜ→1.1 …); this covers the long tail of custom series (ΤΠΒ, ΑΚΤΠΥ,
 * δεύτερες σειρές…) where ekdosi can only GUESS from the Greek name, so the
 * operator confirms in the edit form.
 *
 * Heuristic over the type's name (and credit/return flags), most-specific
 * first. Returns null when it can't make a confident guess — better no hint
 * than a wrong one on a legal classification.
 */
final class InvoiceTypeClassSuggester
{
    /**
     * Canonical income-classification chain (E3 type + per-rate category) per
     * §8.1 code, for the issuing types where a single default is unambiguous —
     * matches MyDataLookupSeeder's by-the-book seed so a suggestion and the
     * starter seed never disagree. Types with no safe default (delivery notes,
     * τίτλος κτήσης, αυτοπαράδοση, ενοίκια/συμβόλαια — specialised E3 lines) are
     * absent → the suggestion carries the TYPE only and the operator picks the
     * income line.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const INCOME_CHAIN = [
        '1.1' => ['E3_561_001', 'category1_1'],
        '1.2' => ['E3_561_005', 'category1_1'],
        '1.3' => ['E3_561_005', 'category1_1'],
        '2.1' => ['E3_561_001', 'category1_3'],
        '2.2' => ['E3_561_005', 'category1_3'],
        '2.3' => ['E3_561_005', 'category1_3'],
        '5.1' => ['E3_561_001', 'category1_3'],
        '5.2' => ['E3_561_001', 'category1_3'],
        '11.1' => ['E3_561_003', 'category1_1'],
        '11.2' => ['E3_561_003', 'category1_3'],
        '11.3' => ['E3_561_003', 'category1_3'],
        '11.4' => ['E3_561_003', 'category1_3'],
    ];

    /**
     * @return array{code: string, label: string, income_class: ?string, income_class_category: ?string}|null
     */
    public static function suggest(string $name, bool $isCredit = false, bool $isReturn = false): ?array
    {
        $n = self::fold($name);

        // Order matters: most-specific intent first. Credit/cancellation wins
        // over everything; retail before services/goods (a retail receipt name
        // also contains "πώληση"/"παροχή"); goods before delivery (a "Τιμολόγιο
        // … Δελτίο Αποστολής" is a SALE invoice, not a pure delivery note).

        // Credit / cancellation / return → πιστωτικό.
        if ($isCredit || $isReturn || str_contains($n, 'πιστωτικ') || str_contains($n, 'ακυρωτικ') || str_contains($n, 'επιστροφ')) {
            if (str_contains($n, 'λιανικ')) {
                return self::hit('11.4'); // retail credit note
            }
            if (str_contains($n, 'μη συσχετ') || str_contains($n, 'ασυσχετ')) {
                return self::hit('5.2'); // non-correlated credit
            }

            return self::hit('5.1'); // correlated (default); operator switches to 5.2 if not
        }

        // Τίτλος Κτήσης (self-billing a non-obligated counterparty).
        if (str_contains($n, 'τιτλοσ κτησ') || str_contains($n, 'τιτλου κτησ') || str_contains($n, 'τιτλ κτησ')) {
            return self::hit('3.1');
        }

        // Self-supply / own-use.
        if (str_contains($n, 'αυτοπαραδοσ')) {
            return self::hit('6.1');
        }
        if (str_contains($n, 'ιδιοχρησιμοποι')) {
            return self::hit('6.2');
        }

        // Simplified invoice — contains "τιμολόγιο", so MUST precede the goods block.
        if (str_contains($n, 'απλοποιημ')) {
            return self::hit('11.3');
        }

        // Retail (λιανική): ΑΛΠ goods vs ΑΠΥ services.
        if (str_contains($n, 'λιανικ') || str_contains($n, 'αλπ')) {
            return self::hit('11.1');
        }
        if (str_contains($n, 'αποδειξη') && str_contains($n, 'παροχ')) {
            return self::hit('11.2'); // ΑΠΥ
        }

        // Services (παροχή υπηρεσιών). Intra-EU / third-country variants first.
        if (str_contains($n, 'παροχ') || str_contains($n, 'υπηρεσι')) {
            if (str_contains($n, 'ενδοκοινοτ')) {
                return self::hit('2.2');
            }
            if (str_contains($n, 'τριτ')) {
                return self::hit('2.3');
            }

            return self::hit('2.1');
        }

        // Goods sales (πώληση / τιμολόγιο / εμπόρευμα). Intra-EU / third-country.
        // BEFORE delivery so "Τιμολόγιο Δελτίο Αποστολής" → 1.1, not 9.3.
        if (str_contains($n, 'πωλησ') || str_contains($n, 'τιμολογιο') || str_contains($n, 'εμπορευμ')) {
            if (str_contains($n, 'ενδοκοινοτ')) {
                return self::hit('1.2');
            }
            if (str_contains($n, 'τριτ')) {
                return self::hit('1.3');
            }

            return self::hit('1.1');
        }

        // Pure delivery / receipt notes (δελτίο αποστολής / παραλαβής).
        // Correlated (συσχετιζόμενο) vs aggregate (συγκεντρωτικό) vs standalone.
        if (str_contains($n, 'δελτιο')) {
            if (str_contains($n, 'παραλαβ')) {
                return self::hit(str_contains($n, 'συσχετιζ') ? '10.1' : '10.2');
            }
            if (str_contains($n, 'συγκεντρωτικ')) {
                return self::hit('9.2');
            }
            if (str_contains($n, 'συσχετιζ')) {
                return self::hit('9.1');
            }

            return self::hit('9.3');
        }

        // Income from contracts / rents (issuing side: 7.1 / 8.1, not the
        // expense twins 15.1 / 16.1 — those aren't issued as series).
        if (str_contains($n, 'συμβολαι')) {
            return self::hit('7.1');
        }
        if (str_contains($n, 'ενοικι') || str_contains($n, 'μισθωμ')) {
            return self::hit('8.1');
        }

        return null;
    }

    /**
     * @return array{code: string, label: string, income_class: ?string, income_class_category: ?string}
     */
    private static function hit(string $code): array
    {
        [$incomeClass, $incomeCategory] = self::INCOME_CHAIN[$code] ?? [null, null];

        return [
            'code' => $code,
            'label' => Codes::INVOICE_TYPES[$code] ?? $code,
            'income_class' => $incomeClass,
            'income_class_category' => $incomeCategory,
        ];
    }

    /**
     * Lowercase + strip Greek accents so "Πώλησης" / "πωλησησ" both match.
     */
    private static function fold(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');

        return strtr($s, [
            'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω',
            'ϊ' => 'ι', 'ϋ' => 'υ', 'ΐ' => 'ι', 'ΰ' => 'υ', 'ς' => 'σ',
        ]);
    }
}
