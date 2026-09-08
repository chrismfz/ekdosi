<?php

namespace App\Support\MyData;

use App\Support\GreekText;

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
     * @return array{code: string, label: string, income_class: ?string, income_class_category: ?string, goods: bool}|null
     */
    public static function suggest(string $name, bool $isCredit = false, bool $isReturn = false): ?array
    {
        $n = GreekText::fold($name);

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
     * @return array{code: string, label: string, income_class: ?string, income_class_category: ?string, goods: bool}
     */
    private static function hit(string $code): array
    {
        // Classification defaults come from the ONE canonical source (Codes),
        // shared with the seeder — the suggestion and the starter seed can't
        // disagree on what a code means.
        $defaults = Codes::typeDefaults($code);

        return [
            'code' => $code,
            'label' => Codes::INVOICE_TYPES[$code] ?? $code,
            'income_class' => $defaults['income'],
            'income_class_category' => $defaults['category'],
            'goods' => $defaults['goods'],
        ];
    }

    /**
     * Lowercase + strip Greek accents so "Πώλησης" / "πωλησησ" both match.
     */
}
