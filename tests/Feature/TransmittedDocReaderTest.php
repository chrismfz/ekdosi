<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\MyData\TransmittedDocReader;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Line-level companion to SalesReconcilerFetchTest: feeds the reader the
 * real captured RequestTransmittedDocs shape (with <invoiceDetails>) and
 * asserts the flattened MarkDetail array the detail page renders — header,
 * per-line values, totals, and the folded cancellation state.
 */
class TransmittedDocReaderTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Reader test',
            'slug' => 'reader-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '800561849',
            'mydata_aade_id_sandbox' => 'TESTUSER',
            'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);
    }

    public function test_flattens_a_full_document_with_lines(): void
    {
        $mock = new MockHandler([
            new Response(200, [], $this->retailResponse()),
        ]);

        $detail = (new TransmittedDocReader($this->tenant, $mock))->fetchDetailByMark(
            '400001964394607',
            now()->subMonth(),
            now(),
        );

        $this->assertNotNull($detail);
        $this->assertSame('400001964394607', $detail['mark']);
        $this->assertSame('11.2', $detail['invoiceType']);
        $this->assertSame('ΑΠΥ', $detail['invoiceTypeLabel']);
        $this->assertSame('ΑΠΥ 999001', $detail['invcode']);
        $this->assertSame('VALID', $detail['state']);
        $this->assertSame('aade', $detail['source']);

        // Totals (string XML → explicit float).
        $this->assertSame(10.0, $detail['netTotal']);
        $this->assertSame(2.4, $detail['vatTotal']);
        $this->assertSame(12.4, $detail['grossTotal']);

        // One line, parsed off <invoiceDetails>.
        $this->assertCount(1, $detail['lines']);
        $line = $detail['lines'][0];
        $this->assertEquals(1, $line['lineNumber']);
        $this->assertSame(10.0, $line['netValue']);
        $this->assertSame(1, $line['vatCategory']);
        $this->assertSame(2.4, $line['vatAmount']);

        // Issuer is our own ΑΦΜ → outbound; the income classification is
        // surfaced as the line's "what is this" signal.
        $this->assertSame('outbound', $detail['direction']);
        $this->assertSame('E3_561_003', $line['classifications'][0]['type']);
        $this->assertNotNull($line['classifications'][0]['typeLabel']);
    }

    public function test_inbound_expense_doc_labels_supplier_and_classification(): void
    {
        $mock = new MockHandler([
            new Response(200, [], $this->inboundExpenseResponse()),
        ]);

        $detail = (new TransmittedDocReader($this->tenant, $mock))->fetchDetailByMark(
            '400013690089504',
            now()->subMonth(),
            now(),
        );

        $this->assertNotNull($detail);
        // Counterpart is us, issuer is the foreign supplier → inbound.
        $this->assertSame('inbound', $detail['direction']);
        $this->assertSame('HOSTING CONCEPTS B.V.', $detail['issuerName']);
        $this->assertSame('14.3', $detail['invoiceType']);
        // Expense classification surfaced on the line.
        $this->assertSame('E3_585_010', $detail['lines'][0]['classifications'][0]['type']);
    }

    public function test_inbound_retail_without_issuer_is_marked_undisclosed(): void
    {
        $mock = new MockHandler([
            new Response(200, [], $this->inboundRetailResponse()),
        ]);

        $detail = (new TransmittedDocReader($this->tenant, $mock))->fetchDetailByMark(
            '400013690400311',
            now()->subMonth(),
            now(),
        );

        $this->assertNotNull($detail);
        // ΑΛΠ 13.1: myDATA carries no issuer — still classified inbound (we're
        // the counterpart), and issuer fields are null so the view can say
        // "δεν δηλώνεται στο myDATA".
        $this->assertSame('inbound', $detail['direction']);
        $this->assertNull($detail['issuerName']);
        $this->assertNull($detail['issuerVat']);
    }

    public function test_folds_cancellation_state(): void
    {
        $mock = new MockHandler([
            new Response(200, [], $this->retailResponseWithCancellation()),
        ]);

        $detail = (new TransmittedDocReader($this->tenant, $mock))->fetchDetailByMark(
            '400001964394607',
            now()->subMonth(),
            now(),
        );

        $this->assertNotNull($detail);
        $this->assertSame('CANCELLED', $detail['state']);
    }

    public function test_returns_null_when_mark_absent_from_window(): void
    {
        $mock = new MockHandler([
            new Response(200, [], $this->retailResponse()),
        ]);

        $detail = (new TransmittedDocReader($this->tenant, $mock))->fetchDetailByMark(
            '999999999999999',
            now()->subMonth(),
            now(),
        );

        $this->assertNull($detail);
    }

    private function retailResponse(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns:icls="https://www.aade.gr/myDATA/incomeClassificaton/v1.0" xmlns:ecls="https://www.aade.gr/myDATA/expensesClassificaton/v1.0" xmlns:pm="https://www.aade.gr/myDATA/paymentMethod/v1.0" xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
  <invoicesDoc>
    <invoice>
      <uid>E50E567A57B4F990B273C17220B0E88DAE243CAA</uid>
      <mark>400001964394607</mark>
      <issuer>
        <vatNumber>800561849</vatNumber>
        <country>GR</country>
        <branch>0</branch>
      </issuer>
      <invoiceHeader>
        <series>ΑΠΥ</series>
        <aa>999001</aa>
        <issueDate>2026-05-28</issueDate>
        <invoiceType>11.2</invoiceType>
        <vatPaymentSuspension>false</vatPaymentSuspension>
        <currency>EUR</currency>
      </invoiceHeader>
      <paymentMethods>
        <paymentMethodDetails>
          <type>3</type>
          <amount>12.4</amount>
        </paymentMethodDetails>
      </paymentMethods>
      <invoiceDetails>
        <lineNumber>1</lineNumber>
        <netValue>10</netValue>
        <vatCategory>1</vatCategory>
        <vatAmount>2.4</vatAmount>
        <incomeClassification>
          <icls:classificationType>E3_561_003</icls:classificationType>
          <icls:classificationCategory>category1_3</icls:classificationCategory>
          <icls:amount>10.0</icls:amount>
        </incomeClassification>
      </invoiceDetails>
      <invoiceSummary>
        <totalNetValue>10</totalNetValue>
        <totalVatAmount>2.4</totalVatAmount>
        <totalWithheldAmount>0</totalWithheldAmount>
        <totalFeesAmount>0</totalFeesAmount>
        <totalStampDutyAmount>0</totalStampDutyAmount>
        <totalOtherTaxesAmount>0</totalOtherTaxesAmount>
        <totalDeductionsAmount>0</totalDeductionsAmount>
        <totalGrossValue>12.4</totalGrossValue>
        <incomeClassification>
          <icls:classificationType>E3_561_003</icls:classificationType>
          <icls:classificationCategory>category1_3</icls:classificationCategory>
          <icls:amount>10.0</icls:amount>
        </incomeClassification>
      </invoiceSummary>
      <qrCodeUrl>https://mydataapidev.aade.gr/TimologioQR/QRInfo?q=EXAMPLE</qrCodeUrl>
    </invoice>
  </invoicesDoc>
</RequestedDoc>
XML;
    }

    /** Intra-community service receipt (14.3) — issuer is a foreign supplier, we are the counterpart. */
    private function inboundExpenseResponse(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns:icls="https://www.aade.gr/myDATA/incomeClassificaton/v1.0" xmlns:ecls="https://www.aade.gr/myDATA/expensesClassificaton/v1.0" xmlns:pm="https://www.aade.gr/myDATA/paymentMethod/v1.0" xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
  <invoicesDoc>
    <invoice>
      <uid>4BC6A5494C7F4E1C453BCB6958E98DD58F74D097</uid>
      <mark>400013690089504</mark>
      <issuer>
        <vatNumber>817618569B01</vatNumber>
        <country>NL</country>
        <branch>0</branch>
        <name>HOSTING CONCEPTS B.V.</name>
      </issuer>
      <counterpart>
        <vatNumber>800561849</vatNumber>
        <country>GR</country>
        <branch>0</branch>
      </counterpart>
      <invoiceHeader>
        <series>0</series>
        <aa>1779732</aa>
        <issueDate>2026-04-30</issueDate>
        <invoiceType>14.3</invoiceType>
        <vatPaymentSuspension>false</vatPaymentSuspension>
        <currency>EUR</currency>
      </invoiceHeader>
      <invoiceDetails>
        <lineNumber>1</lineNumber>
        <netValue>337.71</netValue>
        <vatCategory>1</vatCategory>
        <vatAmount>81.05</vatAmount>
        <expensesClassification>
          <ecls:classificationType>E3_585_010</ecls:classificationType>
          <ecls:classificationCategory>category2_4</ecls:classificationCategory>
          <ecls:amount>337.71</ecls:amount>
        </expensesClassification>
      </invoiceDetails>
      <invoiceSummary>
        <totalNetValue>337.71</totalNetValue>
        <totalVatAmount>81.05</totalVatAmount>
        <totalWithheldAmount>0</totalWithheldAmount>
        <totalFeesAmount>0</totalFeesAmount>
        <totalStampDutyAmount>0</totalStampDutyAmount>
        <totalOtherTaxesAmount>0</totalOtherTaxesAmount>
        <totalDeductionsAmount>0</totalDeductionsAmount>
        <totalGrossValue>418.76</totalGrossValue>
      </invoiceSummary>
    </invoice>
  </invoicesDoc>
</RequestedDoc>
XML;
    }

    /** Retail expense (ΑΛΠ 13.1) — myDATA returns NO <issuer> at all. */
    private function inboundRetailResponse(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns:icls="https://www.aade.gr/myDATA/incomeClassificaton/v1.0" xmlns:ecls="https://www.aade.gr/myDATA/expensesClassificaton/v1.0" xmlns:pm="https://www.aade.gr/myDATA/paymentMethod/v1.0" xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
  <invoicesDoc>
    <invoice>
      <uid>DD225FAC4992220969AC44ABA72123486D4FA50A</uid>
      <mark>400013690400311</mark>
      <counterpart>
        <vatNumber>800561849</vatNumber>
        <country>GR</country>
        <branch>0</branch>
      </counterpart>
      <invoiceHeader>
        <series>0</series>
        <aa>16055</aa>
        <issueDate>2026-04-01</issueDate>
        <invoiceType>13.1</invoiceType>
        <vatPaymentSuspension>false</vatPaymentSuspension>
        <currency>EUR</currency>
      </invoiceHeader>
      <invoiceDetails>
        <lineNumber>1</lineNumber>
        <netValue>175</netValue>
        <vatCategory>7</vatCategory>
        <vatAmount>0</vatAmount>
        <vatExemptionCategory>27</vatExemptionCategory>
        <expensesClassification>
          <ecls:classificationType>E3_585_016</ecls:classificationType>
          <ecls:classificationCategory>category2_5</ecls:classificationCategory>
          <ecls:amount>175.00</ecls:amount>
        </expensesClassification>
      </invoiceDetails>
      <invoiceSummary>
        <totalNetValue>175</totalNetValue>
        <totalVatAmount>0</totalVatAmount>
        <totalWithheldAmount>0</totalWithheldAmount>
        <totalFeesAmount>0</totalFeesAmount>
        <totalStampDutyAmount>0</totalStampDutyAmount>
        <totalOtherTaxesAmount>0</totalOtherTaxesAmount>
        <totalDeductionsAmount>0</totalDeductionsAmount>
        <totalGrossValue>175</totalGrossValue>
      </invoiceSummary>
    </invoice>
  </invoicesDoc>
</RequestedDoc>
XML;
    }

    private function retailResponseWithCancellation(): string
    {
        $invoice = $this->retailResponse();

        $cancellation = <<<'XML'
  <cancelledInvoicesDoc>
    <cancelledInvoice>
      <invoiceMark>400001964394607</invoiceMark>
      <cancellationMark>400001964394999</cancellationMark>
      <cancellationDate>2026-05-28</cancellationDate>
      <r xmlns:p7="http://www.w3.org/2001/XMLSchema-instance" p7:nil="true"/>
    </cancelledInvoice>
  </cancelledInvoicesDoc>
</RequestedDoc>
XML;

        return str_replace('</RequestedDoc>', $cancellation, $invoice);
    }
}
