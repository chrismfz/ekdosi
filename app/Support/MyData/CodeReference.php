<?php

namespace App\Support\MyData;

/**
 * «Οδηγός κωδικών myDATA» — a plain-Greek reference of the AADE §8 code tables an
 * operator meets in the panel (invoice types, income classification, VAT
 * categories, exemption reasons, business-activity policy), each with «τι είναι /
 * πού χρησιμοποιείται». Built so a non-accountant can look up a code («τι είναι το
 * 2.1;») instead of memorising the spec.
 *
 * This is a PRESENTATION layer: the authoritative code values live in {@see Codes}
 * (the §8 tables), {@see ClassificationGuidance} and {@see VatExemptionGuidance};
 * this only pairs them with a usage note. Where a note is missing the official
 * name still shows, so a spec change to Codes surfaces here automatically without
 * a silent gap. Rendered read-only by the MyDataCodeGuide page.
 */
class CodeReference
{
    /**
     * «Πού χρησιμοποιείται» for the invoice types a Greek seller actually ISSUES
     * (income + delivery). Expense/settlement types (13/14/15/16/17) are shown by
     * their official name only — an issuing τιμολογιέρα does not pick them.
     *
     * @var array<string, string>
     */
    public const INVOICE_TYPE_USAGE = [
        '1.1' => 'Πώληση ΑΓΑΘΩΝ σε επιχείρηση εντός Ελλάδας (B2B, με ΑΦΜ).',
        '1.2' => 'Πώληση/αποστολή ΑΓΑΘΩΝ σε επιχείρηση άλλης χώρας ΕΕ (ενδοκοινοτική παράδοση, VIES ΑΦΜ, ΦΠΑ 0% αιτία 14).',
        '1.3' => 'Εξαγωγή ΑΓΑΘΩΝ σε χώρα εκτός ΕΕ (τρίτη χώρα, ΦΠΑ 0% αιτία 8).',
        '2.1' => 'Παροχή ΥΠΗΡΕΣΙΩΝ σε επιχείρηση εντός Ελλάδας (B2B, με ΑΦΜ). Το πιο συνηθισμένο για hosting/υπηρεσίες σε ελληνική εταιρεία.',
        '2.2' => 'Παροχή ΥΠΗΡΕΣΙΩΝ σε επιχείρηση άλλης χώρας ΕΕ (ενδοκοινοτική, VIES ΑΦΜ, ΦΠΑ 0% αιτία 4 — reverse charge στη χώρα του λήπτη).',
        '2.3' => 'Παροχή ΥΠΗΡΕΣΙΩΝ σε λήπτη τρίτης χώρας (εκτός ΕΕ).',
        '5.1' => 'Πιστωτικό ΣΥΣΧΕΤΙΖΟΜΕΝΟ: επιστροφή/έκπτωση που δείχνει στο αρχικό τιμολόγιο (ΜΑΡΚ). Η προεπιλογή για επιστροφές από το «Έκδοση πιστωτικού».',
        '5.2' => 'Πιστωτικό ΜΗ ΣΥΣΧΕΤΙΖΟΜΕΝΟ: δεν δείχνει σε συγκεκριμένο αρχικό (η ΑΑΔΕ ΑΠΑΓΟΡΕΥΕΙ τη συσχέτιση εδώ).',
        '11.1' => 'ΑΛΠ — Απόδειξη Λιανικής Πώλησης ΑΓΑΘΩΝ σε ιδιώτη (χωρίς ΑΦΜ).',
        '11.2' => 'ΑΠΥ — Απόδειξη Παροχής ΥΠΗΡΕΣΙΩΝ σε ιδιώτη (χωρίς ΑΦΜ). Συνηθισμένο για λιανική υπηρεσία.',
        '11.3' => 'Απλοποιημένο τιμολόγιο (μικρής αξίας, ειδικές περιπτώσεις).',
        '11.4' => 'Πιστωτικό στοιχείο λιανικής (επιστροφή σε ΑΛΠ/ΑΠΥ).',
        '11.5' => 'Απόδειξη λιανικής για λογαριασμό τρίτου.',
        '9.1' => 'Δελτίο Αποστολής συσχετιζόμενο με τιμολόγιο (διακίνηση αγαθών).',
        '9.2' => 'Συγκεντρωτικό Δελτίο Αποστολής.',
        '9.3' => 'Δελτίο Αποστολής (σκέτη διακίνηση, χωρίς τιμολόγιο).',
        '1.4' => 'Πώληση ΑΓΑΘΩΝ για λογαριασμό τρίτου (π.χ. αντιπρόσωπος / παρακαταθήκη).',
        '1.5' => 'Εκκαθάριση πωλήσεων που έγιναν για λογαριασμό τρίτου.',
        '1.6' => 'Συμπληρωματικό τιμολόγιο πώλησης αγαθών (διόρθωση/προσθήκη σε προηγούμενο).',
        '2.4' => 'Συμπληρωματικό τιμολόγιο παροχής υπηρεσιών.',
        '3.1' => 'Τίτλος κτήσης: ο εκδότης ΔΕΝ είναι υπόχρεος έκδοσης (π.χ. αμοιβή/αγορά από ιδιώτη ή μη επιτηδευματία).',
        '3.2' => 'Τίτλος κτήσης λόγω ΑΡΝΗΣΗΣ έκδοσης από τον υπόχρεο.',
        '6.1' => 'Στοιχείο αυτοπαράδοσης αγαθών (τεκμαρτό έσοδο — π.χ. δωρεάν διάθεση/ιδιοκατανάλωση).',
        '6.2' => 'Στοιχείο ιδιοχρησιμοποίησης υπηρεσιών.',
        '7.1' => 'Έσοδο από σύμβαση/συμβόλαιο.',
        '8.1' => 'Έσοδο από ενοίκια.',
        '8.2' => 'Τέλος ανθεκτικότητας κλιματικής κρίσης (πρώην τέλος διαμονής — καταλύματα).',
        '8.4' => 'Απόδειξη είσπραξης μέσω POS.',
        '8.5' => 'Απόδειξη επιστροφής μέσω POS.',
        '8.6' => 'Δελτίο παραγγελίας εστίασης.',
        '10.1' => 'Δελτίο ποσοτικής παραλαβής συσχετιζόμενο.',
        '10.2' => 'Δελτίο ποσοτικής παραλαβής μη συσχετιζόμενο.',
    ];

