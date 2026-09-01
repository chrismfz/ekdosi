<?php

namespace App\Support\MyData;

use Firebed\AadeMyData\Enums\ExpenseClassificationCategory;
use Firebed\AadeMyData\Enums\ExpenseClassificationType;
use Firebed\AadeMyData\Enums\IncomeClassificationCategory;
use Firebed\AadeMyData\Enums\IncomeClassificationType;

/**
 * AADE myDATA code tables (the Παράρτημα / Appendix §8 of the official
 * "myDATA API Documentation v2.0.0"). The full doc lives in the repo
 * root: docs/aade/myDATA_API_Documentation_v2.0.0_preofficial_erp.md.
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
     * Recommended default classification per §8.1 ISSUING type — the SINGLE
     * source consumed by BOTH the by-the-book starter seed
     * (MyDataLookupSeeder::seedInvoiceTypes) AND the name-based one-click apply
     * (InvoiceTypeClassSuggester), so the two write paths can never disagree on
     * what a code means (a divergence would be a silent legal-filing bug).
     *
     * Per code: the income-classification chain (E3 type + per-rate category;
     * null where there is no single safe default — delivery notes carry no
     * revenue, and τίτλος κτήσης / αυτοπαράδοση / ενοίκια / συμβόλαια use
     * specialised E3 lines the operator picks) and `goods` (= the §8.1 type
     * carries a per-line quantity at filing, G5 / error [205]).
     *
     * @var array<string, array{income: ?string, category: ?string, goods: bool}>
     */
    public const TYPE_DEFAULTS = [
        '1.1' => ['income' => 'E3_561_001', 'category' => 'category1_1', 'goods' => true],
        '1.2' => ['income' => 'E3_561_005', 'category' => 'category1_1', 'goods' => true],
        // Third-country goods export (1.3): E3_561_006 «Εξωτερικού Τρίτων Χωρών»,
        // NOT the intra-community E3_561_005 used by 1.2 (MYD-001).
        '1.3' => ['income' => 'E3_561_006', 'category' => 'category1_1', 'goods' => true],
        '2.1' => ['income' => 'E3_561_001', 'category' => 'category1_3', 'goods' => false],
        '2.2' => ['income' => 'E3_561_005', 'category' => 'category1_3', 'goods' => false],
        // Cross-border services to a third country (2.3): third-country E3_561_006
        // (the non-EU twin of 2.2), NOT intra-community E3_561_005 (MYD-001).
        '2.3' => ['income' => 'E3_561_006', 'category' => 'category1_3', 'goods' => false],
        '3.1' => ['income' => null, 'category' => null, 'goods' => false],
        '5.1' => ['income' => 'E3_561_001', 'category' => 'category1_3', 'goods' => false],
        '5.2' => ['income' => 'E3_561_001', 'category' => 'category1_3', 'goods' => false],
        '6.1' => ['income' => null, 'category' => null, 'goods' => false],
        '6.2' => ['income' => null, 'category' => null, 'goods' => false],
        '7.1' => ['income' => null, 'category' => null, 'goods' => false],
        '8.1' => ['income' => null, 'category' => null, 'goods' => false],
        '9.1' => ['income' => null, 'category' => null, 'goods' => true],
        '9.2' => ['income' => null, 'category' => null, 'goods' => true],
        '9.3' => ['income' => null, 'category' => null, 'goods' => true],
        '10.1' => ['income' => null, 'category' => null, 'goods' => true],
        '10.2' => ['income' => null, 'category' => null, 'goods' => true],
        '11.1' => ['income' => 'E3_561_003', 'category' => 'category1_1', 'goods' => true],
        '11.2' => ['income' => 'E3_561_003', 'category' => 'category1_3', 'goods' => false],
        '11.3' => ['income' => 'E3_561_003', 'category' => 'category1_3', 'goods' => false],
        '11.4' => ['income' => 'E3_561_003', 'category' => 'category1_3', 'goods' => false],
    ];

    /**
     * Default classification for a §8.1 code (all-null / not-goods when unknown).
     *
     * @return array{income: ?string, category: ?string, goods: bool}
     */
    public static function typeDefaults(string $code): array
    {
        return self::TYPE_DEFAULTS[$code] ?? ['income' => null, 'category' => null, 'goods' => false];
    }

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
     * Supplier-expense (εισροές) type prefixes — αγορές λιανικής (13.x) and
     * ενδοκοινοτικές/τρίτων-χωρών αποκτήσεις & λήψεις υπηρεσιών (14.x). These
     * are the orphan types that belong to the Έξοδα console (RequestDocs +
     * import). Kept as a named const beside INCOME_TYPE_PREFIXES so the two
     * halves of the taxonomy stay at the same altitude (transmittedDocBucket).
     *
     * @var list<string>
     */
    public const EXPENSE_TYPE_PREFIXES = ['13', '14'];

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
        10 => 4.0,  // αρ.31 ν.5057/2023 — collides with 6 at 4%
    ];

    /**
     * §8.2 official descriptions (verbatim from the AADE spec, §8.2 table) used
     * to SEED a tenant's vat_categories with the standard Greek rates. There is
     * NO myDATA "fetch VAT rates" API — §8.2 is a static enum in the spec — so
     * the seed source is this committed table (which IS the AADE spec).
     *
     * Keyed by §8.2 code. Code 8 (records without VAT) is intentionally omitted
     * from the seed: it has no numeric rate and isn't a sales-line VAT category.
     *
     * @var array<int, string>
     */
    public const VAT_CATEGORY_LABELS = [
        1 => 'Κανονικός ΦΠΑ 24%',
        2 => 'Μειωμένος ΦΠΑ 13%',
        3 => 'Υπερμειωμένος ΦΠΑ 6%',
        4 => 'ΦΠΑ νήσων 17%',
        5 => 'ΦΠΑ νήσων 9%',
        6 => 'ΦΠΑ νήσων 4%',
        7 => 'Άνευ ΦΠΑ 0%',
        9 => 'ΦΠΑ 3% (αρ.31 ν.5057/2023)',
        10 => 'ΦΠΑ 4% (αρ.31 ν.5057/2023)',
    ];

    /**
     * The standard sales-line VAT categories to seed, as [rate, description]
     * rows. Skips code 8 (no rate) and code 10 (duplicate 4% of code 6 — would
     * just create a confusing second 4% row; a tenant on the ν.5057/2023
     * regime can add it manually). Code 7 (0%) is seeded WITHOUT an exemption
     * reason — the operator sets §8.3 per their case (Setup → VAT Categories).
     *
     * @return list<array{rate: float, description: string}>
     */
    public static function vatCategorySeedRows(): array
    {
        $rows = [];
        foreach ([1, 2, 3, 4, 5, 6, 7] as $code) {
            $rows[] = [
                'rate' => (float) self::VAT_CATEGORY_RATES[$code],
                'description' => self::VAT_CATEGORY_LABELS[$code],
            ];
        }

        return $rows;
    }

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
     * §8.3 human-readable reasons (ν.5144/2024) — verbatim from the AADE spec
     * (myDATA_API_Documentation_v2.0.0 §8.3, lines 2187–2226). Used to label the
     * exemption-reason picker so the operator picks the RIGHT reason instead of
     * a meaningless "Κατηγορία 16". Do NOT paraphrase — these are legal citations.
     *
     * For an EU intra-community supply (reverse charge) the reason is
     * **code 16 — άρθρο 45** (ex-«άρθρο 39α»).
     *
     * @var array<int, string>
     */
    public const VAT_EXEMPTION_LABELS = [
        1 => 'Χωρίς ΦΠΑ - άρθρο 2 και 3 του Κώδικα ΦΠΑ',
        2 => 'Χωρίς ΦΠΑ - άρθρο 5 του Κώδικα ΦΠΑ',
        3 => 'Χωρίς ΦΠΑ - άρθρο 17 του Κώδικα ΦΠΑ',
        4 => 'Χωρίς ΦΠΑ - άρθρο 18 του Κώδικα ΦΠΑ',
        5 => 'Χωρίς ΦΠΑ - άρθρο 21 του Κώδικα ΦΠΑ',
        6 => 'Χωρίς ΦΠΑ - άρθρο 24 του Κώδικα ΦΠΑ',
        7 => 'Χωρίς ΦΠΑ - άρθρο 27 του Κώδικα ΦΠΑ',
        8 => 'Χωρίς ΦΠΑ - άρθρο 29 του Κώδικα ΦΠΑ',
        9 => 'Χωρίς ΦΠΑ - άρθρο 30 του Κώδικα ΦΠΑ',
        10 => 'Χωρίς ΦΠΑ - άρθρο 31 του Κώδικα ΦΠΑ',
        11 => 'Χωρίς ΦΠΑ - άρθρο 32 του Κώδικα ΦΠΑ',
        12 => 'Χωρίς ΦΠΑ - άρθρο 32 του Κώδικα ΦΠΑ - Πλοία Ανοικτής Θαλάσσης του Κώδικα ΦΠΑ',
        13 => 'Χωρίς ΦΠΑ - άρθρο 32 .1.γ. του Κώδικα ΦΠΑ - Πλοία Ανοικτής Θαλάσσης του Κώδικα ΦΠΑ',
        14 => 'Χωρίς ΦΠΑ - άρθρο 33 του Κώδικα ΦΠΑ',
        15 => 'Χωρίς ΦΠΑ - άρθρο 44 του Κώδικα ΦΠΑ',
        16 => 'Χωρίς ΦΠΑ - άρθρο 45 του Κώδικα ΦΠΑ',
        17 => 'Χωρίς ΦΠΑ - άρθρο 47 του Κώδικα ΦΠΑ',
        18 => 'Χωρίς ΦΠΑ - άρθρο 48 του Κώδικα ΦΠΑ',
        19 => 'Χωρίς ΦΠΑ - άρθρο 54 του Κώδικα ΦΠΑ',
        20 => 'ΦΠΑ εμπεριεχόμενος - άρθρο 50 του Κώδικα ΦΠΑ',
        21 => 'ΦΠΑ εμπεριεχόμενος - άρθρο 51 του Κώδικα ΦΠΑ',
        22 => 'ΦΠΑ εμπεριεχόμενος - άρθρο 52 του Κώδικα ΦΠΑ',
        23 => 'ΦΠΑ εμπεριεχόμενος - άρθρο 53 του Κώδικα ΦΠΑ',
        24 => 'Χωρίς ΦΠΑ - άρθρο 8 του Κώδικα ΦΠΑ',
        25 => 'Χωρίς ΦΠΑ - ΠΟΛ.1029/1995',
        26 => 'Χωρίς ΦΠΑ - ΠΟΛ.1167/2015',
        27 => 'Λοιπές Εξαιρέσεις ΦΠΑ',
        28 => 'Χωρίς ΦΠΑ – άρθρο 29 περ. β’ παρ.1 του Κώδικα ΦΠΑ, (Tax Free)',
        29 => 'Χωρίς ΦΠΑ – άρθρο 56 του Κώδικα ΦΠΑ (OSS_μη ενωσιακό καθεστώς)',
        30 => 'Χωρίς ΦΠΑ – άρθρο 57 του Κώδικα ΦΠΑ (OSS_ενωσιακό καθεστώς)',
        31 => 'Χωρίς ΦΠΑ – άρθρο 58 του Κώδικα ΦΠΑ (IOSS)',
    ];

    /**
     * §8.3 exemption reason that AADE expects for an EU intra-community supply
     * (reverse charge): code 16 (άρθρο 45, ex-«άρθρο 39α»). Surfaced as the
     * recommended default in the VAT-category form's 0% helper.
     */
    public const VAT_EXEMPTION_INTRACOMMUNITY = 16;

    /**
     * Options for an exemption-reason picker: "16 — Χωρίς ΦΠΑ - άρθρο 45 …".
     *
     * @return array<int, string>
     */
    public static function vatExemptionOptions(): array
    {
        $out = [];
        foreach (self::VAT_EXEMPTION_CATEGORIES as $code) {
            $label = self::VAT_EXEMPTION_LABELS[$code] ?? ('Κατηγορία '.$code);
            $out[$code] = $code.' — '.$label;
        }

        return $out;
    }

    /**
     * Credit-note types that are NON-correlated (§8.1): AADE FORBIDS
     * <correlatedInvoices> on these. 5.1 = correlated (link required),
     * 5.2 = non-correlated (link forbidden). The submitter must not send
     * a correlation for these even when an original exists locally.
     *
     * @var list<string>
     */
    public const NON_CORRELATED_CREDIT_TYPES = ['5.2'];

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

    /**
     * Quantity units offered in the delivery-line pickers. Code 7 (Τεμάχια_Λοιπές
     * Περιπτώσεις) is EXCLUDED: AADE marks otherMeasurementUnitQuantity/Title
     * mandatory for it (§8.13 note 9) and we do not model/emit those yet, so
     * offering 7 would only let an operator build a payload AADE must reject
     * (MYD-016). Keep the full QUANTITY_TYPES map for DISPLAY of legacy rows.
     *
     * @return array<int, string>
     */
    public static function selectableQuantityTypes(): array
    {
        return array_diff_key(self::QUANTITY_TYPES, [7 => true]);
    }

    public static function invoiceTypeExists(string $code): bool
    {
        return isset(self::INVOICE_TYPES[$code]);
    }

    /**
     * The VAT rates ekdosi can actually FILE — i.e. the exact set
     * MyDataSubmitter::vatCategoryFor() maps to an AADE vatCategory enum.
     *
     * NOTE this is a SUBSET of VAT_CATEGORY_RATES: that table lists the full
     * §8.2 enum including codes 9 (3%) and 10 (4%) from ν.5057/2023,
     * which the submitter does NOT yet map (no match arm → it throws). So the
     * "would AADE accept a line at this rate" check (ETL warning, table flag)
     * MUST use THIS set, not the full enum — otherwise a 3% category passes the
     * warning clean and then explodes at filing. Keep in lockstep with
     * vatCategoryFor(): if a 3% arm is added there, add 3.0 here.
     *
     * @var list<float>
     */
    public const FILEABLE_VAT_RATES = [0.0, 4.0, 6.0, 9.0, 13.0, 17.0, 24.0];

    /**
     * Is this a VAT rate ekdosi can file at AADE? Single source of truth for
     * "would AADE accept a line at this rate" — used by the ETL post-import
     * warning AND the VatCategories table flag, and kept in sync with
     * MyDataSubmitter::vatCategoryFor via FILEABLE_VAT_RATES. Tolerant float
     * compare (0.01).
     */
    public static function vatRateIsValid(int|float|string|null $rate): bool
    {
        if ($rate === null || $rate === '') {
            return false;
        }
        foreach (self::FILEABLE_VAT_RATES as $r) {
            if (abs((float) $rate - $r) < 0.01) {
                return true;
            }
        }

        return false;
    }

    /**
     * MYD-8: "would AADE accept a line at this rate" WITH the ambiguous-rate
     * override taken into account. 3% (ν.5057/2023) is NOT in FILEABLE_VAT_RATES
     * because the DIRECT vatCategoryFor() mapping throws on it — but it IS fileable
     * when the VatCategory carries a valid §8.2 `mydata_vat_category` override
     * (3%→9). (4% is already directly fileable — category 6 — so it needs no
     * override to file; its override only picks 6-vs-10.) Pass the override so the
     * readiness surfaces (VAT-categories badge, ETL warning) stop false-flagging a
     * correctly-configured 3% row. Without an override the answer is unchanged.
     */
    public static function vatRateFileable(int|float|string|null $rate, int|string|null $override = null): bool
    {
        if (self::vatRateIsValid($rate)) {
            return true;
        }
        if ($override === null) {
            return false;
        }

        // The only rate that files ONLY via an override is 3%. And the override
        // must be a §8.2 code whose OWN rate matches — otherwise a 3% row with,
        // say, override=8 (records-without-VAT) would read green here yet file a
        // no-VAT category with a nonzero 3%-derived vatAmount → AADE rejection.
        $r = (float) $rate;
        if (abs($r - 3.0) >= 0.01) {
            return false;
        }
        $overrideRate = self::VAT_CATEGORY_RATES[(int) $override] ?? null;

        return $overrideRate !== null && abs($overrideRate - $r) < 0.01;
    }

    /** Does this invoice type require an income classification? */
    public static function isIncomeInvoiceType(string $code): bool
    {
        $prefix = explode('.', $code)[0];

        return in_array($prefix, self::INCOME_TYPE_PREFIXES, true);
    }

    /**
     * EU member states (ISO 3166-1 alpha-2), INCLUDING GR — feeds the
     * counterpart country↔type cross-check (AADE [242]-[244]).
     *
     * @var list<string>
     */
    public const EU_MEMBER_COUNTRIES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR',
        'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK',
        'SI', 'ES', 'SE',
    ];

    public static function isEuCountry(string $iso): bool
    {
        return in_array(strtoupper($iso), self::EU_MEMBER_COUNTRIES, true);
    }

    /**
     * MYD-6: the expected counterpart-country class for an invoice type, so we can
     * pre-empt AADE's opaque [242]-[244] rejections with a clear error:
     *   'GR'     → must be Greece            (domestic 1.1 / 2.1)          → [242]
     *   'EU'     → EU member but not Greece  (intra-community 1.2 / 2.2)   → [243]
     *   'NON_EU' → outside the EU            (third country 1.3 / 2.3)     → [244]
     *   null     → no constraint / not cross-checked here.
     * Deliberately covers only the unambiguous 1.x/2.x sales types; credit notes,
     * self-billing and retail are left unconstrained (their country rules vary /
     * carry no counterpart).
     */
    public static function counterpartCountryClass(?string $type): ?string
    {
        return match ($type) {
            '1.1', '2.1' => 'GR',
            '1.2', '2.2' => 'EU',
            '1.3', '2.3' => 'NON_EU',
            default => null,
        };
    }

    /**
     * MYD-9: is this §8.1 type a GOODS document (per TYPE_DEFAULTS)? Goods types
     * carry a per-line quantity; service types FORBID it (AADE [205]). null =
     * unknown type (no opinion). Drives the config-audit quantity-flag check.
     */
    public static function typeIsGoods(?string $type): ?bool
    {
        return self::TYPE_DEFAULTS[$type]['goods'] ?? null;
    }

    /**
     * §8.1 invoice types that are genuinely CREDIT NOTES (πιστωτικά) — they
     * REDUCE the figure they relate to. Income side (5.1, 5.2 πιστωτικό
     * τιμολόγιο, 11.4 πιστωτικό λιανικής) and expense side (13.31, 14.31
     * πιστωτικά ημεδαπής/αλλοδαπής). This list is the authority for credit-note
     * IDENTITY (isCreditNoteType) AND the expense-side subtract rule in
     * LedgerBook / VatPeriodReport (matched against expenses.invoice_type).
     * Keep it to real credit notes only — other "reducing" documents that are
     * not πιστωτικά live in REDUCING_EXTRA_TYPES.
     *
     * @var list<string>
     */
    public const CREDIT_NOTE_TYPES = ['5.1', '5.2', '11.4', '13.31', '14.31'];

    /**
     * §8.1 types that are NOT credit notes but whose net/vat still REDUCE a sum
     * of transmitted myDATA documents (MYD-015). Kept separate from
     * CREDIT_NOTE_TYPES so credit-note identity stays exact — only documentSign()
     * (the myDATA VAT-picture aggregator) reads this, never isCreditNoteType().
     *
     * Explicit §8.x reporting-sign policy — the §8.x prefix alone is NOT enough:
     *   - 8.1 Ενοίκια-Έσοδο           → income (+)
     *   - 8.2 Τέλος ανθεκτικότητας     → income (+)
     *   - 8.4 Απόδειξη Είσπραξης POS   → income/collection (+)
     *   - 8.5 Απόδειξη Επιστροφής POS  → RETURN (−) ← here
     *   - 8.6 Δελτίο Παραγγελίας Εστίασης → order slip: sign (+); myDATA sends it
     *         with zero value, so it adds no revenue on its own (this zero-value
     *         expectation is not separately enforced).
     *
     * @var list<string>
     */
    public const REDUCING_EXTRA_TYPES = ['8.5'];

    public static function isCreditNoteType(?string $code): bool
    {
        return $code !== null && in_array($code, self::CREDIT_NOTE_TYPES, true);
    }

    /**
     * Reporting sign for a summed myDATA document: -1 for a credit note or other
     * reducing type (e.g. an 8.5 POS return), else +1.
     */
    public static function documentSign(?string $code): int
    {
        if ($code === null) {
            return 1;
        }

        return in_array($code, self::CREDIT_NOTE_TYPES, true)
            || in_array($code, self::REDUCING_EXTRA_TYPES, true) ? -1 : 1;
    }

    /**
     * Coarse economic bucket for a TRANSMITTED document. RequestTransmittedDocs
     * returns EVERYTHING the tenant filed — real sales, self-declared supplier
     * expenses (ενδοκοινοτικά/τρίτων χωρών), AND accounting entries (μισθοδοσία,
     * πάγια, τακτοποιήσεις). The sales console uses this to stop a €5.000
     * payroll (17.1) from sitting in the "αδέσποτα πωλήσεων" list looking like
     * a missed sale.
     *
     *   - 'income'  : 1/2/5/6/7/8/11 — real sales. Actionable on THIS console.
     *   - 'expense' : 13/14 — έξοδα/εισροές προμηθευτών. Belong to the Έξοδα
     *                 console (RequestDocs + 1-click import live there).
     *   - 'other'   : 3/15/16/17/… — μισθοδοσία, πάγια, ΕΦΚΑ, τακτοποιήσεις.
     *                 Self-declared accounting entries; informational only.
     */
    public static function transmittedDocBucket(?string $code): string
    {
        if ($code === null || $code === '') {
            return 'other';
        }

        if (self::isIncomeInvoiceType($code)) {
            return 'income';
        }

        $prefix = explode('.', $code)[0];

        return in_array($prefix, self::EXPENSE_TYPE_PREFIXES, true) ? 'expense' : 'other';
    }

    /**
     * For a NON-income transmitted doc (self-declared expenses + accounting
     * entries in RequestTransmittedDocs), the coarse VAT-picture category it
     * belongs to — so the dashboard breaks them out as their own lines
     * («Ενδοκοινοτικά», «Μισθοδοσία») instead of inflating Έσοδα.
     *
     *   14.x → intra-community / third-country
     *   13.x → retail expense (ΑΛΠ)
     *   17.x (+ 3/15/16) → payroll / other accounting entries
     *
     * @return array{key: string, label: string}
     */
    /**
     * Self-declared bucket KEYS that are accounting entries (μισθοδοσία/πάγια/
     * τακτοποιήσεις), NOT real expense invoices. Used to split the Έξοδα list so
     * a payroll doesn't read as a τιμολόγιο. One source for the UI.
     *
     * @var list<string>
     */
    public const ACCOUNTING_EXPENSE_CATEGORIES = ['payroll', 'depreciation', 'adjustments'];

    public static function selfDeclaredVatCategory(?string $code): array
    {
        $key = self::selfDeclaredVatCategoryKey($code);

        return ['key' => $key, 'label' => self::selfDeclaredVatCategoryLabel($key) ?? 'Λοιπά έξοδα'];
    }

    /**
     * The bucket KEY for a self-declared invoice TYPE code (e.g. '17.1' →
     * 'payroll'). Finer split for what an accountant commonly self-declares, so
     * ΕΦΚΑ / πάγια don't hide under a generic label and muddy the charts.
     */
    public static function selfDeclaredVatCategoryKey(?string $code): string
    {
        $code ??= '';
        $prefix = $code === '' ? '' : explode('.', $code)[0];

        return match (true) {
            $code === '14.5' => 'social_security',
            $code === '17.1' => 'payroll',
            $code === '17.2' => 'depreciation',
            $prefix === '14' => 'intracommunity',
            $prefix === '13' => 'retail_expense',
            $prefix === '17' => 'adjustments',
            default => 'other',
        };
    }

    /**
     * Greek label for a self-declared expense bucket KEY (the value stored in
     * `expenses.category`). THE single source of these labels —
     * selfDeclaredVatCategory() composes its 'label' from here, so the two can
     * never drift. Null for an unknown key (caller falls back to the raw key).
     *
     * @var array<string, string>
     */
    public static function selfDeclaredVatCategoryLabel(string $key): ?string
    {
        return [
            'social_security' => 'Ασφαλιστικές εισφορές (ΕΦΚΑ)',
            'payroll' => 'Μισθοδοσία',
            'depreciation' => 'Αποσβέσεις / Πάγια',
            'intracommunity' => 'Ενδοκοινοτικά / Τρίτων χωρών',
            'retail_expense' => 'Έξοδα λιανικής (ΑΛΠ)',
            'adjustments' => 'Λοιπές εγγραφές τακτοποίησης',
            'other' => 'Λοιπά έξοδα',
        ][$key] ?? null;
    }

    /**
     * value => Greek-label map of every self-declared bucket — for Filament
     * SelectFilters. Derived from the one label source above.
     *
     * @return array<string, string>
     */
    public static function selfDeclaredVatCategoryOptions(): array
    {
        $out = [];
        foreach (['retail_expense', 'intracommunity', 'social_security', 'payroll', 'depreciation', 'adjustments', 'other'] as $key) {
            $out[$key] = self::selfDeclaredVatCategoryLabel($key);
        }

        return $out;
    }

    public static function isValidIncomeClassType(string $code): bool
    {
        return in_array($code, self::INCOME_CLASS_TYPES, true);
    }

    public static function isValidIncomeClassCategory(string $code): bool
    {
        return in_array($code, self::INCOME_CLASS_CATEGORIES, true);
    }

    /**
     * §8.x Expense classification — type (E3_*) and category (category2_*).
     *
     * Unlike the income codes above (baked literals), the EXPENSE side has 88
     * types + 15 categories that firebed already ships as validated backed
     * enums (`ExpenseClassificationType` / `ExpenseClassificationCategory`) WITH
     * Greek labels. We delegate to them — re-transcribing 88 opaque E3 codes
     * here would only invite drift. Codes stays the single entry point.
     */
    public static function isValidExpenseClassType(string $code): bool
    {
        return ExpenseClassificationType::tryFrom($code) !== null;
    }

    public static function isValidExpenseClassCategory(string $code): bool
    {
        return ExpenseClassificationCategory::tryFrom($code) !== null;
    }

    /**
     * Greek label for ANY E3 classification type code, income OR expense
     * (RequestE3Info mixes both — E3_561_x income, E3_585_x/E3_581_x expense).
     * Tries both firebed enums; returns null if neither knows the code (AADE
     * occasionally returns aggregate pseudo-codes we leave raw).
     */
    public static function e3TypeLabel(string $code): ?string
    {
        return IncomeClassificationType::tryFrom($code)?->label()
            ?? ExpenseClassificationType::tryFrom($code)?->label();
    }

    /** Greek label for an E3 classification category (income or expense). */
    public static function e3CategoryLabel(string $code): ?string
    {
        return IncomeClassificationCategory::tryFrom($code)?->label()
            ?? ExpenseClassificationCategory::tryFrom($code)?->label();
    }

    /**
     * Which side of the Ε3 an E3_* type belongs to — so the overview can split
     * income (E3_56x) from expense (E3_58x) instead of summing them into one
     * meaningless grand total. Resolved off firebed's two backed enums (the
     * same source e3TypeLabel reads), with 'unknown' for any aggregate
     * pseudo-code AADE returns that neither enum knows.
     */
    public static function e3Direction(string $code): string
    {
        if (IncomeClassificationType::tryFrom($code) !== null) {
            return 'income';
        }
        if (ExpenseClassificationType::tryFrom($code) !== null) {
            return 'expense';
        }

        return 'unknown';
    }

    /**
     * value => "code — Greek label" maps for Filament Selects.
     *
     * @return array<string, string>
     */
    public static function expenseClassTypeOptions(): array
    {
        $out = [];
        foreach (ExpenseClassificationType::cases() as $c) {
            $out[$c->value] = $c->value.' — '.$c->label();
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    public static function expenseClassCategoryOptions(): array
    {
        $out = [];
        foreach (ExpenseClassificationCategory::cases() as $c) {
            $out[$c->value] = $c->value.' — '.$c->label();
        }

        return $out;
    }

    public static function paymentMethodExists(int $type): bool
    {
        return isset(self::PAYMENT_METHODS[$type]);
    }

    /** Is this a credit-note type that FORBIDS a correlation (e.g. 5.2)? */
    public static function isNonCorrelatedCreditType(string $code): bool
    {
        return in_array($code, self::NON_CORRELATED_CREDIT_TYPES, true);
    }

    /**
     * Does AADE ACCEPT a per-line <itemDescr> for this document type?
     *
     * The spec (§ itemDescr, doc line 1287) restricts it to «tax free ή που
     * είναι τιμολόγια και δελτία αποστολής ή απλά δελτία διακίνησης (π.χ 9.3)»
     * — i.e. delivery notes / shipping documents (and tax-free / isDeliveryNote
     * invoices). For a plain ΤΠΥ/ΤΙΜ (2.1, 1.1, 11.x …) AADE REJECTS it, so the
     * opt-in itemDescr knob must never emit it there. We only have the document
     * type as a deterministic signal (no isDeliveryNote / tax-free columns on
     * Invoice), so we gate on the 9.x δελτία-διακίνησης types.
     */
    public static function allowsItemDescr(?string $code): bool
    {
        return self::isMovementOnlyType($code);
    }

    /**
     * Movement-only §8.1 types — the whole `9.x` family (9.1 συσχετιζόμενο, 9.2
     * συγκεντρωτικό, 9.3 απλό Δελτίο Αποστολής, and any future 9.x). These carry
     * no revenue and belong to the Digital Delivery-Note flow (DeliveryNoteSubmitter)
     * — they must NEVER appear in a monetary invoice selector or reach the monetary
     * AADE builder (MYD-003). Prefix-based so the builder guard and the picker's SQL
     * `not like '9.%'` stay the SAME rule and cannot drift as the code table grows.
     */
    public static function isMovementOnlyType(?string $code): bool
    {
        return $code !== null && str_starts_with($code, '9.');
    }

    public static function vatExemptionExists(int $code): bool
    {
        return in_array($code, self::VAT_EXEMPTION_CATEGORIES, true);
    }

    public static function withholdingCategoryExists(int $code): bool
    {
        return in_array($code, self::WITHHOLDING_CATEGORIES, true);
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
