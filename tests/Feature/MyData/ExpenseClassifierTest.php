<?php

namespace Tests\Feature\MyData;

use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseClassificationRule;
use App\Services\MyData\ExpenseClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The #5 auto-classification engine: «supplier ΑΦΜ (+ optional type) →
 * χαρακτηρισμός», applied on import + the bulk «Εφαρμογή κανόνων» action.
 */
class ExpenseClassifierTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'Cls OE', 'slug' => 'cls-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
        ]);
    }

    private function rule(array $o): ExpenseClassificationRule
    {
        return ExpenseClassificationRule::create(array_merge([
            'company_id' => $this->tenant->id,
            'supplier_afm' => '998482379',
            'invoice_type' => null,
            'classification_type' => 'E3_102_001',
            'classification_category' => 'category2_5',
            'is_active' => true,
            'priority' => 0,
        ], $o));
    }

    private function expense(array $o = []): Expense
    {
        return Expense::create(array_merge([
            'company_id' => $this->tenant->id,
            'supplier_afm' => '998482379',
            'invoice_type' => '14.30',
            'mydata_mark' => '400'.uniqid(),
            'net_total' => 100, 'vat_total' => 24, 'gross_total' => 124,
            'source' => 'sync',
        ], $o));
    }

    public function test_matching_rule_is_applied(): void
    {
        $this->rule([]);
        $expense = $this->expense();

        $this->assertTrue(app(ExpenseClassifier::class)->classify($expense));
        $this->assertSame('E3_102_001', $expense->classification_type);
        $this->assertSame('category2_5', $expense->classification_category);
        $this->assertSame('classified', $expense->classification_state);
    }

    public function test_no_rule_no_change(): void
    {
        $expense = $this->expense(['supplier_afm' => '111111111']);
        $this->assertFalse(app(ExpenseClassifier::class)->classify($expense));
        $this->assertNull($expense->classification_state);
    }

    public function test_type_specific_rule_beats_generic(): void
    {
        $this->rule(['invoice_type' => null, 'classification_type' => 'E3_GENERIC']);
        $this->rule(['invoice_type' => '14.30', 'classification_type' => 'E3_SPECIFIC']);

        $expense = $this->expense(['invoice_type' => '14.30']);
        app(ExpenseClassifier::class)->classify($expense);

        $this->assertSame('E3_SPECIFIC', $expense->classification_type);
    }

    public function test_inactive_rule_ignored_and_submitted_not_clobbered(): void
    {
        $this->rule(['is_active' => false]);
        $expense = $this->expense();
        $this->assertFalse(app(ExpenseClassifier::class)->classify($expense));

        // Already submitted → never re-classified, even with an active rule.
        $this->rule(['is_active' => true]);
        $submitted = $this->expense(['classification_state' => 'submitted', 'classification_type' => 'E3_KEEP']);
        $this->assertFalse(app(ExpenseClassifier::class)->classify($submitted));
        $this->assertSame('E3_KEEP', $submitted->classification_type);
    }
}
