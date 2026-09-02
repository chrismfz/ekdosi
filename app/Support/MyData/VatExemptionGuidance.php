<?php

namespace App\Support\MyData;

/**
 * Operator-facing guidance for the §8.3 VAT-exemption reason (Κατηγορία Αιτίας
 * Εξαίρεσης ΦΠΑ) — the «ποια αιτία, πότε, γιατί» an operator needs WITHOUT reading
 * the VAT Code (ν.5144/2024) or the myDATA spec. Mirrors {@see DeliveryGuidance}:
 * the law is encoded ONCE here; the operator picks a plain-Greek scenario and we
 * map it to the right §8.3 code AND the invoice type it pairs with.
 *
 * WHY this exists (MYD-007): the old model applied ONE tenant-wide 0% reason to
 * every zero-rated line, and the seed hint hard-wired code 16 (άρθρο 45 — a
 * DOMESTIC reverse-charge case) as if it were a generic «intracommunity» reason.
 * That is wrong for the common case: an intra-community SERVICE (e.g. hosting to
 * an EU business) is code 4 (άρθρο 18), intra-community GOODS are code 14 (άρθρο
 * 33), and a third-country goods EXPORT is code 8 (άρθρο 29). Goods and services
 * diverge, and the reason must be chosen per case — not globally.
 *
 * SINGLE HOME: the VatCategory form's 0% helper, onboarding, and the invoice-form
 * auto-suggestion all read `SCENARIOS` / `recommendForType()` here. When a code
 * mapping changes, this is the one file to edit — guarded by VatExemptionGuidanceTest.
 *
 * The §8.3 code numbers are cross-checked against {@see Codes::VAT_EXEMPTION_LABELS}
 * (verbatim from the spec); this file only adds the plain-Greek «when to use it».
 * Confirm the exact code for a borderline case with the accountant — this encodes
 * the common, agreed mappings, not every edge of the VAT Code.
 */
class VatExemptionGuidance
{
    /**
     * «Τι είδους απαλλαγή 0% είναι;» — the operator picks a plain-Greek scenario
     * when creating a 0% VatCategory (or reviewing an invoice). Each maps to the
     * §8.3 reason code AND the myDATA invoice type it pairs with, so the two stay
     * consistent (a type/reason mismatch is the exact MYD-007 defect).
     *
     * @var array<string, array{label: string, exemption_code: int, invoice_types: list<string>, hint: string}>
     */
    public const SCENARIOS = [
        'intra_eu_service' => [
            'label' => 'Ενδοκοινοτική παροχή ΥΠΗΡΕΣΙΑΣ (σε επιχείρηση άλλης χώρας ΕΕ)',
            'exemption_code' => 4, // άρθρο 18 (πρώην άρθρο 14)
            'invoice_types' => ['2.2'],
            'hint' => 'π.χ. hosting/υπηρεσία σε επιχείρηση άλλου κράτους ΕΕ με ισχύον VIES ΑΦΜ. '
                .'Τύπος 2.2 (Ενδοκοινοτική Παροχή Υπηρεσιών), ΦΠΑ 0%, αιτία 4 (άρθρο 18). '
                .'Ο φόρος αποδίδεται από τον λήπτη (reverse charge στη χώρα του). ΟΧΙ 16, ΟΧΙ 14.',
        ],
        'intra_eu_goods' => [
            'label' => 'Ενδοκοινοτική παράδοση ΑΓΑΘΩΝ (σε επιχείρηση άλλης χώρας ΕΕ)',
            'exemption_code' => 14, // άρθρο 33 (πρώην άρθρο 28)
            'invoice_types' => ['1.2'],
            'hint' => 'Πώληση/αποστολή αγαθών σε επιχείρηση άλλου κράτους ΕΕ (ισχύον VIES ΑΦΜ). '
                .'Τύπος 1.2, ΦΠΑ 0%, αιτία 14 (άρθρο 33). Διαφέρει από την υπηρεσία (εκείνη = 4).',
        ],
        'export_goods' => [
            'label' => 'Εξαγωγή ΑΓΑΘΩΝ σε χώρα εκτός ΕΕ (τρίτη χώρα)',
            'exemption_code' => 8, // άρθρο 29 (πρώην άρθρο 24)
            'invoice_types' => ['1.3'],
            'hint' => 'Αγαθά που εξάγονται εκτός ΕΕ. Τύπος 1.3, ΦΠΑ 0%, αιτία 8 (άρθρο 29).',
        ],
        'domestic_reverse_charge' => [
            'label' => 'Εγχώρια αντιστροφή επιβάρυνσης (reverse charge εντός Ελλάδας)',
            'exemption_code' => 16, // άρθρο 45
            'invoice_types' => ['1.1', '2.1'],
            'hint' => '⚠️ ΕΙΔΙΚΕΣ εγχώριες περιπτώσεις (π.χ. συγκεκριμένα αγαθά/υπηρεσίες όπου ο ΛΗΠΤΗΣ '
                .'αποδίδει τον ΦΠΑ). ΔΕΝ είναι το γενικό «ενδοκοινοτικό» — γι'."'".'αυτό ήταν λάθος στο seed. '
                .'Επιβεβαίωσε με λογιστή ότι η συναλλαγή σου εμπίπτει πραγματικά.',
        ],
        'small_business' => [
            'label' => 'Απαλλασσόμενη μικρή επιχείρηση (άρθρο 44)',
            'exemption_code' => 15, // άρθρο 44
            'invoice_types' => ['1.1', '2.1'],
            'hint' => 'Καθεστώς απαλλαγής μικρών επιχειρήσεων. ΦΠΑ 0%, αιτία 15 (άρθρο 44).',
        ],
        // Cross-border B2C digital/telecom/hosting — OSS/IOSS. Distinct from the
        // B2B reverse-charge cases above: here ο ΠΩΛΗΤΗΣ αποδίδει τον ΦΠΑ της χώρας
        // του καταναλωτή μέσω OSS/IOSS, και το ελληνικό παραστατικό βγαίνει 0%.
        'oss_eu_consumer' => [
            'label' => 'Πώληση σε ΙΔΙΩΤΗ άλλης χώρας ΕΕ μέσω OSS (ενωσιακό καθεστώς)',
            'exemption_code' => 30, // άρθρο 57 — OSS ενωσιακό
            'invoice_types' => ['1.1', '2.1'],
            'hint' => '⚠️ Για B2C (ιδιώτη, ΟΧΙ επιχείρηση) σε άλλη χώρα ΕΕ — π.χ. hosting σε ιδιώτη. '
                .'Αποδίδεις τον ΦΠΑ της χώρας του πελάτη μέσω OSS· το ελληνικό παραστατικό 0%, αιτία 30 '
                .'(άρθρο 57). Διαφέρει από το B2B (εκείνο = 4, reverse charge). Θέλει εγγραφή στο OSS.',
        ],
        'oss_non_eu' => [
            'label' => 'Πώληση σε ιδιώτη ΕΕ — μη ενωσιακό καθεστώς OSS',
            'exemption_code' => 29, // άρθρο 56 — OSS μη ενωσιακό
            'invoice_types' => ['1.1', '2.1'],
            'hint' => 'OSS μη ενωσιακό καθεστώς (για μη εγκατεστημένους στην ΕΕ). 0%, αιτία 29 (άρθρο 56). '
                .'Επιβεβαίωσε με λογιστή ότι αυτό είναι το καθεστώς σου.',
        ],
        'ioss' => [
            'label' => 'Εισαγωγή αγαθών χαμηλής αξίας σε ιδιώτη ΕΕ (IOSS)',
            'exemption_code' => 31, // άρθρο 58 — IOSS
            'invoice_types' => ['1.1'],
            'hint' => 'IOSS — αγαθά ≤150€ σε ιδιώτη ΕΕ με απόδοση ΦΠΑ μέσω IOSS. 0%, αιτία 31 (άρθρο 58).',
        ],
        'tax_free' => [
            'label' => 'Λιανική σε ταξιδιώτη εκτός ΕΕ (Tax Free)',
            'exemption_code' => 28, // άρθρο 29 περ. β' (Tax Free)
            'invoice_types' => ['11.1', '11.2'],
            'hint' => 'Πώληση λιανικής σε ταξιδιώτη κάτοικο τρίτης χώρας (Tax Free). 0%, αιτία 28.',
        ],
    ];

