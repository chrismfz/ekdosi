<?php

namespace App\Support\MyData;

/**
 * AADE myDATA code tables (the Παράρτημα / Appendix §8 of the official
 * "myDATA API Documentation v2.0.0"). The full doc lives in the repo
 * root: myDATA_API_Documentation_v2.0.0_preofficial_erp.md.
 *
 * These are the authoritative value sets the SendInvoices payload is
 * validated against by AADE. Centralised here so `mydata:preflight`,
 * `MyDataSubmitter`, and the Filament forms all check against ONE source
 * instead of scattering magic strings. When AADE publishes a spec update,
 * this file is the single place to refresh.
 *
 * Section references (§) point at the headings in that Markdown doc.
 */
final class Codes
{
    /**
     * §8.1 Είδη παραστατικών — invoice type code → description.
     * The full catalogue; we issue only a subset (see INCOME_TYPE_PREFIXES).
     *
     * @var array<string, string>
     */
    public const INVOICE_TYPES = [
        '1.1' => 'Τιμολόγιο Πώλησης',
        '1.2' => 'Τιμολόγιο Πώλησης / Ενδοκοινοτικές Παραδόσεις',
        '1.3' => 'Τιμολόγιο Πώλησης / Παραδόσεις Τρίτων Χωρών',
        '1.4' => 'Τιμολόγιο Πώλησης / Πώληση για Λογαριασμό Τρίτων',
        '1.5' => 'Τιμολόγιο Πώλησης / Εκκαθάριση Πωλήσεων Τρίτων',
        '1.6' => 'Τιμολόγιο Πώλησης / Συμπληρωματικό Παραστατικό',
        '2.1' => 'Τιμολόγιο Παροχής',
        '2.2' => 'Τιμολόγιο Παροχής / Ενδοκοινοτική Παροχή Υπηρεσιών',
        '2.3' => 'Τιμολόγιο Παροχής / Παροχή Υπηρεσιών σε λήπτη Τρίτης Χώρας',
        '2.4' => 'Τιμολόγιο Παροχής / Συμπληρωματικό Παραστατικό',
        '3.1' => 'Τίτλος Κτήσης (μη υπόχρεος Εκδότης)',
        '3.2' => 'Τίτλος Κτήσης (άρνηση έκδοσης από υπόχρεο Εκδότη)',
        '5.1' => 'Πιστωτικό Τιμολόγιο / Συσχετιζόμενο',
        '5.2' => 'Πιστωτικό Τιμολόγιο / Μη Συσχετιζόμενο',
        '6.1' => 'Στοιχείο Αυτοπαράδοσης',
        '6.2' => 'Στοιχείο Ιδιοχρησιμοποίησης',
        '7.1' => 'Συμβόλαιο - Έσοδο',
        '8.1' => 'Ενοίκια - Έσοδο',
        '8.2' => 'Τέλος ανθεκτικότητας κλιματικής κρίσης',
        '8.4' => 'Απόδειξη Είσπραξης POS',
        '8.5' => 'Απόδειξη Επιστροφής POS',
        '8.6' => 'Δελτίο Παραγγελίας Εστίασης',
        '9.1' => 'Δελτίο Αποστολής Συσχετιζόμενο',
        '9.2' => 'Συγκεντρωτικό Δελτίο Αποστολής',
        '9.3' => 'Δελτίο Αποστολής',
        '10.1' => 'Δελτίο Ποσοτικής Παραλαβής Συσχετιζόμενο',
        '10.2' => 'Δελτίο Ποσοτικής Παραλαβής Μη Συσχετιζόμενο',
        '11.1' => 'ΑΛΠ',
        '11.2' => 'ΑΠΥ',
        '11.3' => 'Απλοποιημένο Τιμολόγιο',
        '11.4' => 'Πιστωτικό Στοιχ. Λιανικής',
        '11.5' => 'Απόδειξη Λιανικής Πώλησης για Λογ/σμό Τρίτων',
        '13.1' => 'Έξοδα - Αγορές Λιανικών Συναλλαγών',
        '13.2' => 'Παροχή Λιανικών Συναλλαγών',
        '13.3' => 'Κοινόχρηστα',
        '13.4' => 'Συνδρομές',
        '13.30' => 'Παραστατικά Οντότητας ως Αναγράφονται από την ίδια',
        '13.31' => 'Πιστωτικό Στοιχ. Λιανικής',
        '14.1' => 'Τιμολόγιο / Ενδοκοινοτικές Αποκτήσεις',
        '14.2' => 'Τιμολόγιο / Αποκτήσεις Τρίτων Χωρών',
        '14.3' => 'Τιμολόγιο / Ενδοκοινοτική Λήψη Υπηρεσιών',
        '14.4' => 'Τιμολόγιο / Λήψη Υπηρεσιών Τρίτων Χωρών',
        '14.5' => 'ΕΦΚΑ και λοιποί Ασφαλιστικοί Οργανισμοί',
        '14.30' => 'Παραστατικά Οντότητας ως Αναγράφονται από την ίδια',
        '14.31' => 'Πιστωτικό',
        '15.1' => 'Συμβόλαιο - Έξοδο',
        '16.1' => 'Ενοίκιο Έξοδο',
        '17.1' => 'Μισθοδοσία',
        '17.2' => 'Αποσβέσεις',
        '17.3' => 'Λοιπές Εγγραφές Τακτοποίησης Εσόδων - Λογιστική Βάση',
        '17.4' => 'Λοιπές Εγγραφές Τακτοποίησης Εσόδων - Φορολογική Βάση',
        '17.5' => 'Λοιπές Εγγραφές Τακτοποίησης Εξόδων - Λογιστική Βάση',
        '17.6' => 'Λοιπές Εγγραφές Τακτοποίησης Εξόδων - Φορολογική Βάση',
    ];

