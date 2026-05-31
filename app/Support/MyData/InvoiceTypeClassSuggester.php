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
     * @return array{code: string, label: string}|null
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

            return self::hit('5.1'); // correlated; operator switches to 5.2 if not
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
        if (str_contains($n, 'δελτιο')) {
            if (str_contains($n, 'συγκεντρωτικ')) {
                return self::hit('9.2');
            }
            if (str_contains($n, 'παραλαβ')) {
                return self::hit('10.2');
            }

            return self::hit('9.3');
        }

        return null;
    }

    /**
     * @return array{code: string, label: string}
     */
    private static function hit(string $code): array
    {
        return ['code' => $code, 'label' => Codes::INVOICE_TYPES[$code] ?? $code];
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