    /**
     * Options for a «τι είδους 0%;» picker: key => label. Fed to the VatCategory
     * form's 0% helper so the operator picks a scenario, not a raw §8.3 number.
     *
     * @return array<string, string>
     */
    public static function scenarioOptions(): array
    {
        $out = [];
        foreach (self::SCENARIOS as $key => $s) {
            $out[$key] = $s['label'];
        }

        return $out;
    }

    /** @return array{label: string, exemption_code: int, invoice_types: list<string>, hint: string}|null */
    public static function scenario(string $key): ?array
    {
        return self::SCENARIOS[$key] ?? null;
    }

    /**
     * The recommended §8.3 code for a myDATA invoice TYPE, since the type already
     * encodes the case (2.2 = intra-EU service, 1.2 = intra-EU goods, 1.3 = export
     * goods). Positive-evidence only: returns null for a type whose 0% reason is
     * case-specific (domestic 1.1/2.1, third-country services 2.3) so the caller
     * asks the operator instead of guessing wrong — the MYD-009 «refuse rather than
     * guess» discipline applied to exemptions.
     */
    public static function recommendForType(?string $mydataType): ?int
    {
        return match (trim((string) $mydataType)) {
            '2.2' => 4,   // ενδοκοινοτική υπηρεσία → άρθρο 18
            '1.2' => 14,  // ενδοκοινοτικά αγαθά → άρθρο 33
            '1.3' => 8,   // εξαγωγή αγαθών → άρθρο 29
            default => null, // 1.1/2.1 (domestic), 2.3 (third-country service) → operator decides
        };
    }

    /**
     * Human label for a §8.3 code (verbatim legal citation), for showing next to a
     * recommendation. Delegates to the spec table so there is one source.
     */
    public static function labelForCode(int $code): string
    {
        return Codes::VAT_EXEMPTION_LABELS[$code] ?? ('Κατηγορία '.$code);
    }

    public const INTRO =
        'Το «Άνευ ΦΠΑ 0%» ΔΕΝ αρκεί μόνο του: η ΑΑΔΕ απαιτεί την ΑΙΤΙΑ απαλλαγής (§8.3). '
        .'Η σωστή αιτία εξαρτάται από το ΕΙΔΟΣ της συναλλαγής — δεν υπάρχει ένα γενικό '
        .'«ενδοκοινοτικό». Διάλεξε το σενάριο που ταιριάζει· η υπηρεσία προς ΕΕ (αιτία 4) '
        .'διαφέρει από τα αγαθά προς ΕΕ (14) και από την εξαγωγή (8).';
}
