<?php

namespace Tests\Feature\MyData;

use App\Models\Company;
use App\Services\MyData\MyDataVatAggregator;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard "Εικόνα από myDATA — ΦΠΑ" must count only real sales as Έσοδα.
 * RequestTransmittedDocs also returns self-declared μισθοδοσία (17.x) /
 * ενδοκοινοτικά (14.x) / ΑΛΠ (13.x); the aggregator filters those out of the
 * output (income) sum and breaks them out into `breakdown` instead.
 */
class MyDataVatAggregatorTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'Agg',
            'slug' => 'agg-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '800561849',
            'mydata_aade_id_sandbox' => 'U',
            'mydata_subscription_key_sandbox' => 'K',
        ]);
    }

    /** @param list<array{mark:string,type:string,net:string,vat:string,gross:string}> $invoices */
    private function docXml(array $invoices): string
    {
        $items = '';
        foreach ($invoices as $i) {
            $items .= <<<XML
    <invoice>
      <mark>{$i['mark']}</mark>
      <invoiceHeader><series>0</series><aa>1</aa><issueDate>2026-04-10</issueDate><invoiceType>{$i['type']}</invoiceType><currency>EUR</currency></invoiceHeader>
      <invoiceSummary><totalNetValue>{$i['net']}</totalNetValue><totalVatAmount>{$i['vat']}</totalVatAmount><totalGrossValue>{$i['gross']}</totalGrossValue></invoiceSummary>
    </invoice>
XML;
        }

        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<RequestedDoc xmlns:icls="https://www.aade.gr/myDATA/incomeClassificaton/v1.0" xmlns:ecls="https://www.aade.gr/myDATA/expensesClassificaton/v1.0" xmlns="http://www.aade.gr/myDATA/invoice/v1.0">'
            ."<invoicesDoc>{$items}</invoicesDoc></RequestedDoc>";
    }

    public function test_income_is_isolated_and_self_declared_docs_are_broken_out(): void
    {
        // Transmitted: 1 real sale (1.1), 1 payroll (17.1), 1 intra-community (14.3).
        $transmitted = $this->docXml([
            ['mark' => '1', 'type' => '1.1', 'net' => '100', 'vat' => '24', 'gross' => '124'],
            ['mark' => '2', 'type' => '17.1', 'net' => '5000', 'vat' => '0', 'gross' => '5000'],
            ['mark' => '3', 'type' => '14.3', 'net' => '300', 'vat' => '72', 'gross' => '372'],
        ]);
        // Inbound expenses (RequestDocs).
        $docs = $this->docXml([
            ['mark' => '9', 'type' => '13.1', 'net' => '50', 'vat' => '12', 'gross' => '62'],
        ]);

        $mock = new MockHandler([
            new Response(200, [], $transmitted),  // RequestTransmittedDocs (output)
            new Response(200, [], $docs),          // RequestDocs (input)
        ]);

        $picture = (new MyDataVatAggregator($this->tenant(), $mock))
            ->forPeriod(now()->subMonth(), now());

        // Έσοδα = ONLY the real sale — payroll & intra-community excluded.
        $this->assertSame(100.0, $picture->outputNet);
        $this->assertSame(24.0, $picture->outputVat);
        $this->assertSame(1, $picture->outputCount);

        // The non-sales docs are broken out, not lost.
        $this->assertSame(5000.0, $picture->breakdown['payroll']['net']);
        $this->assertSame(0, (int) ($picture->breakdown['payroll']['vat']));
        $this->assertSame(300.0, $picture->breakdown['intracommunity']['net']);
        $this->assertSame(72.0, $picture->breakdown['intracommunity']['vat']);

        // Έξοδα = the inbound expense doc.
        $this->assertSame(50.0, $picture->inputNet);
        $this->assertSame(12.0, $picture->inputVat);
    }
}
