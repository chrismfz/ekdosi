<?php

namespace Tests\Feature\Peppol;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Services\Peppol\PeppolInvoiceDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The provider-independent PEPPOL BIS 3.0 (EN 16931) UBL builder — validates
 * against the library's EN 16931 + PEPPOL rules and carries the right BT values /
 * VAT categories, for both an Estonian and a Greek (mainland myDATA) tenant. The
 * Greek cases pin the EL-vs-GR subtlety: the <Country> code is GR (ISO 3166-1) but
 * the VAT identifier prefix is EL (EN 16931 BR-CO-9).
 */
class PeppolInvoiceDocumentTest extends TestCase
{
    use RefreshDatabase;

    private function eeCompany(): Company
    {
        return Company::create([
            'name' => 'Nixpal OÜ', 'slug' => 'nixpal-ee-'.uniqid(), 'country_code' => 'EE',
            'einvoice_provider' => 'ee-peppol', 'afm' => '101234567',
            'address' => 'Tartu mnt 1', 'city' => 'Tallinn', 'postcode' => '10115',
        ]);
    }

    private function grCompany(): Company
    {
        return Company::create([
            'name' => 'MyIP ΙΚΕ', 'slug' => 'myip-gr-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'afm' => '800561849',
            'address' => 'Λεωφ. Κηφισίας 1', 'city' => 'Αθήνα', 'postcode' => '11523',
        ]);
    }

