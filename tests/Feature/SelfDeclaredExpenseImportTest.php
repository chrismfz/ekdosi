<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Expense;
use App\Services\MyData\ExpenseImporter;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * E8 — importing OUR OWN non-income documents from RequestTransmittedDocs into
 * `expenses` (αποδείξεις 13.x, ενδοκοινοτικά/VIES 14.x, μισθοδοσία 17.x). Real
 * sales (1.x/2.x) must be filtered out, the economic `category` recorded, and
 * the supplier resolved from the COUNTERPART (when present).
 */
class SelfDeclaredExpenseImportTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Self test',
            'slug' => 'self-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '801280908',
            'mydata_aade_id_sandbox' => 'TESTUSER',
            'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);
    }

    private function importSelf(MockHandler $mock)
    {
        return (new ExpenseImporter($this->tenant, $mock))->importSelfDeclared(
            now()->subMonth(),
            now(),
        );
    }

    public function test_imports_non_income_and_skips_our_sales(): void
    {
        $result = $this->importSelf(new MockHandler([
            new Response(200, [], $this->mixedDoc()),
        ]));

        // 3 docs in the window (1.1 sale, 17.1 payroll, 13.1 receipt); only the
        // two non-income ones are imported.
        $this->assertSame(2, $result->created);

        $expenses = Expense::where('company_id', $this->tenant->id)->get();
        $this->assertCount(2, $expenses);

        // Our sale (1.1) is NOT imported as an expense.
        $this->assertNull($expenses->firstWhere('invoice_type', '1.1'));

        // Payroll (17.1): self_declared, category 'payroll', no counterpart → no supplier.
        $payroll = $expenses->firstWhere('invoice_type', '17.1');
        $this->assertSame('self_declared', $payroll->source->value);
        $this->assertSame('payroll', $payroll->category);
        $this->assertNull($payroll->supplier_id);

        // Retail receipt (13.1): category 'retail_expense', supplier from counterpart.
        $receipt = $expenses->firstWhere('invoice_type', '13.1');
        $this->assertSame('retail_expense', $receipt->category);
        $this->assertSame('998482379', $receipt->supplier_afm);
        $this->assertSame(1, $receipt->lines->count());
    }

    public function test_is_idempotent(): void
    {
        $this->importSelf(new MockHandler([new Response(200, [], $this->mixedDoc())]));
        $second = $this->importSelf(new MockHandler([new Response(200, [], $this->mixedDoc())]));

        $this->assertSame(0, $second->created);
        $this->assertSame(2, $second->skippedExisting);
        $this->assertSame(2, Expense::where('company_id', $this->tenant->id)->count());
    }

    private function mixedDoc(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
    <invoicesDoc>
        <invoice>
            <mark>500000000000001</mark>
            <issuer><vatNumber>801280908</vatNumber><country>GR</country></issuer>
            <counterpart><vatNumber>123456789</vatNumber><country>GR</country></counterpart>
            <invoiceHeader><series>A</series><aa>1</aa><issueDate>2026-01-10</issueDate><invoiceType>1.1</invoiceType><currency>EUR</currency></invoiceHeader>
            <invoiceDetails><lineNumber>1</lineNumber><netValue>200.00</netValue><vatCategory>1</vatCategory><vatAmount>48.00</vatAmount></invoiceDetails>
            <invoiceSummary><totalNetValue>200.00</totalNetValue><totalVatAmount>48.00</totalVatAmount><totalGrossValue>248.00</totalGrossValue></invoiceSummary>
        </invoice>
        <invoice>
            <mark>500000000000002</mark>
            <issuer><vatNumber>801280908</vatNumber><country>GR</country></issuer>
            <invoiceHeader><series>A</series><aa>2</aa><issueDate>2026-01-11</issueDate><invoiceType>17.1</invoiceType><currency>EUR</currency></invoiceHeader>
            <invoiceDetails><lineNumber>1</lineNumber><netValue>5000.00</netValue><vatCategory>8</vatCategory><vatAmount>0.00</vatAmount></invoiceDetails>
            <invoiceSummary><totalNetValue>5000.00</totalNetValue><totalVatAmount>0.00</totalVatAmount><totalGrossValue>5000.00</totalGrossValue></invoiceSummary>
        </invoice>
        <invoice>
            <mark>500000000000003</mark>
            <issuer><vatNumber>801280908</vatNumber><country>GR</country></issuer>
            <counterpart><vatNumber>998482379</vatNumber><country>GR</country></counterpart>
            <invoiceHeader><series>A</series><aa>3</aa><issueDate>2026-01-12</issueDate><invoiceType>13.1</invoiceType><currency>EUR</currency></invoiceHeader>
            <invoiceDetails><lineNumber>1</lineNumber><netValue>80.00</netValue><vatCategory>1</vatCategory><vatAmount>19.20</vatAmount></invoiceDetails>
            <invoiceSummary><totalNetValue>80.00</totalNetValue><totalVatAmount>19.20</totalVatAmount><totalGrossValue>99.20</totalGrossValue></invoiceSummary>
        </invoice>
    </invoicesDoc>
</RequestedDoc>
XML;
    }
}
