<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ExpenseMark;
use App\Services\MyData\SalesReconciler;
use App\Services\MyData\SalesReconciliationResult;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RequestTransmittedDocs also returns the accountant's self-declared EXPENSE docs
 * (13.x/14.x/17.x). Once the ExpenseImporter holds them in `expense_marks` they are
 * NOT «missing locally» — otherwise the nightly sales reconcile raised a false
 * «N αποκλίσεις» every morning (prod, myip, 2026-10-30).
 */
class SalesReconcilerExpenseMarksTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('exp-marks');
    }

    public function test_tenant_whose_only_gap_is_imported_expense_marks_reports_zero(): void
    {
        foreach (['400015718842701', '400015820977154', '400015820977155', '400015826597341'] as $mark) {
            $this->importExpenseMark($this->tenant, $mark);
        }

        $result = $this->reconcile([
            ['400015718842701', '13.1'],
            ['400015820977154', '14.3'],
            ['400015820977155', '14.3'],
            ['400015826597341', '17.1'],
        ]);

        $this->assertSame(4, $result->aadeTotal);
        $this->assertSame([], $result->missingLocally);
        $this->assertSame(0, $result->discrepancyCount());
    }

    public function test_unimported_or_other_tenants_or_income_marks_still_surface(): void
    {
        $other = $this->makeTenant('exp-other');

        $this->importExpenseMark($this->tenant, '400000000000101');   // imported 14.3 → hidden
        $this->importExpenseMark($other, '400000000000102');          // OTHER tenant's import → still missing
        $this->importExpenseMark($this->tenant, '400000000000104');   // income-type in expense_marks = data fault

        $result = $this->reconcile([
            ['400000000000101', '14.3'],
            ['400000000000102', '14.3'],
            ['400000000000103', '17.1'],   // not imported yet
            ['400000000000104', '1.1'],
        ]);

        $missing = collect($result->missingLocally)->pluck('mark')->sort()->values()->all();

        $this->assertSame(['400000000000102', '400000000000103', '400000000000104'], $missing);
        $this->assertSame(3, $result->discrepancyCount());
    }

    /**
     * @param  list<array{0: string, 1: string}>  $docs  [mark, invoiceType]
     */
    private function reconcile(array $docs): SalesReconciliationResult
    {
        $mock = new MockHandler([new Response(200, [], $this->response($docs))]);

        return (new SalesReconciler($this->tenant, $mock))->reconcile(now()->subMonth(), now());
    }

    private function makeTenant(string $slug): Company
    {
        return Company::create([
            'name' => 'Expense marks '.$slug,
            'slug' => $slug.'-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '800561849',
            'mydata_aade_id_sandbox' => 'TESTUSER',
            'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);
    }

    private function importExpenseMark(Company $company, string $mark): void
    {
        ExpenseMark::create([
            'company_id' => $company->getKey(),
            'mark' => $mark,
            'mydata_action' => 'self_declared',
        ]);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $docs
     */
    private function response(array $docs): string
    {
        $invoices = '';
        foreach ($docs as $i => [$mark, $type]) {
            $aa = $i + 1;
            $invoices .= <<<XML
        <invoice>
            <uid>UID{$aa}</uid>
            <mark>{$mark}</mark>
            <invoiceHeader>
                <series>ACC</series>
                <aa>{$aa}</aa>
                <issueDate>2026-10-15</issueDate>
                <invoiceType>{$type}</invoiceType>
            </invoiceHeader>
            <invoiceSummary>
                <totalNetValue>100.00</totalNetValue>
                <totalGrossValue>124.00</totalGrossValue>
            </invoiceSummary>
        </invoice>

XML;
        }

        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
    <invoicesDoc>
{$invoices}    </invoicesDoc>
</RequestedDoc>
XML;
    }
}
