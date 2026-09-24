<?php

namespace App\Support\Pdf;

/**
 * PDF label dictionary (GR/EN/bilingual) for the invoice + quote templates.
 *
 * A document carries a `language` choice ('el'|'en'|'both'); when null it's
 * resolved from the recipient's country (GR → Greek, foreign → bilingual, the
 * safe cross-border default: Greek satisfies the AADE-facing copy, English the
 * foreign reader). The instance is invokable so a Blade does `{{ $L('net') }}`
 * or `@gup($L('description'))` (the @gup directive still uppercases the result).
 *
 * 'both' renders «EL / EN». Unknown slugs fall back to the slug itself (visible
 * in dev, never fatal).
 */
class PdfLabels
{
    /** The languages a document may be rendered in — the single whitelist. */
    public const LANGUAGES = ['el', 'en', 'both'];

    /** @var array<string, array{0:string,1:string}> slug => [el, en] */
    private const MAP = [
        // Document titles / banners
        'doc_generic' => ['Παραστατικό', 'Document'],
        'quote_title' => ['Προσφορά', 'Quotation'],
        // DOC-3: draft wording is provider-agnostic — a draft is «not issued»,
        // full stop. myDATA is irrelevant for none/ee-peppol/Off tenants, and
        // for GR tenants the not-yet-filed state has its own banner below.
        'banner_draft' => ['ΠΡΟΧΕΙΡΟ — ΔΕΝ ΕΧΕΙ ΕΚΔΟΘΕΙ', 'DRAFT — NOT ISSUED'],
        'banner_proforma' => [
            'ΠΡΟΤΙΜΟΛΟΓΙΟ — ΔΕΝ ΑΠΟΤΕΛΕΙ ΦΟΡΟΛΟΓΙΚΟ ΠΑΡΑΣΤΑΤΙΚΟ',
            'PROFORMA — NOT A TAX DOCUMENT',
        ],
        'banner_informal' => ['ΑΤΥΠΟ — ΔΕΝ ΑΠΟΤΕΛΕΙ ΦΟΡΟΛΟΓΙΚΟ ΣΤΟΙΧΕΙΟ', 'INFORMAL — NOT A TAX DOCUMENT'],
        'banner_cancelled' => ['ΑΚΥΡΩΘΕΝ ΠΑΡΑΣΤΑΤΙΚΟ — Δεν έχει νόμιμη ισχύ', 'CANCELLED DOCUMENT — Not legally valid'],
        'banner_cancel_pending_mydata' => ['Εκκρεμεί ακύρωση στο myDATA', 'myDATA cancellation pending'],
        'banner_pending_mydata' => ['ΕΚΔΟΘΕΝ — ΕΚΚΡΕΜΕΙ ΥΠΟΒΟΛΗ ΣΤΟ myDATA', 'ISSUED — myDATA SUBMISSION PENDING'],
        'banner_credit' => ['ΠΙΣΤΩΤΙΚΟ ΠΑΡΑΣΤΑΤΙΚΟ', 'CREDIT NOTE'],

        // Identity / parties
        'vat_no' => ['ΑΦΜ', 'VAT No'],
        'tax_office' => ['ΔΟΥ', 'Tax office'],
        'gemi' => ['ΓΕΜΗ', 'GEMI No'],
        'activity' => ['Δραστηριότητα', 'Activity'],
        'phone' => ['Τηλ', 'Tel'],
        'customer_details' => ['Στοιχεία Πελάτη', 'Customer'],
        'to' => ['Προς', 'To'],

        // Meta / terms
        'date' => ['Ημ/νία', 'Date'],
        'valid_until' => ['Ισχύει έως', 'Valid until'],
        'delivery_date' => ['Παράδοση', 'Delivery'],
        'doc_terms' => ['Όροι Παραστατικού', 'Document terms'],
        'payment_method' => ['Τρόπος πληρωμής', 'Payment method'],
        'deposit_account' => ['Λογαριασμός κατάθεσης', 'Deposit account'],
        'payment_accounts' => ['Λογαριασμοί πληρωμής', 'Payment accounts'],
        'total_quantity' => ['Συνολική ποσότητα', 'Total quantity'],
        'shipping_method' => ['Τρόπος αποστολής', 'Shipping method'],
        'movement_purpose' => ['Σκοπός διακίνησης', 'Movement purpose'],
        'mydata_type' => ['myDATA τύπος', 'myDATA type'],
        'status' => ['Κατάσταση', 'Status'],
        'certified' => ['Πιστοποιημένο', 'Certified'],

        // Line table headers
        'description' => ['Περιγραφή', 'Description'],
        'unit' => ['ΜΜ', 'Unit'],
        'quantity' => ['Ποσότητα', 'Quantity'],
        'quantity_short' => ['Ποσότ.', 'Qty'],
        'unit_price' => ['Τιμή μον.', 'Unit price'],
        'discount_pct' => ['Έκπτ.%', 'Disc.%'],
        'vat_pct' => ['ΦΠΑ%', 'VAT%'],
        'net' => ['Καθαρή', 'Net'],
        'gross_incl_vat' => ['Με ΦΠΑ', 'Incl. VAT'],
        'amount' => ['Αξία', 'Amount'],
        'no_lines' => ['Καμία γραμμή', 'No lines'],

        // Totals
        'vat' => ['ΦΠΑ', 'VAT'],
        'on_net' => ['επί καθ.', 'on net'],
        'net_value' => ['Καθαρή αξία', 'Net value'],
        'total_vat' => ['Σύνολο ΦΠΑ', 'Total VAT'],
        'header_discount' => ['Έκπτωση παραστατικού', 'Document discount'],
        'applied' => ['εφαρμοσμένη', 'applied'],
        // DOC-1: the exemption REASON itself stays the Greek legal citation
        // verbatim (Codes::VAT_EXEMPTION_LABELS — a legal reference is not
        // translated); only this prefix label localizes.
        'vat_exemption' => ['Απαλλαγή ΦΠΑ', 'VAT exemption'],
        'total_value' => ['Συνολική αξία', 'Total'],
        'total' => ['Σύνολο', 'Total'],
        'withholding' => ['Παρακράτηση φόρου', 'Tax withholding'],
        'fees' => ['Τέλη', 'Fees'],
        'stamp_duty' => ['Ψηφιακό Τέλος Συναλλαγής', 'Digital transaction fee'],
        'other_taxes' => ['Λοιποί φόροι', 'Other taxes'],
        'deductions' => ['Κρατήσεις', 'Deductions'],
        'payable' => ['Πληρωτέο', 'Payable'],

        // Customer running-balance block («ΝΕΟ ΥΠΟΛΟΙΠΟ»)
        'customer_balance' => ['Υπόλοιπο πελάτη', 'Customer balance'],
        'previous_balance' => ['Προηγούμενο υπόλοιπο', 'Previous balance'],
        'this_document' => ['Αυτό το παραστατικό', 'This document'],
        'new_balance' => ['Νέο υπόλοιπο', 'New balance'],

        // Sections
        'notes' => ['Παρατηρήσεις', 'Notes'],
        'related_docs' => ['Σχετικά παραστατικά', 'Related documents'],
        'doc_status' => ['Κατάσταση παραστατικού', 'Document status'],
        'cancelled_by_credit' => ['Ακυρώθηκε με πιστωτικό', 'Cancelled by credit note'],
        // PROV-019: a full credit that is still an UN-FILED draft has not legally
        // reversed the original at AADE — say so on the printed document rather than
        // claim a cancellation that hasn't happened.
        'reduced_by_draft_credit' => [
            'Μειώθηκε με πρόχειρο πιστωτικό (δεν υποβλήθηκε στην ΑΑΔΕ)',
            'Reduced by draft credit note (not filed at AADE)',
        ],
        'reduced_credited_with' => ['Μειώθηκε (πρόχειρο πιστωτικό) με', 'Reduced (draft credit) by'],
        'credit_reverses' => ['Πιστωτικό — αντιστρέφει το παραστατικό', 'Credit note — reverses document'],
        'credit_note_purpose' => [
            'Αυτό το πιστωτικό εκδόθηκε για να ακυρώσει/διορθώσει το παραπάνω παραστατικό.',
            'This credit note was issued to cancel/correct the document above.',
        ],
        'cancelled_credited_with' => ['Ακυρώθηκε / πιστώθηκε με', 'Cancelled / credited by'],
        'credited_partially_with' => ['Πιστώθηκε (μερικώς) με', 'Credited (partially) by'],
        'original_valid_note' => [
            'Το αρχικό παραμένει VALID στην ΑΑΔΕ· το/τα πιστωτικό/ά το μηδενίζει/ουν λογιστικά.',
            'The original stays VALID at AADE; the credit note(s) offset it for accounting.',
        ],
        'delivery_notes' => ['Δελτία αποστολής', 'Delivery notes'],
        // DOC-5: standalone ΜΑΡΚ label, used when the mark prints without a QR
        // (e.g. an ETL-imported legacy invoice: VALID + mydata_mark, no url).
        'mark_label' => ['ΜΑΡΚ', 'MARK'],

        // Provider (ΥΠΑΗΕΣ) evidence block — PROV-003 / A.1112/2025.
        'provider_issued' => [
            'Εκδόθηκε μέσω παρόχου ηλεκτρονικής τιμολόγησης (ΥΠΑΗΕΣ)',
            'Issued via an accredited e-invoicing provider (ΥΠΑΗΕΣ)',
        ],
        // Labels mirror the provider's OWN official document vocabulary
        // («Αριθμός Αδειοδότησης», «Αναγνωριστικό», «Υπογραφή») so an operator
        // cross-referencing our representation against the provider's copy reads
        // the same words for the same fields.
        'provider_name' => ['Πάροχος', 'Provider'],
        'provider_licence' => ['Αριθμός Αδειοδότησης', 'ΥΠΑΗΕΣ licence no.'],
        'provider_uid' => ['Αναγνωριστικό (UID)', 'Document UID'],
        'provider_auth' => ['Υπογραφή', 'Authentication code'],

        // ── Customer statement (Καρτέλα) — CustomerStatementPdfRenderer ──
        'statement_title' => ['Καρτέλα Πελάτη', 'Account statement'],
        'statement_short' => ['Καρτέλα', 'Statement'],
        'issue_date' => ['Ημ/νία έκδοσης', 'Issue date'],
        'customer' => ['Πελάτης', 'Customer'],
        'balance' => ['Υπόλοιπο', 'Balance'],
        'oldest_unpaid' => ['Παλαιότερο ανεξόφλητο', 'Oldest unpaid'],
        'days_short' => ['ημ.', 'days'],
        'yearly_breakdown' => ['Ετήσια ανάλυση', 'Yearly breakdown'],
        'year' => ['Έτος', 'Year'],
        'invoices' => ['Τιμολόγια', 'Invoices'],
        'payments' => ['Πληρωμές', 'Payments'],
        'year_end_balance' => ['Υπόλοιπο τέλους έτους', 'Year-end balance'],
        'ledger_movements' => ['Καρτέλα κινήσεων', 'Account activity'],
        'date_full' => ['Ημερομηνία', 'Date'],
        'type' => ['Τύπος', 'Type'],
        'reference' => ['Αναφορά', 'Reference'],
        'debit' => ['Χρέωση', 'Debit'],
        'credit' => ['Πίστωση', 'Credit'],
        'no_movements' => ['Δεν υπάρχουν κινήσεις.', 'No activity.'],

        // ── Payment receipt (Απόδειξη Είσπραξης) — PaymentReceiptRenderer ──
        'receipt_title' => ['ΑΠΟΔΕΙΞΗ ΕΙΣΠΡΑΞΗΣ', 'PAYMENT RECEIPT'],
        'receipt_informal' => [
            'Άτυπο αποδεικτικό — δεν αποτελεί φορολογικό παραστατικό',
            'Informal acknowledgement — not a tax document',
        ],
        'account_ref' => ['Αριθμός αναφοράς', 'Reference number'],
        'channel_method' => ['Κανάλι / τρόπος', 'Channel / method'],
        'transaction_id' => ['Κωδικός συναλλαγής', 'Transaction ID'],
        'concerns' => ['Αφορά', 'Concerns'],
        'amount_col' => ['Ποσό', 'Amount'],
        'receipt_total' => ['Σύνολο είσπραξης', 'Total received'],
        'on_account' => ['Έναντι λογαριασμού', 'On account'],
        'receipt_disclaimer' => [
            'Η παρούσα βεβαιώνει την είσπραξη του ανωτέρω ποσού. Δεν υποκαθιστά το φορολογικό παραστατικό (τιμολόγιο/απόδειξη) που εκδίδεται μέσω myDATA.',
            'This confirms receipt of the above amount. It does not replace the tax document (invoice/receipt) issued via myDATA.',
        ],

        // ── Delivery note (Δελτίο Αποστολής) — DeliveryNotePdf ──
        'delivery_note_title' => ['Δελτίο Αποστολής', 'Delivery note'],
        'banner_draft_dn' => ['ΠΡΟΧΕΙΡΟ — ΜΗ ΔΙΑΒΙΒΑΣΜΕΝΟ ΣΤΗ myDATA', 'DRAFT — NOT SUBMITTED TO myDATA'],
        'banner_cancelled_dn' => ['ΑΚΥΡΩΘΕΝ ΔΕΛΤΙΟ — Δεν έχει νόμιμη ισχύ', 'CANCELLED NOTE — Not legally valid'],
        'dn_mydata_type' => ['Τύπος myDATA', 'myDATA type'],
        'issuer' => ['Εκδότης', 'Issuer'],
        'recipient' => ['Παραλήπτης', 'Recipient'],
        'internal_movement' => ['Ενδοδιακίνηση', 'Internal movement'],
        'internal_movement_desc' => ['Διακίνηση εντός της επιχείρησης', 'Movement within the business'],
        'country' => ['Χώρα', 'Country'],
        'movement_details' => ['Στοιχεία Διακίνησης', 'Movement details'],
        'loading_place' => ['Τόπος φόρτωσης', 'Loading place'],
        'delivery_place' => ['Τόπος παράδοσης', 'Delivery place'],
        'transport_means' => ['Μεταφορικό μέσο', 'Means of transport'],
        'vehicle' => ['Όχημα', 'Vehicle'],
        'carrier_vat' => ['Μεταφορέας (ΑΦΜ)', 'Carrier (VAT No)'],
        'dispatch_time' => ['Ημ/ώρα έναρξης', 'Dispatch time'],
        'line_no' => ['Α/Α', 'No.'],
        'item' => ['Είδος', 'Item'],
        'unit_full' => ['Μονάδα', 'Unit'],
        'mydata_submitted_verify' => [
            'Διαβιβάστηκε στη myDATA — επαληθεύστε σαρώνοντας το QR ή στη διεύθυνση:',
            'Submitted to myDATA — verify by scanning the QR or at:',
        ],
        'draft_no_mydata' => [
            'ΠΡΟΧΕΙΡΟ — δεν έχει διαβιβαστεί στη myDATA (χωρίς ΜΑΡΚ/QR)',
            'DRAFT — not submitted to myDATA (no MARK/QR)',
        ],
        'history' => ['Ιστορικό', 'History'],
        'movement' => ['Διακίνηση', 'Movement'],
        'event' => ['Γεγονός', 'Event'],
        'details' => ['Λεπτομέρειες', 'Details'],
        'from_actor' => ['Από', 'From'],
        'mydata_submissions' => ['Υποβολές myDATA', 'myDATA submissions'],
        'action' => ['Ενέργεια', 'Action'],
        'cancellation' => ['ακύρωση', 'cancellation'],

        // Footer
        'mydata_verify' => [
            'Πιστοποιημένο στη myDATA — επαληθεύστε σαρώνοντας το QR ή στη διεύθυνση:',
            'Certified on myDATA — verify by scanning the QR or at:',
        ],
        'page' => ['Σελίδα', 'Page'],
        'of' => ['από', 'of'],
        'quote_not_tax_doc' => [
            'Η παρούσα προσφορά δεν αποτελεί φορολογικό παραστατικό.',
            'This quotation is not a tax document.',
        ],
    ];

    public function __construct(private readonly string $lang) {}

    public static function for(string $lang): self
    {
        return new self(in_array($lang, self::LANGUAGES, true) ? $lang : 'el');
    }

    /**
     * Resolve the effective PDF language: an explicit stored choice wins; else
     * Greek for a GR/empty recipient country, bilingual for a foreign one.
     */
    public static function resolveLanguage(?string $stored, ?string $countryCode): string
    {
        if (in_array($stored, self::LANGUAGES, true)) {
            return $stored;
        }

        $country = strtoupper(trim((string) $countryCode));

        return ($country === '' || $country === 'GR') ? 'el' : 'both';
    }

    public function lang(): string
    {
        return $this->lang;
    }

    public function get(string $slug): string
    {
        [$el, $en] = self::MAP[$slug] ?? [$slug, $slug];

        return match ($this->lang) {
            'en' => $en,
            'both' => $el.' / '.$en,
            default => $el,
        };
    }

    public function __invoke(string $slug): string
    {
        return $this->get($slug);
    }
}
