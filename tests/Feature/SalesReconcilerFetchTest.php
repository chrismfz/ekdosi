<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\MyData\SalesReconciler;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Network-layer test for the reconciler: a Guzzle MockHandler feeds
 * canned RequestTransmittedDocs XML through firebed so we verify
 * pagination (continuationToken) and the cancellation-folding (inline
 * <cancelledByMark> AND the standalone <cancelledInvoicesDoc> list).
 *
 * No local invoices exist, so every AADE doc lands in `missingLocally`
 * — convenient because each row carries the parsed aadeState we want
 * to assert.
 */
class SalesReconcilerFetchTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Fetch test',
            'slug' => 'fetch-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '800561849',
            'mydata_aade_id_sandbox' => 'TESTUSER',
            'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);
    }

    public function test_paginates_and_folds_cancellations(): void
    {
        $mock = new MockHandler([
            new Response(200, [], $this->pageOne()),
            new Response(200, [], $this->pageTwo()),
        ]);

        $result = (new SalesReconciler($this->tenant, $mock))->reconcile(
            now()->subMonth(),
            now(),
        );

        // Both pages consumed → pagination followed the continuationToken.
        $this->assertSame(0, $mock->count(), 'Both paginated responses should be consumed');

        // 3 unique invoices across the two pages.
        $this->assertSame(3, $result->aadeTotal);
        $this->assertCount(3, $result->missingLocally);

        $byMark = collect($result->missingLocally)->keyBy('mark');

        $this->assertSame('VALID', $byMark['400000000000001']->aadeState);
        // Inline <cancelledByMark> → cancelled
        $this->assertSame('CANCELLED', $byMark['400000000000002']->aadeState);
        // Listed in <cancelledInvoicesDoc> → folded to cancelled
        $this->assertSame('CANCELLED', $byMark['400000000000003']->aadeState);

        // Display fields parsed off the header/summary/counterpart.
        $this->assertSame('Πελάτης Α', $byMark['400000000000001']->counterpartName);
        $this->assertSame(124.00, $byMark['400000000000001']->gross);
    }

    public function test_empty_window_response_does_not_crash(): void
    {
        // AADE returns an empty <invoicesDoc/> container when nothing
        // matches the window — firebed parses that to a scalar, so an
        // unguarded foreach would TypeError. Must yield an empty result.
        $mock = new MockHandler([
            new Response(200, [], <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
    <invoicesDoc/>
</RequestedDoc>
XML),
        ]);

        $result = (new SalesReconciler($this->tenant, $mock))->reconcile(
            now()->subMonth(),
            now(),
        );

        $this->assertSame(0, $result->aadeTotal);
        $this->assertSame(0, $mock->count());
        $this->assertFalse($result->hasDiscrepancies());
    }

    /**
     * REAL AADE sandbox shape (captured 2026-05-28 from a live
     * RequestTransmittedDocs call after filing a dummy ΑΠΥ). Differs from
     * the synthetic fixtures above in ways the live run surfaced:
     *   - root <RequestedDoc> carries icls/ecls/pm namespace prefixes
     *   - QR element is <qrCodeUrl> (not <qrUrl>)
     *   - header has an extra <vatPaymentSuspension>
     *   - issueDate is ISO Y-m-d (the REQUEST uses dd/MM/yyyy; the
     *     RESPONSE uses Y-m-d)
     *   - totalGrossValue uses a '.' decimal separator
     *   - a retail (11.2) invoice has NO <counterpart> → getCounterpart()
     *     is null and must not crash the reader
     */
    public function test_real_aade_populated_retail_invoice_parses(): void
    {
        $mock = new MockHandler([
            new Response(200, [], $this->realRetailResponse()),
        ]);

        $result = (new SalesReconciler($this->tenant, $mock))->reconcile(
            now()->subMonth(),
            now(),
        );

        $this->assertSame(0, $mock->count());
        $this->assertSame(1, $result->aadeTotal);
        $this->assertCount(1, $result->missingLocally);

        $row = $result->missingLocally[0];
        $this->assertSame('400001964394607', $row->mark);
        $this->assertSame('VALID', $row->aadeState);
        $this->assertSame(12.4, $row->gross);          // '.' decimal cast to float
        $this->assertNull($row->counterpartName);      // retail → no counterpart
        $this->assertSame('ΑΠΥ 999001', $row->invcode);
    }

    /**
     * REAL empty-window shape: AADE returns a self-closing <RequestedDoc/>
     * with NO child element at all (the synthetic test above uses
     * <invoicesDoc/> inside). get('invoicesDoc') is then null, not a
     * string — is_iterable(null) is false, so the reader skips cleanly.
     */
    public function test_real_aade_bare_empty_requesteddoc_is_safe(): void
    {
        $mock = new MockHandler([
            new Response(200, [], <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns:icls="https://www.aade.gr/myDATA/incomeClassificaton/v1.0" xmlns:ecls="https://www.aade.gr/myDATA/expensesClassificaton/v1.0" xmlns:pm="https://www.aade.gr/myDATA/paymentMethod/v1.0" xmlns="http://www.aade.gr/myDATA/invoice/v1.0"/>
XML),
        ]);

        $result = (new SalesReconciler($this->tenant, $mock))->reconcile(
            now()->subMonth(),
            now(),
        );

        $this->assertSame(0, $result->aadeTotal);
        $this->assertSame(0, $mock->count());
        $this->assertFalse($result->hasDiscrepancies());
    }

    /**
     * REAL cancellation element shape: a <cancelledInvoice> carries
     * <invoiceMark>, <cancellationMark>, <cancellationDate> and an
     * xsi:nil <r/> reason element. Folded onto the matching invoicesDoc
     * entry → that doc must read as CANCELLED.
     */
    public function test_real_aade_cancellation_shape_folds_to_cancelled(): void
    {
        $mock = new MockHandler([
            new Response(200, [], $this->realRetailResponseWithCancellation()),
        ]);

        $result = (new SalesReconciler($this->tenant, $mock))->reconcile(
            now()->subMonth(),
            now(),
        );

        $this->assertSame(1, $result->aadeTotal);
        $this->assertCount(1, $result->missingLocally);
        $this->assertSame('CANCELLED', $result->missingLocally[0]->aadeState);
    }

    private function realRetailResponse(): string
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

    private function realRetailResponseWithCancellation(): string
    {
        $invoice = $this->realRetailResponse();

        // Inject the real-shape <cancelledInvoicesDoc> (captured live)
        // before the closing tag, referencing the invoice's MARK.
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

    private function pageOne(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
    <continuationToken>
        <nextPartitionKey>PK1</nextPartitionKey>
        <nextRowKey>RK1</nextRowKey>
    </continuationToken>
    <invoicesDoc>
        <invoice>
            <uid>UID1</uid>
            <mark>400000000000001</mark>
            <counterpart>
                <vatNumber>123456789</vatNumber>
                <name>Πελάτης Α</name>
            </counterpart>
            <invoiceHeader>
                <series>TPY</series>
                <aa>1</aa>
                <issueDate>2026-01-10</issueDate>
            </invoiceHeader>
            <invoiceSummary>
                <totalGrossValue>124.00</totalGrossValue>
            </invoiceSummary>
        </invoice>
    </invoicesDoc>
</RequestedDoc>
XML;
    }

    private function pageTwo(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
    <invoicesDoc>
        <invoice>
            <uid>UID2</uid>
            <mark>400000000000002</mark>
            <cancelledByMark>900000000000002</cancelledByMark>
            <invoiceHeader>
                <series>TPY</series>
                <aa>2</aa>
                <issueDate>2026-01-11</issueDate>
            </invoiceHeader>
            <invoiceSummary>
                <totalGrossValue>200.00</totalGrossValue>
            </invoiceSummary>
        </invoice>
        <invoice>
            <uid>UID3</uid>
            <mark>400000000000003</mark>
            <invoiceHeader>
                <series>TPY</series>
                <aa>3</aa>
                <issueDate>2026-01-12</issueDate>
            </invoiceHeader>
            <invoiceSummary>
                <totalGrossValue>50.00</totalGrossValue>
            </invoiceSummary>
        </invoice>
    </invoicesDoc>
    <cancelledInvoicesDoc>
        <cancelledInvoice>
            <invoiceMark>400000000000003</invoiceMark>
            <cancellationMark>900000000000003</cancellationMark>
            <cancellationDate>2026-01-13</cancellationDate>
        </cancelledInvoice>
    </cancelledInvoicesDoc>
</RequestedDoc>
XML;
    }
}
