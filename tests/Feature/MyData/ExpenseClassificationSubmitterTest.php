<?php

namespace Tests\Feature\MyData;

use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Services\MyData\ExpenseClassificationSubmitter;
use Firebed\AadeMyData\Factories\ResponseDocXmlFactory;
use Firebed\AadeMyData\Models\Response as AadeResponse;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response as HttpResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Network-layer test for the expense-classification submitter: a Guzzle
 * MockHandler stands in for AADE's SendExpensesClassification endpoint.
 */
class ExpenseClassificationSubmitterTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'Cls test', 'slug' => 'cls-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox', 'afm' => '801280908',
            'mydata_aade_id_sandbox' => 'TESTUSER', 'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);
    }

    private function classifiedExpense(array $overrides = []): Expense
    {
        $expense = Expense::create(array_merge([
            'company_id' => $this->tenant->id, 'source' => 'sync',
            'mydata_mark' => '400001234567890', 'mydata_state' => 'VALID',
            'invoice_type' => '2.1', 'supplier_name' => 'Προμηθευτής ΑΕ', 'supplier_afm' => '094000045',
            'date' => now(), 'issue_date' => now(), 'currency' => 'EUR',
            'net_total' => 10, 'vat_total' => 2.4, 'gross_total' => 12.4,
            'classification_type' => 'E3_102_001', 'classification_category' => 'category2_1',
            'classification_state' => 'classified',
        ], $overrides));

        ExpenseLine::create([
            'company_id' => $this->tenant->id, 'expense_id' => $expense->id,
            'line_number' => 1, 'net_value' => 10, 'vat_amount' => 2.4, 'vat_category' => 1,
        ]);

        return $expense->fresh('lines');
    }

    private function successHandler(string $invoiceMark): MockHandler
    {
        $factory = new ResponseDocXmlFactory;
        $factory->addResponse(AadeResponse::factory()->make(['invoiceMark' => $invoiceMark]));

        return new MockHandler([new HttpResponse(200, body: $factory->asXML())]);
    }

    #[Test]
    public function it_classifies_each_line_with_its_own_category(): void
    {
        // Same supplier invoice, mixed: line 1 = εμπορεύματα (category2_1),
        // line 2 = πάγια (category2_7) — set per line, header left blank.
        $expense = $this->classifiedExpense([
            'classification_type' => null, 'classification_category' => null,
        ]);
        $expense->lines()->first()->forceFill([
            'classification_type' => 'E3_102_001', 'classification_category' => 'category2_1',
        ])->save();
        ExpenseLine::create([
            'company_id' => $this->tenant->id, 'expense_id' => $expense->id,
            'line_number' => 2, 'net_value' => 500, 'vat_amount' => 120, 'vat_category' => 1,
            'classification_type' => 'E3_103', 'classification_category' => 'category2_7',
        ]);

        $xml = (new ExpenseClassificationSubmitter($this->tenant))->requestXml($expense->fresh('lines'));

        // Both per-line categories travel — the mixed-classification proof.
        $this->assertStringContainsString('category2_1', $xml);
        $this->assertStringContainsString('category2_7', $xml);
        $this->assertSame(2, substr_count($xml, '<lineNumber>'));
    }

    #[Test]
    public function mixed_lines_are_flagged_as_mixed(): void
    {
        // Header-only (uniform) → not mixed.
        $uniform = $this->classifiedExpense();
        $this->assertFalse($uniform->classificationIsMixed());

        // Two lines with different categories → mixed.
        $mixed = $this->classifiedExpense([
            'mydata_mark' => '400009999999999',
            'classification_type' => null, 'classification_category' => null,
        ]);
        $mixed->lines()->first()->forceFill(['classification_type' => 'E3_102_001', 'classification_category' => 'category2_1'])->save();
        ExpenseLine::create([
            'company_id' => $this->tenant->id, 'expense_id' => $mixed->id,
            'line_number' => 2, 'net_value' => 500, 'vat_amount' => 120, 'vat_category' => 1,
            'classification_type' => 'E3_103', 'classification_category' => 'category2_7',
        ]);
        $this->assertTrue($mixed->fresh('lines')->classificationIsMixed());
    }

    #[Test]
    public function request_xml_is_a_dry_run_that_posts_nothing(): void
    {
        $expense = $this->classifiedExpense();

        // No mock handler needed — requestXml never hits the network.
        $xml = (new ExpenseClassificationSubmitter($this->tenant))->requestXml($expense);

        $this->assertStringContainsString('category2_1', $xml);
        $this->assertSame('classified', $expense->fresh()->classification_state); // unchanged
        $this->assertSame(0, $expense->marks()->count());
    }

    private function rejectionHandler(): MockHandler
    {
        $factory = new ResponseDocXmlFactory;
        $factory->addResponse(AadeResponse::factory()->make([
            'statusCode' => 'ValidationError', 'classificationMark' => null,
        ]));

        return new MockHandler([new HttpResponse(200, body: $factory->asXML())]);
    }

    #[Test]
    public function it_submits_the_classification_and_writes_an_audit_row(): void
    {
        $expense = $this->classifiedExpense();

        $mark = (new ExpenseClassificationSubmitter($this->tenant, $this->successHandler($expense->mydata_mark)))
            ->submit($expense);

        $this->assertNotSame('', $mark);                                  // AADE classification MARK
        $this->assertSame('submitted', $expense->fresh()->classification_state);

        // Legal audit trail: a SendExpensesClassification mark row with request+response.
        $auditRow = $expense->marks()->where('mydata_action', 'SendExpensesClassification')->first();
        $this->assertNotNull($auditRow);
        $this->assertNotEmpty($auditRow->request);
        $this->assertNotEmpty($auditRow->response);
    }

    #[Test]
    public function an_aade_rejection_throws_and_leaves_state_untouched(): void
    {
        $expense = $this->classifiedExpense();

        try {
            (new ExpenseClassificationSubmitter($this->tenant, $this->rejectionHandler()))->submit($expense);
            $this->fail('expected the rejection to throw');
        } catch (RuntimeException) {
            // state must NOT flip, and no audit row claims success
        }

        $this->assertSame('classified', $expense->fresh()->classification_state);
        $this->assertSame(0, $expense->marks()->count());
    }

    #[Test]
    public function it_refuses_an_expense_without_a_mydata_mark(): void
    {
        $expense = $this->classifiedExpense(['mydata_mark' => null]);

        $this->expectException(RuntimeException::class);
        (new ExpenseClassificationSubmitter($this->tenant))->submit($expense);
    }

    #[Test]
    public function it_refuses_an_unclassified_expense(): void
    {
        $expense = $this->classifiedExpense(['classification_type' => null, 'classification_category' => null]);

        $this->expectException(RuntimeException::class);
        (new ExpenseClassificationSubmitter($this->tenant))->submit($expense);
    }
}
