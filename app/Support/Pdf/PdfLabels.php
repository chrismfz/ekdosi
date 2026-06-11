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
    /** @var array<string, array{0:string,1:string}> slug => [el, en] */
    private const MAP = [
        // Document titles / banners
        'doc_generic' => ['Παραστατικό', 'Document'],
        'quote_title' => ['Προσφορά', 'Quotation'],
        'banner_draft' => ['ΠΡΟΧΕΙΡΟ — ΔΕΝ ΕΧΕΙ ΥΠΟΒΛΗΘΕΙ ΣΤΗ myDATA', 'DRAFT — NOT SUBMITTED TO myDATA'],
        'banner_cancelled' => ['ΑΚΥΡΩΘΕΝ ΠΑΡΑΣΤΑΤΙΚΟ — Δεν έχει νόμιμη ισχύ', 'CANCELLED DOCUMENT — Not legally valid'],
        'banner_credit' => ['ΠΙΣΤΩΤΙΚΟ ΠΑΡΑΣΤΑΤΙΚΟ', 'CREDIT NOTE'],

        // Identity / parties
        'vat_no' => ['ΑΦΜ', 'VAT No'],
        'tax_office' => ['ΔΟΥ', 'Tax office'],
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
        'total_value' => ['Συνολική αξία', 'Total'],
        'total' => ['Σύνολο', 'Total'],
        'withholding' => ['Παρακράτηση φόρου', 'Tax withholding'],
        'fees' => ['Τέλη', 'Fees'],
        'stamp_duty' => ['Χαρτόσημο', 'Stamp duty'],
        'other_taxes' => ['Λοιποί φόροι', 'Other taxes'],
        'deductions' => ['Κρατήσεις', 'Deductions'],
        'payable' => ['Πληρωτέο', 'Payable'],

        // Sections
        'notes' => ['Παρατηρήσεις', 'Notes'],
        'related_docs' => ['Σχετικά παραστατικά', 'Related documents'],
        'doc_status' => ['Κατάσταση παραστατικού', 'Document status'],
        'cancelled_by_credit' => ['Ακυρώθηκε με πιστωτικό', 'Cancelled by credit note'],
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
        return new self(in_array($lang, ['el', 'en', 'both'], true) ? $lang : 'el');
    }

    /**
     * Resolve the effective PDF language: an explicit stored choice wins; else
     * Greek for a GR/empty recipient country, bilingual for a foreign one.
     */
    public static function resolveLanguage(?string $stored, ?string $countryCode): string
    {
        if (in_array($stored, ['el', 'en', 'both'], true)) {
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
