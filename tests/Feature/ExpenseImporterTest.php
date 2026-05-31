<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseMark;
use App\Models\Supplier;
use App\Services\MyData\ExpenseImporter;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Network-layer test for the expense importer: a Guzzle MockHandler feeds a
 * canned RequestDocs payload (full doc with lines, incl. a 0%/exempt line)
 * through firebed. Verifies expense+lines creation, supplier auto-create vs
 * link, the verbatim §8.2/§8.3 codes, the audit mark, and idempotency.
 */
class ExpenseImporterTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Imp test',
            'slug' => 'imp-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '801280908',
            'mydata_aade_id_sandbox' => 'TESTUSER',
            'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);
    }

    private function import(MockHandler $mock, ?string $onlyMark = null)
    {
        return (new ExpenseImporter($this->tenant, $mock))->import(
            now()->subMonth(),
            now(),
            $onlyMark,
        );
    }

    public function test_imports_expense_with_lines_and_creates_supplier(): void
    {
        $result = $this->import(new MockHandler([
            new Response(200, [], $this->twoLineDoc()),
        ]));

        $this->assertSame(1, $result->created);
        $this->assertSame(1, $result->suppliersCreated);

        $expense = Expense::where('company_id', $this->tenant->id)->firstOrFail();
        $this->assertSame('400012434052701', $expense->mydata_mark);
        $this->assertSame('1.1', $expense->invoice_type);
        $this->assertSame('VALID', $expense->mydata_state);
        $this->assertEquals(150.00, (float) $expense->net_total);
        $this->assertEquals(174.00, (float) $expense->gross_total);

        // Supplier auto-created from the issuer AFM (with the doc's name).
        $supplier = Supplier::where('company_id', $this->tenant->id)->where('afm', '998482379')->firstOrFail();
        $this->assertSame('ΑΛΦΑΝΕΤ ΑΕ', $supplier->name);
        $this->assertSame($supplier->id, $expense->supplier_id);

        // Two lines; the 2nd is a 0%/exempt line stored verbatim.
        $this->assertCount(2, $expense->lines);
        $line2 = $expense->lines->firstWhere('line_number', 2);
        $this->assertSame(7, $line2->vat_category);
        $this->assertSame(16, $line2->vat_exemption_category);
        $this->assertEquals(0.0, (float) $line2->vat_amount);

        // Audit mark written with the doc XML.
        $this->assertSame(1, ExpenseMark::where('company_id', $this->tenant->id)->count());
        $mark = ExpenseMark::first();
        $this->assertSame('RequestDocs', $mark->mydata_action);
        $this->assertNotEmpty($mark->response);
    }

    public function test_is_idempotent_and_links_existing_supplier(): void
    {
        // Pre-existing supplier for the issuer AFM (manual) → link, not create.
        $supplier = Supplier::create([
            'company_id' => $this->tenant->id,
            'afm' => '998482379',
            'name' => 'Χειροκίνητος',
            'source' => 'manual',
        ]);

        $first = $this->import(new MockHandler([new Response(200, [], $this->twoLineDoc())]));
        $this->assertSame(1, $first->created);
        $this->assertSame(0, $first->suppliersCreated, 'existing supplier linked, not recreated');
        $this->assertSame($supplier->id, Expense::first()->supplier_id);

        // Re-run: the MARK already exists → skipped, no duplicate expense/lines.
        $second = $this->import(new MockHandler([new Response(200, [], $this->twoLineDoc())]));
        $this->assertSame(0, $second->created);
        $this->assertSame(1, $second->skippedExisting);
        $this->assertSame(1, Expense::where('company_id', $this->tenant->id)->count());
        $this->assertSame(2, \App\Models\ExpenseLine::where('company_id', $this->tenant->id)->count());
    }

    public function test_only_mark_filters_and_reports_not_found(): void
    {
        // Window has mark 701; we ask for a different one → not found, nothing created.
        $result = $this->import(new MockHandler([new Response(200, [], $this->twoLineDoc())]), '999999999999999');

        $this->assertSame(0, $result->created);
        $this->assertSame(['999999999999999'], $result->notFoundMarks);
        $this->assertSame(0, Expense::where('company_id', $this->tenant->id)->count());
    }

    public function test_cancelled_doc_imports_as_cancelled(): void
    {
        // The doc's MARK is listed in <cancelledInvoicesDoc> → must import as
        // CANCELLED, not as a live VALID expense.
        $this->import(new MockHandler([new Response(200, [], $this->cancelledDoc())]));

        $expense = Expense::where('company_id', $this->tenant->id)->firstOrFail();
        $this->assertSame('CANCELLED', $expense->mydata_state);
    }

    private function cancelledDoc(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
    <invoicesDoc>
        <invoice>
            <mark>400012434052701</mark>
            <issuer><vatNumber>998482379</vatNumber><country>GR</country></issuer>
            <counterpart><vatNumber>801280908</vatNumber><country>GR</country></counterpart>
            <invoiceHeader><series>A</series><aa>42</aa><issueDate>2026-01-15</issueDate><invoiceType>1.1</invoiceType><currency>EUR</currency></invoiceHeader>
            <invoiceDetails><lineNumber>1</lineNumber><netValue>100.00</netValue><vatCategory>1</vatCategory><vatAmount>24.00</vatAmount></invoiceDetails>
            <invoiceSummary><totalNetValue>100.00</totalNetValue><totalVatAmount>24.00</totalVatAmount><totalGrossValue>124.00</totalGrossValue></invoiceSummary>
        </invoice>
    </invoicesDoc>
    <cancelledInvoicesDoc>
        <cancelledInvoice>
            <invoiceMark>400012434052701</invoiceMark>
            <cancellationMark>400012434099999</cancellationMark>
            <cancellationDate>2026-01-20</cancellationDate>
        </cancelledInvoice>
    </cancelledInvoicesDoc>
</RequestedDoc>
XML;
    }

    public function test_imports_per_line_expense_classification(): void
    {
        $this->import(new MockHandler([
            new Response(200, [], $this->classifiedLineDoc()),
        ]));

        $expense = Expense::where('company_id', $this->tenant->id)->firstOrFail();
        $line = $expense->lines->firstWhere('line_number', 1);

        // The line's <expensesClassification> is recorded verbatim.
        $this->assertSame('E3_102_001', $line->classification_type);
        $this->assertSame('category2_1', $line->classification_category);

        // A line with NO classification leaves both fields null.
        $line2 = $expense->lines->firstWhere('line_number', 2);
        $this->assertNull($line2->classification_type);
        $this->assertNull($line2->classification_category);
    }

    private function classifiedLineDoc(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
    <invoicesDoc>
        <invoice>
            <uid>UID-IMP-CLS</uid>
            <mark>400012434052702</mark>
            <issuer><vatNumber>998482379</vatNumber><country>GR</country></issuer>
            <counterpart><vatNumber>801280908</vatNumber><country>GR</country></counterpart>
            <invoiceHeader>
                <series>A</series><aa>43</aa>
                <issueDate>2026-01-16</issueDate>
                <invoiceType>1.1</invoiceType><currency>EUR</currency>
            </invoiceHeader>
            <invoiceDetails>
                <lineNumber>1</lineNumber>
                <netValue>100.00</netValue>
                <vatCategory>1</vatCategory>
                <vatAmount>24.00</vatAmount>
                <expensesClassification>
                    <classificationType>E3_102_001</classificationType>
                    <classificationCategory>category2_1</classificationCategory>
                    <amount>100.00</amount>
                </expensesClassification>
            </invoiceDetails>
            <invoiceDetails>
                <lineNumber>2</lineNumber>
                <netValue>50.00</netValue>
                <vatCategory>1</vatCategory>
                <vatAmount>12.00</vatAmount>
            </invoiceDetails>
            <invoiceSummary>
                <totalNetValue>150.00</totalNetValue>
                <totalVatAmount>36.00</totalVatAmount>
                <totalGrossValue>186.00</totalGrossValue>
            </invoiceSummary>
        </invoice>
    </invoicesDoc>
</RequestedDoc>
XML;
    }

    private function twoLineDoc(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
    <invoicesDoc>
        <invoice>
            <uid>UID-IMP-1</uid>
            <mark>400012434052701</mark>
            <authenticationCode>AUTH123</authenticationCode>
            <issuer>
                <vatNumber>998482379</vatNumber>
                <country>GR</country>
                <name>ΑΛΦΑΝΕΤ ΑΕ</name>
            </issuer>
            <counterpart><vatNumber>801280908</vatNumber><country>GR</country></counterpart>
            <invoiceHeader>
                <series>A</series>
                <aa>42</aa>
                <issueDate>2026-01-15</issueDate>
                <invoiceType>1.1</invoiceType>
                <currency>EUR</currency>
            </invoiceHeader>
            <invoiceDetails>
                <lineNumber>1</lineNumber>
                <netValue>100.00</netValue>
                <vatCategory>1</vatCategory>
                <vatAmount>24.00</vatAmount>
            </invoiceDetails>
            <invoiceDetails>
                <lineNumber>2</lineNumber>
                <netValue>50.00</netValue>
                <vatCategory>7</vatCategory>
                <vatExemptionCategory>16</vatExemptionCategory>
                <vatAmount>0.00</vatAmount>
            </invoiceDetails>
            <invoiceSummary>
                <totalNetValue>150.00</totalNetValue>
                <totalVatAmount>24.00</totalVatAmount>
                <totalGrossValue>174.00</totalGrossValue>
            </invoiceSummary>
        </invoice>
    </invoicesDoc>
</RequestedDoc>
XML;
    }
}