    private function invoiceWith(Company $company, Customer $customer, array $lines, array $extra = []): Invoice
    {
        $type = InvoiceType::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'ARV'],
            ['name' => 'Arve', 'invcount' => 0],
        );

        $invoice = Invoice::create(array_merge([
            'company_id' => $company->id, 'customer_id' => $customer->id,
            'invoice_type_id' => $type->id,
            'code' => 1001, 'invcode' => 'INV1001', 'issued_at' => now(),
            'local_status' => 'active', 'company_name' => $customer->name,
        ], $extra));

        foreach ($lines as $l) {
            InvoiceLine::create([
                'company_id' => $company->id, 'invoice_id' => $invoice->id,
                'qty' => $l['qty'], 'price_per_item' => $l['price'], 'vat_percent' => $l['vat'],
                'product_descr' => $l['name'],
            ]);
        }

        return $invoice->fresh('lines');
    }

    #[Test]
    public function it_builds_a_valid_domestic_ee_invoice(): void
    {
        $company = $this->eeCompany();
        $customer = Customer::create([
            'company_id' => $company->id, 'type' => 'company', 'name' => 'Eesti Klient OÜ',
            'afm' => '109876543', 'country' => 'EE', 'city' => 'Tartu', 'postcode' => '51004',
        ]);

        $invoice = $this->invoiceWith($company, $customer, [
            ['name' => 'Veebimajutus', 'qty' => 2, 'price' => 100, 'vat' => 22],
        ]);

        $doc = app(PeppolInvoiceDocument::class);

        // Valid against EN 16931 + PEPPOL rules.
        $this->assertNull($doc->validate($invoice), 'expected a valid PEPPOL document');

        $xml = $doc->xml($invoice);
        $this->assertStringContainsString('urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0', $xml);
        $this->assertStringContainsString('INV1001', $xml);
        $this->assertStringContainsString('Nixpal OÜ', $xml);
        $this->assertStringContainsString('Eesti Klient OÜ', $xml);
        $this->assertStringContainsString('EUR', $xml);
        // Standard-rated domestic line → category S, rate 22.
        $this->assertStringContainsString('>S<', $xml);

        $totals = $doc->build($invoice)->getTotals();
        $this->assertEqualsWithDelta(200.0, $totals->netAmount, 0.01);
        $this->assertEqualsWithDelta(44.0, $totals->vatAmount, 0.01);
        $this->assertEqualsWithDelta(244.0, $totals->payableAmount, 0.01);
    }

    #[Test]
    public function gr_invoice_uses_el_vat_prefix_but_gr_country_code(): void
    {
        // Greek tenant → Greek B2B customer, both with a bare 9-digit ΑΦΜ.
        $company = $this->grCompany();
        $customer = Customer::create([
            'company_id' => $company->id, 'type' => 'company', 'name' => 'Πελάτης ΑΕ',
            'afm' => '094512345', 'country' => 'GR', 'address1' => 'Ερμού 5',
            'city' => 'Αθήνα', 'postcode' => '10563',
        ]);

        $invoice = $this->invoiceWith($company, $customer, [
            ['name' => 'Υπηρεσίες φιλοξενίας', 'qty' => 1, 'price' => 100, 'vat' => 24],
        ]);

        $doc = app(PeppolInvoiceDocument::class);
        $this->assertNull($doc->validate($invoice), 'expected a valid PEPPOL document');

        $xml = $doc->xml($invoice);
        // VAT identifiers carry the EL prefix (BR-CO-9), for BOTH seller and buyer…
        $this->assertStringContainsString('EL800561849', $xml, 'seller VAT must use the EL prefix');
        $this->assertStringContainsString('EL094512345', $xml, 'buyer VAT must use the EL prefix');
        // …but the <Country> code is the ISO 3166-1 alpha-2 'GR', never 'EL'.
        $this->assertMatchesRegularExpression('/<cbc:IdentificationCode[^>]*>GR<\/cbc:IdentificationCode>/', $xml);
        $this->assertDoesNotMatchRegularExpression('/<cbc:IdentificationCode[^>]*>EL<\/cbc:IdentificationCode>/', $xml);
        // The old bug: never emit a GR-prefixed VAT number.
        $this->assertStringNotContainsString('GR800561849', $xml, 'VAT number must not be GR-prefixed');
        $this->assertStringNotContainsString('GR094512345', $xml, 'VAT number must not be GR-prefixed');
        // 24% standard-rated domestic → category S.
        $this->assertStringContainsString('>S<', $xml);

        $totals = $doc->build($invoice)->getTotals();
        $this->assertEqualsWithDelta(100.0, $totals->netAmount, 0.01);
        $this->assertEqualsWithDelta(24.0, $totals->vatAmount, 0.01);
        $this->assertEqualsWithDelta(124.0, $totals->payableAmount, 0.01);
    }

    #[Test]
    public function a_mistyped_gr_prefixed_buyer_vat_is_normalised_to_el(): void
    {
        // Legacy/WHMCS data can carry a VIES value verbatim as 'GR…' (invalid — a
        // VAT id is never GR-prefixed). We must still emit the correct EL prefix.
        $company = $this->grCompany();
        $customer = Customer::create([
            'company_id' => $company->id, 'type' => 'company', 'name' => 'Πελάτης ΑΕ',
            'vat_vies' => 'GR094512345', 'country' => 'GR', 'city' => 'Αθήνα', 'postcode' => '10563',
        ]);

        $invoice = $this->invoiceWith($company, $customer, [
            ['name' => 'Υπηρεσίες', 'qty' => 1, 'price' => 100, 'vat' => 24],
        ]);

        $xml = app(PeppolInvoiceDocument::class)->xml($invoice);
        $this->assertStringContainsString('EL094512345', $xml, 'a GR-prefixed VAT must be normalised to EL');
        $this->assertStringNotContainsString('GR094512345', $xml, 'the invalid GR-prefixed VAT must not survive');
    }

    #[Test]
    public function gr_retail_invoice_without_buyer_vat_is_valid(): void
    {
        // Retail (ιδιώτης) buyer: no VAT id, still a valid EN 16931 document.
        $company = $this->grCompany();
        $customer = Customer::create([
            'company_id' => $company->id, 'type' => 'person', 'name' => 'Ιδιώτης Πελάτης',
            'country' => 'GR', 'city' => 'Θεσσαλονίκη', 'postcode' => '54622',
        ]);

        $invoice = $this->invoiceWith($company, $customer, [
            ['name' => 'Domain renewal', 'qty' => 1, 'price' => 12, 'vat' => 24],
        ]);

        $doc = app(PeppolInvoiceDocument::class);
        $this->assertNull($doc->validate($invoice), 'retail GR document should be valid');

        $xml = $doc->xml($invoice);
        $this->assertStringContainsString('EL800561849', $xml, 'seller VAT still EL-prefixed');
        $this->assertStringContainsString('>S<', $xml);
    }

    #[Test]
    public function intra_community_zero_rate_maps_to_category_k(): void
    {
        $company = $this->eeCompany();
        // A German B2B customer with a VAT id, 0% line → intra-community supply.
        $customer = Customer::create([
            'company_id' => $company->id, 'type' => 'company', 'name' => 'Deutsche GmbH',
            'vat_vies' => 'DE123456789', 'country' => 'DE', 'city' => 'Berlin', 'postcode' => '10115',
        ]);

        $invoice = $this->invoiceWith($company, $customer, [
            ['name' => 'Consulting', 'qty' => 1, 'price' => 500, 'vat' => 0],
        ]);

        $doc = app(PeppolInvoiceDocument::class);
        $this->assertNull($doc->validate($invoice));

        $xml = $doc->xml($invoice);
        $this->assertStringContainsString('>K<', $xml);            // intra-community category
        $this->assertStringContainsString('Intra-community supply', $xml);
    }

    #[Test]
    public function multi_rate_invoice_with_odd_cents_stays_consistent_and_2dp(): void
    {
        $company = $this->eeCompany();
        $customer = Customer::create([
            'company_id' => $company->id, 'type' => 'company', 'name' => 'Eesti Klient OÜ',
            'afm' => '109876543', 'country' => 'EE', 'city' => 'Tartu', 'postcode' => '51004',
        ]);

        // Two VAT rates + a qty/price that yields an odd-cent unit net (10/3).
        $invoice = $this->invoiceWith($company, $customer, [
            ['name' => 'Majutus', 'qty' => 3, 'price' => 3.3333, 'vat' => 22],
            ['name' => 'Raamat', 'qty' => 1, 'price' => 50, 'vat' => 0],
        ]);

        $doc = app(PeppolInvoiceDocument::class);
        $this->assertNull($doc->validate($invoice), 'multi-rate doc should pass');

        // Monetary amounts serialise at 2dp (no 8dp tails) — BR-DEC-*.
        $xml = $doc->xml($invoice);
        $this->assertMatchesRegularExpression('/<cbc:TaxInclusiveAmount[^>]*>\d+\.\d{2}<\/cbc:TaxInclusiveAmount>/', $xml);
        $this->assertStringNotContainsString('.000', $xml); // no 8dp monetary tails

        // Two VAT subtotals present (22% standard + 0% zero-rated).
        $this->assertSame(2, substr_count($xml, '<cac:TaxSubtotal>'));
    }

    #[Test]
    public function header_discount_is_folded_into_line_prices(): void
    {
        $company = $this->eeCompany();
        $customer = Customer::create([
            'company_id' => $company->id, 'type' => 'company', 'name' => 'Eesti Klient OÜ',
            'afm' => '109876543', 'country' => 'EE', 'city' => 'Tartu', 'postcode' => '51004',
        ]);

        $invoice = $this->invoiceWith($company, $customer, [
            ['name' => 'Service', 'qty' => 1, 'price' => 100, 'vat' => 22],
        ], ['header_discount_percent' => 10]);

        $totals = app(PeppolInvoiceDocument::class)->build($invoice)->getTotals();
        // 100 − 10% = 90 net, 22% VAT = 19.80, payable 109.80.
        $this->assertEqualsWithDelta(90.0, $totals->netAmount, 0.01);
        $this->assertEqualsWithDelta(109.80, $totals->payableAmount, 0.01);
    }
}