    /**
     * Invoice-type prefixes that an issuing entity files as INCOME and
     * which therefore require an income classification (errors [230]).
     * Expense/receiver types (13.x, 14.x, 15.x, 16.x) and settlement
     * types (17.x) are out of scope for our seller-side issuing.
     *
     * @var list<string>
     */
    public const INCOME_TYPE_PREFIXES = ['1', '2', '5', '6', '7', '8', '11'];

    /**
     * §8.2 Κατηγορία Φ.Π.Α. — vatCategory enum → percent rate.
     * 7 = Άνευ ΦΠΑ (0%, needs vatExemptionCategory — error [217]).
     * 8 = Εγγραφές χωρίς ΦΠΑ (no VAT, e.g. payroll/depreciation).
     *
     * @var array<int, float|null>
     */
    public const VAT_CATEGORY_RATES = [
        1 => 24.0,
        2 => 13.0,
        3 => 6.0,
        4 => 17.0,
        5 => 9.0,
        6 => 4.0,
        7 => 0.0,   // exempt — vatExemptionCategory mandatory
        8 => null,  // records without VAT
        9 => 3.0,   // αρ.31 ν.5057/2023
        10 => 4.0,  // αρ.31 ν.5057/2023 (island) — collides with 6 at 4%
    ];

    /**
     * §8.3 Κατηγορία Αιτίας Εξαίρεσης ΦΠΑ — valid exemption reason codes
     * (1–31, ν.5144/2024). Required when vatCategory = 7.
     *
     * @var list<int>
     */
    public const VAT_EXEMPTION_CATEGORIES = [
        1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16,
        17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31,
    ];

    /**
     * §8.12 Τρόποι Πληρωμής — payment method type → description.
     *
     * @var array<int, string>
     */
    public const PAYMENT_METHODS = [
        1 => 'Επαγ. Λογαριασμός Πληρωμών Ημεδαπής',
        2 => 'Επαγ. Λογαριασμός Πληρωμών Αλλοδαπής',
        3 => 'Μετρητά',
        4 => 'Επιταγή',
        5 => 'Επί Πιστώσει',
        6 => 'Web Banking',
        7 => 'POS/e-POS',
        8 => 'Άμεσες Πληρωμές IRIS',
    ];

