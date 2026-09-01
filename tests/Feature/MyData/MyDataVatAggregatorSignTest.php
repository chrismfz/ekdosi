<?php

namespace Tests\Feature\MyData;

use App\Models\Company;
use App\Services\MyData\MyDataVatAggregator;
use App\Support\MyData\Codes;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MYD-015: a POS return receipt (type 8.5) must REDUCE the myDATA VAT picture,
 * not inflate it. A 100€ collection (8.4) followed by a 40€ return (8.5) must
 * contribute net 60 — never 140.
 */
class MyDataVatAggregatorSignTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'Sign test',
            'slug' => 'sign-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '800561849',
            'mydata_aade_id_sandbox' => 'TESTUSER',
            'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);
    }

    public function test_pos_return_8_5_reduces_output_totals(): void
    {
        $mock = new MockHandler([
            new Response(200, [], $this->outputWithPosCollectionAndReturn()),
            new Response(200, [], $this->emptyDoc()),   // input side (RequestDocs)
        ]);

        $picture = (new MyDataVatAggregator($this->tenant(), $mock))->forPeriod(
            now()->subMonth(),
            now(),
        );

        // 100 (8.4 collection) − 40 (8.5 return) = 60, never 140.
        $this->assertSame(60.0, $picture->outputNet);
        $this->assertSame(14.4, $picture->outputVat);   // 24 − 9.6
        $this->assertSame(74.4, $picture->outputGross);  // 124 − 49.6
        $this->assertSame(2, $picture->outputCount);
    }

    public function test_document_sign_policy_for_section_8_and_credit_types(): void
    {
        // §8.x direction is explicit (MYD-015), not prefix-derived.
        $this->assertSame(1, Codes::documentSign('8.4'));   // POS collection → income
        $this->assertSame(-1, Codes::documentSign('8.5'));  // POS return → reduces
        $this->assertSame(1, Codes::documentSign('8.6'));   // order slip → +sign (zero-value)

        // Regressions: ordinary credit notes still reduce, sales still add.
        $this->assertSame(-1, Codes::documentSign('5.1'));
        $this->assertSame(-1, Codes::documentSign('11.4'));
        $this->assertSame(1, Codes::documentSign('1.1'));
        $this->assertSame(1, Codes::documentSign('2.1'));

        // Credit-note IDENTITY stays exact: 8.5 reduces the VAT picture but is
        // NOT a πιστωτικό, so isCreditNoteType() must not claim it (MYD-015).
        $this->assertFalse(Codes::isCreditNoteType('8.5'));
        $this->assertTrue(Codes::isCreditNoteType('5.1'));
    }

    private function emptyDoc(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns:icls="https://www.aade.gr/myDATA/incomeClassificaton/v1.0" xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
  <invoicesDoc/>
</RequestedDoc>
XML;
    }

    private function outputWithPosCollectionAndReturn(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns:icls="https://www.aade.gr/myDATA/incomeClassificaton/v1.0" xmlns:ecls="https://www.aade.gr/myDATA/expensesClassificaton/v1.0" xmlns:pm="https://www.aade.gr/myDATA/paymentMethod/v1.0" xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
  <invoicesDoc>
    <invoice>
      <uid>AAA0000000000000000000000000000000000001</uid>
      <mark>400000000000801</mark>
      <issuer>
        <vatNumber>800561849</vatNumber>
        <country>GR</country>
        <branch>0</branch>
      </issuer>
      <invoiceHeader>
        <series>POS</series>
        <aa>1</aa>
        <issueDate>2026-05-28</issueDate>
        <invoiceType>8.4</invoiceType>
        <currency>EUR</currency>
      </invoiceHeader>
      <invoiceDetails>
        <lineNumber>1</lineNumber>
        <netValue>100</netValue>
        <vatCategory>1</vatCategory>
        <vatAmount>24</vatAmount>
        <incomeClassification>
          <icls:classificationType>E3_561_003</icls:classificationType>
          <icls:classificationCategory>category1_3</icls:classificationCategory>
          <icls:amount>100</icls:amount>
        </incomeClassification>
      </invoiceDetails>
      <invoiceSummary>
        <totalNetValue>100</totalNetValue>
        <totalVatAmount>24</totalVatAmount>
        <totalWithheldAmount>0</totalWithheldAmount>
        <totalFeesAmount>0</totalFeesAmount>
        <totalStampDutyAmount>0</totalStampDutyAmount>
        <totalOtherTaxesAmount>0</totalOtherTaxesAmount>
        <totalDeductionsAmount>0</totalDeductionsAmount>
        <totalGrossValue>124</totalGrossValue>
        <incomeClassification>
          <icls:classificationType>E3_561_003</icls:classificationType>
          <icls:classificationCategory>category1_3</icls:classificationCategory>
          <icls:amount>100</icls:amount>
        </incomeClassification>
      </invoiceSummary>
    </invoice>
    <invoice>
      <uid>AAA0000000000000000000000000000000000002</uid>
      <mark>400000000000802</mark>
      <issuer>
        <vatNumber>800561849</vatNumber>
        <country>GR</country>
        <branch>0</branch>
      </issuer>
      <invoiceHeader>
        <series>POS</series>
        <aa>2</aa>
        <issueDate>2026-05-28</issueDate>
        <invoiceType>8.5</invoiceType>
        <currency>EUR</currency>
      </invoiceHeader>
      <invoiceDetails>
        <lineNumber>1</lineNumber>
        <netValue>40</netValue>
        <vatCategory>1</vatCategory>
        <vatAmount>9.6</vatAmount>
        <incomeClassification>
          <icls:classificationType>E3_561_003</icls:classificationType>
          <icls:classificationCategory>category1_3</icls:classificationCategory>
          <icls:amount>40</icls:amount>
        </incomeClassification>
      </invoiceDetails>
      <invoiceSummary>
        <totalNetValue>40</totalNetValue>
        <totalVatAmount>9.6</totalVatAmount>
        <totalWithheldAmount>0</totalWithheldAmount>
        <totalFeesAmount>0</totalFeesAmount>
        <totalStampDutyAmount>0</totalStampDutyAmount>
        <totalOtherTaxesAmount>0</totalOtherTaxesAmount>
        <totalDeductionsAmount>0</totalDeductionsAmount>
        <totalGrossValue>49.6</totalGrossValue>
        <incomeClassification>
          <icls:classificationType>E3_561_003</icls:classificationType>
          <icls:classificationCategory>category1_3</icls:classificationCategory>
          <icls:amount>40</icls:amount>
        </incomeClassification>
      </invoiceSummary>
    </invoice>
  </invoicesDoc>
</RequestedDoc>
XML;
    }
}