    /**
     * «Τι είναι / πού χρησιμοποιείται» ανά §8.6 income bucket. The E3 code
     * (E3_561_xxx) is channel-driven and stays on the invoice type; only this
     * item-nature bucket varies per product.
     *
     * @var array<string, string>
     */
    public const INCOME_BUCKET_USAGE = [
        'category1_1' => 'Εμπορεύματα: αγαθά που αγοράζεις έτοιμα και ΜΕΤΑΠΩΛΕΙΣ (δεν τα παράγεις). π.χ. μεταπωλητής εξοπλισμού/αδειών.',
        'category1_2' => 'Προϊόντα: αγαθά που ΠΑΡΑΓΕΙΣ ο ίδιος (βιοτεχνία/κατασκευή). Διαφέρει από τα εμπορεύματα (εκείνα = μεταπώληση).',
        'category1_3' => 'Υπηρεσίες: παροχή υπηρεσιών (hosting, συμβουλευτική, εργασία).',
    ];

    /** @return list<array{code:string,title:string,detail:string}> */
    private static function invoiceTypeRows(): array
    {
        $rows = [];
        foreach (Codes::INVOICE_TYPES as $code => $name) {
            $usage = self::INVOICE_TYPE_USAGE[$code] ?? null;
            // Expense/settlement families a seller never issues → say so; anything
            // else without a specific note gets a friendly fallback (never a bare
            // «—» — the page exists to explain, not to shrug).
            $detail = $usage ?? (self::isExpenseOrSettlement($code)
                ? 'Έξοδα/λοιπά — δεν εκδίδεται ως πωλητής (εμφανίζεται σε εισερχόμενα/εγγραφές).'
                : 'Λιγότερο συνηθισμένος τύπος — δες την επίσημη ονομασία δίπλα ή ρώτησε τον λογιστή σου.');
            $rows[] = ['code' => $code, 'title' => $name, 'detail' => $detail];
        }

        return $rows;
    }

    private static function isExpenseOrSettlement(string $code): bool
    {
        $family = explode('.', $code)[0];

        return in_array($family, ['13', '14', '15', '16', '17'], true);
    }

