<?php

namespace Tests\Unit;

use App\Support\MyData\InvoiceTypeClassSuggester;
use PHPUnit\Framework\TestCase;

class InvoiceTypeClassSuggesterTest extends TestCase
{
    public function test_goods_sale_names_map_to_1_1(): void
    {
        $this->assertSame('1.1', InvoiceTypeClassSuggester::suggest('Τιμολόγιο πώλησης')['code']);
        $this->assertSame('1.1', InvoiceTypeClassSuggester::suggest('Τιμολόγιο Δελτίο Αποστολής')['code']);
    }

    public function test_intra_eu_and_third_country_goods_variants(): void
    {
        $this->assertSame('1.2', InvoiceTypeClassSuggester::suggest('Τιμολόγιο Πώλησης / Ενδοκοινοτικές Παραδόσεις')['code']);
        $this->assertSame('1.3', InvoiceTypeClassSuggester::suggest('Τιμολόγιο Πώλησης σε Τρίτες Χώρες')['code']);
    }

    public function test_services_map_to_2_1_and_variants(): void
    {
        $this->assertSame('2.1', InvoiceTypeClassSuggester::suggest('Τιμολόγιο Παροχής υπηρεσιών (Σειρά 2)')['code']);
        $this->assertSame('2.1', InvoiceTypeClassSuggester::suggest('ΤΠΧ Τιμολόγιο Παροχής Υπηρεσιών')['code']);
        $this->assertSame('2.2', InvoiceTypeClassSuggester::suggest('Παροχή υπηρεσιών ενδοκοινοτική')['code']);
    }

    public function test_retail_receipts(): void
    {
        $this->assertSame('11.1', InvoiceTypeClassSuggester::suggest('Απόδειξη λιανικής πώλησης')['code']);
        $this->assertSame('11.2', InvoiceTypeClassSuggester::suggest('Απόδειξη παροχής υπηρεσιών')['code']);
    }

    public function test_credit_and_cancellation_documents(): void
    {
        $this->assertSame('5.1', InvoiceTypeClassSuggester::suggest('Πιστωτικό τιμολόγιο')['code']);
        // Cancellation of a service receipt — credit territory.
        $this->assertSame('5.1', InvoiceTypeClassSuggester::suggest('Ακυρωτικό τιμολόγιο παροχής υπηρεσιών')['code']);
        // is_credit flag forces credit even on a plain name.
        $this->assertSame('5.1', InvoiceTypeClassSuggester::suggest('Κάτι', isCredit: true)['code']);
        // Retail credit note.
        $this->assertSame('11.4', InvoiceTypeClassSuggester::suggest('Πιστωτικό λιανικής')['code']);
    }

    public function test_non_correlated_credit_maps_to_5_2(): void
    {
        $this->assertSame('5.2', InvoiceTypeClassSuggester::suggest('Πιστωτικό Τιμολόγιο / Μη Συσχετιζόμενο')['code']);
        // Plain / correlated credit still defaults to 5.1.
        $this->assertSame('5.1', InvoiceTypeClassSuggester::suggest('Πιστωτικό Τιμολόγιο / Συσχετιζόμενο')['code']);
    }

    public function test_simplified_invoice_maps_to_11_3_not_goods(): void
    {
        // Contains "Τιμολόγιο" but must NOT fall through to 1.1.
        $this->assertSame('11.3', InvoiceTypeClassSuggester::suggest('Απλοποιημένο Τιμολόγιο')['code']);
    }

    public function test_title_of_acquisition_and_self_supply(): void
    {
        $this->assertSame('3.1', InvoiceTypeClassSuggester::suggest('Τίτλος Κτήσης')['code']);
        $this->assertSame('6.1', InvoiceTypeClassSuggester::suggest('Στοιχείο Αυτοπαράδοσης')['code']);
        $this->assertSame('6.2', InvoiceTypeClassSuggester::suggest('Στοιχείο Ιδιοχρησιμοποίησης')['code']);
    }

    public function test_contracts_and_rents_income(): void
    {
        $this->assertSame('7.1', InvoiceTypeClassSuggester::suggest('Συμβόλαιο Έσοδο')['code']);
        $this->assertSame('8.1', InvoiceTypeClassSuggester::suggest('Ενοίκια Έσοδο')['code']);
    }

    public function test_delivery_notes(): void
    {
        $this->assertSame('9.3', InvoiceTypeClassSuggester::suggest('Δελτίο Αποστολής')['code']);
        $this->assertSame('9.2', InvoiceTypeClassSuggester::suggest('Συγκεντρωτικό Δελτίο Αποστολής')['code']);
        // Correlated delivery / receipt notes (συσχετιζόμενο).
        $this->assertSame('9.1', InvoiceTypeClassSuggester::suggest('Δελτίο Αποστολής Συσχετιζόμενο')['code']);
        $this->assertSame('10.2', InvoiceTypeClassSuggester::suggest('Δελτίο Παραλαβής')['code']);
        $this->assertSame('10.1', InvoiceTypeClassSuggester::suggest('Δελτίο Ποσοτικής Παραλαβής Συσχετιζόμενο')['code']);
    }

    public function test_goods_flag_is_carried(): void
    {
        // Goods types (G5/[205] per-line quantity) vs services / delivery.
        $this->assertTrue(InvoiceTypeClassSuggester::suggest('Τιμολόγιο πώλησης')['goods']);        // 1.1
        $this->assertTrue(InvoiceTypeClassSuggester::suggest('Δελτίο Αποστολής')['goods']);          // 9.3
        $this->assertFalse(InvoiceTypeClassSuggester::suggest('Τιμολόγιο Παροχής Υπηρεσιών')['goods']); // 2.1
    }

    public function test_income_chain_is_carried_for_classifiable_types(): void
    {
        // Cross-border services twin → εξωτερικού + υπηρεσίες, matching the seed.
        $s = InvoiceTypeClassSuggester::suggest('Τιμολόγιο Παροχής / Ενδοκοινοτική Παροχή Υπηρεσιών');
        $this->assertSame('2.2', $s['code']);
        $this->assertSame('E3_561_005', $s['income_class']);
        $this->assertSame('category1_3', $s['income_class_category']);

        // Delivery notes carry NO income chain.
        $dn = InvoiceTypeClassSuggester::suggest('Δελτίο Αποστολής');
        $this->assertNull($dn['income_class']);
        $this->assertNull($dn['income_class_category']);
    }

    public function test_unknown_name_returns_null(): void
    {
        $this->assertNull(InvoiceTypeClassSuggester::suggest('INV'));
        $this->assertNull(InvoiceTypeClassSuggester::suggest('Κάτι άσχετο'));
    }

    public function test_label_is_included(): void
    {
        $s = InvoiceTypeClassSuggester::suggest('Τιμολόγιο πώλησης');
        $this->assertSame('Τιμολόγιο Πώλησης', $s['label']);
    }
}
