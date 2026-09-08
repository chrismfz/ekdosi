<?php

namespace App\Support\Accounting;

/**
 * A LIGHT, indicative Greek chart-of-accounts (ΕΓΛΣ) layer over the myDATA
 * classification we already store. It maps each income (`category1_x`) /
 * expense (`category2_x`) classification category to a default ΕΓΛΣ account so
 * the Βιβλίο Εσόδων-Εξόδων can show a «Λογαριασμός» the accountant recognises
 * (70/73/61/62…).
 *
 * DELIBERATELY a static default, not a per-tenant editable table: the mapping
 * from a myDATA category to an ΕΓΛΣ group is standard, and the accountant's own
 * software (Union κ.λπ.) does its definitive mapping on import. This is a
 * convenience/readability layer — the figures and the legal classification
 * remain the myDATA category. A per-tenant editable chart is a clean follow-up
 * if a tenant needs analytical sub-accounts (e.g. 73.00.0000).
 *
 * The codes are at GROUP level (two digits) and INDICATIVE — they orient the
 * accountant, they are not a substitute for their books.
 */
class ChartOfAccounts
{
    /**
     * ΕΓΛΣ group accounts we reference, code => Greek name.
     *
     * @var array<string, string>
     */
    public const ACCOUNTS = [
        '14' => 'Έπιπλα & λοιπός εξοπλισμός (πάγια)',
        '20' => 'Εμπορεύματα',
        '24' => 'Πρώτες & βοηθητικές ύλες',
        '54' => 'Φ.Π.Α.',
        '60' => 'Αμοιβές & έξοδα προσωπικού',
        '61' => 'Αμοιβές & έξοδα τρίτων',
        '62' => 'Παροχές τρίτων',
        '64' => 'Διάφορα έξοδα',
        '66' => 'Αποσβέσεις παγίων',
        '70' => 'Πωλήσεις εμπορευμάτων',
        '71' => 'Πωλήσεις προϊόντων',
        '73' => 'Πωλήσεις υπηρεσιών',
        '75' => 'Λοιπά έσοδα',
        '78' => 'Έσοδα από πώληση/ιδιοπαραγωγή παγίων',
        '82' => 'Έξοδα & έσοδα προηγουμένων χρήσεων',
    ];

    /**
     * myDATA classification category (category1_x income / category2_x expense)
     * => default ΕΓΛΣ account code. Categories with no natural P&L account
     * (informational, third-party clearing) are intentionally absent → null.
     *
     * @var array<string, string>
     */
    public const CATEGORY_MAP = [
        // Income (category1_x → ομάδα 7 + λοιπά)
        'category1_1' => '70',   // Πώληση εμπορευμάτων
        'category1_2' => '71',   // Πώληση προϊόντων
        'category1_3' => '73',   // Παροχή υπηρεσιών
        'category1_4' => '78',   // Πώληση παγίων
        'category1_5' => '75',   // Λοιπά έσοδα / κέρδη
        'category1_6' => '78',   // Αυτοπαραδόσεις / ιδιοχρησιμοποιήσεις
        'category1_8' => '82',   // Έσοδα προηγούμενων χρήσεων

        // Expense (category2_x → ομάδες 6 / 2 / 1)
        'category2_1' => '20',   // Αγορές εμπορευμάτων
        'category2_2' => '24',   // Αγορές Α'-Β' υλών
        'category2_3' => '61',   // Λήψη υπηρεσιών
        'category2_4' => '64',   // Γενικά έξοδα (με δικαίωμα έκπτωσης ΦΠΑ)
        'category2_5' => '64',   // Γενικά έξοδα (χωρίς δικαίωμα έκπτωσης ΦΠΑ)
        'category2_6' => '60',   // Αμοιβές & παροχές προσωπικού
        'category2_7' => '14',   // Αγορές παγίων
        'category2_8' => '66',   // Αποσβέσεις παγίων
        'category2_10' => '82',  // Έξοδα προηγούμενων χρήσεων
        'category2_13' => '20',  // Αποθέματα έναρξης περιόδου
        'category2_14' => '20',  // Αποθέματα λήξης περιόδου
    ];

    /** Default ΕΓΛΣ account code for a myDATA classification category. */
    public static function codeFor(?string $category): ?string
    {
        if ($category === null || $category === '') {
            return null;
        }

        return self::CATEGORY_MAP[$category] ?? null;
    }

    /** Greek name for an ΕΓΛΣ account code. */
    public static function nameFor(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return self::ACCOUNTS[$code] ?? null;
    }

    /**
     * The account a classification category maps to, as ['code' => , 'name' => ],
     * or null when unmapped.
     *
     * @return array{code: string, name: string}|null
     */
    public static function accountFor(?string $category): ?array
    {
        $code = self::codeFor($category);
        if ($code === null) {
            return null;
        }

        return ['code' => $code, 'name' => self::nameFor($code) ?? $code];
    }

    /**
     * The full chart, code-sorted, as a list of ['code', 'name'].
     *
     * @return list<array{code: string, name: string}>
     */
    public static function chart(): array
    {
        $accounts = self::ACCOUNTS;
        ksort($accounts, SORT_STRING);

        $out = [];
        foreach ($accounts as $code => $name) {
            $out[] = ['code' => $code, 'name' => $name];
        }

        return $out;
    }
}