    /** @return list<array{code:string,title:string,detail:string}> */
    private static function vatRows(): array
    {
        $rows = [];
        foreach (Codes::VAT_CATEGORY_LABELS as $code => $label) {
            $rate = Codes::VAT_CATEGORY_RATES[$code] ?? null;
            $detail = $rate === null
                ? 'Χωρίς αριθμητικό συντελεστή.'
                : ('Συντελεστής '.rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.').'%.')
                    .($code === 7 ? ' Απαιτεί αιτία απαλλαγής §8.3.' : '');

            $rows[] = ['code' => (string) $code, 'title' => $label, 'detail' => $detail];
        }

        return $rows;
    }

    /** @return list<array{code:string,title:string,detail:string}> */
    private static function exemptionRows(): array
    {
        // Common-scenario hints keyed by §8.3 code, from VatExemptionGuidance.
        $hintByCode = [];
        foreach (VatExemptionGuidance::SCENARIOS as $s) {
            $hintByCode[$s['exemption_code']] ??= $s['hint'];
        }

        $rows = [];
        foreach (Codes::VAT_EXEMPTION_LABELS as $code => $label) {
            // The title already carries the legal citation (e.g. «άρθρο 31 …»); for
            // codes without a plain-Greek scenario, point the operator at it rather
            // than a bare «—».
            $rows[] = [
                'code' => (string) $code,
                'title' => $label,
                'detail' => $hintByCode[$code]
                    ?? 'Απαλλαγή κατά τη διάταξη που αναφέρεται (δες τίτλο). Επιβεβαίωσε με λογιστή αν ισχύει στη συναλλαγή σου.',
            ];
        }

        return $rows;
    }

    /** @return list<array{code:string,title:string,detail:string}> */
    private static function businessActivityRows(): array
    {
        $rows = [];
        foreach (ClassificationGuidance::POLICIES as $key => $policy) {
            $rows[] = ['code' => $key, 'title' => $policy['label'], 'detail' => $policy['hint']];
        }

        return $rows;
    }

    /** @return list<array{code:string,title:string,detail:string}> */
    private static function incomeBucketRows(): array
    {
        $rows = [];
        foreach (ClassificationGuidance::BUCKET_LABELS as $code => $label) {
            $rows[] = ['code' => $code, 'title' => $label, 'detail' => self::INCOME_BUCKET_USAGE[$code] ?? '—'];
        }

        return $rows;
    }

    /**
     * The full guide, as ordered sections. Each: a title, a one-line intro and the
     * code rows (code · title · «πού χρησιμοποιείται»).
     *
     * @return list<array{key:string,title:string,intro:string,rows:list<array{code:string,title:string,detail:string}>}>
     */
    public static function sections(): array
    {
        return [
            [
                'key' => 'business_activity',
                'title' => 'Είδος δραστηριότητας (κατηγοριοποίηση εσόδων)',
                'intro' => ClassificationGuidance::INTRO,
                'rows' => self::businessActivityRows(),
            ],
            [
                'key' => 'income_buckets',
                'title' => 'Κατηγορίες εσόδων §8.6 (αγαθά / προϊόντα / υπηρεσίες)',
                'intro' => 'Ορίζουν αν μια γραμμή είναι εμπόρευμα, δικό μας προϊόν ή υπηρεσία. Ρυθμίζονται ανά κατηγορία προϊόντος.',
                'rows' => self::incomeBucketRows(),
            ],
            [
                'key' => 'invoice_types',
                'title' => 'Τύποι παραστατικών §8.1',
                'intro' => 'Ο τύπος καθορίζει τι είδους παραστατικό εκδίδεις (πώληση αγαθών, παροχή υπηρεσιών, πιστωτικό, λιανική…) και τη μεταχείρισή του στην ΑΑΔΕ.',
                'rows' => self::invoiceTypeRows(),
            ],
            [
                'key' => 'vat_categories',
                'title' => 'Κατηγορίες ΦΠΑ §8.2',
                'intro' => 'Ο συντελεστής ΦΠΑ κάθε γραμμής. Το 0% (κατηγορία 7) απαιτεί πάντα αιτία απαλλαγής (§8.3).',
                'rows' => self::vatRows(),
            ],
            [
                'key' => 'vat_exemptions',
                'title' => 'Αιτίες απαλλαγής ΦΠΑ §8.3',
                'intro' => VatExemptionGuidance::INTRO,
                'rows' => self::exemptionRows(),
            ],
        ];
    }
}