    /**
     * §8.8 Κωδικός Κατηγορίας Χαρακτηρισμού Εσόδων — income classification
     * category codes.
     *
     * @var list<string>
     */
    public const INCOME_CLASS_CATEGORIES = [
        'category1_1', 'category1_2', 'category1_3', 'category1_4',
        'category1_5', 'category1_6', 'category1_7', 'category1_8',
        'category1_9', 'category1_10', 'category1_95', 'category3',
    ];

    /**
     * §8.9 Κωδικός Τύπου Χαρακτηρισμού Εσόδων — income classification
     * type (E3_*) codes.
     *
     * @var list<string>
     */
    public const INCOME_CLASS_TYPES = [
        'E3_106', 'E3_205', 'E3_210', 'E3_305', 'E3_310', 'E3_318',
        'E3_561_001', 'E3_561_002', 'E3_561_003', 'E3_561_004',
        'E3_561_005', 'E3_561_006', 'E3_561_007',
        'E3_562', 'E3_563', 'E3_564', 'E3_565', 'E3_566', 'E3_567',
        'E3_568', 'E3_570', 'E3_595', 'E3_596', 'E3_597',
        'E3_880_001', 'E3_880_002', 'E3_880_003', 'E3_880_004',
        'E3_881_001', 'E3_881_002', 'E3_881_003', 'E3_881_004',
        'E3_598_001', 'E3_598_003',
    ];

    /**
     * §8.4 Κατηγορία Παρακρατούμενων Φόρων — withholding tax categories.
     *
     * @var list<int>
     */
    public const WITHHOLDING_CATEGORIES = [
        1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18,
    ];

    /**
     * §8.13 Είδος Ποσότητας — quantity type codes.
     *
     * @var array<int, string>
     */
    public const QUANTITY_TYPES = [
        1 => 'Τεμάχια',
        2 => 'Κιλά',
        3 => 'Λίτρα',
        4 => 'Μέτρα',
        5 => 'Τετραγωνικά Μέτρα',
        6 => 'Κυβικά Μέτρα',
        7 => 'Τεμάχια_Λοιπές Περιπτώσεις',
    ];

    public static function invoiceTypeExists(string $code): bool
    {
        return isset(self::INVOICE_TYPES[$code]);
    }

    /** Does this invoice type require an income classification? */
    public static function isIncomeInvoiceType(string $code): bool
    {
        $prefix = explode('.', $code)[0];

        return in_array($prefix, self::INCOME_TYPE_PREFIXES, true);
    }

    public static function isValidIncomeClassType(string $code): bool
    {
        return in_array($code, self::INCOME_CLASS_TYPES, true);
    }

    public static function isValidIncomeClassCategory(string $code): bool
    {
        return in_array($code, self::INCOME_CLASS_CATEGORIES, true);
    }

    public static function paymentMethodExists(int $type): bool
    {
        return isset(self::PAYMENT_METHODS[$type]);
    }

    public static function vatExemptionExists(int $code): bool
    {
        return in_array($code, self::VAT_EXEMPTION_CATEGORIES, true);
    }

    /**
     * vatCategory enum(s) matching a percent rate. Usually one, but 4%
     * is ambiguous (6 = pre-existing island rate vs 10 = ν.5057/2023),
     * so this returns ALL matches — callers decide / flag.
     *
     * @return list<int>
     */
    public static function vatCategoriesForRate(float $rate): array
    {
        $matches = [];
        foreach (self::VAT_CATEGORY_RATES as $enum => $pct) {
            if ($pct !== null && abs($pct - $rate) < 0.001) {
                $matches[] = $enum;
            }
        }

        return $matches;
    }
}
