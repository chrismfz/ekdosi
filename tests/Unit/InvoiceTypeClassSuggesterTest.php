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

    public function test_delivery_notes(): void
    {
        $this->assertSame('9.3', InvoiceTypeClassSuggester::suggest('Δελτίο Αποστολής')['code']);
        $this->assertSame('9.2', InvoiceTypeClassSuggester::suggest('Συγκεντρωτικό Δελτίο Αποστολής')['code']);
        $this->assertSame('10.2', InvoiceTypeClassSuggester::suggest('Δελτίο Παραλαβής')['code']);
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
