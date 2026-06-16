<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ExpenseClassificationRules\ExpenseClassificationRuleResource;
use App\Filament\Resources\ExpenseClassificationRules\Pages\ListExpenseClassificationRules;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseClassificationRule;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * #5 UI: the rules resource (gr-mydata gated), the «προς χαρακτηρισμό» worklist
 * scope, and the «Δημιουργία κανόνα» action that builds a rule from an expense.
 */
class ExpenseClassificationRulesTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'A', 'email' => 'a-'.uniqid().'@t.local', 'password' => bcrypt('x'),
        ]));
        $this->tenant = Company::create([
            'name' => 'Cls OE', 'slug' => 'cls-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
        ]);
        Filament::setTenant($this->tenant);
    }

    private function expense(array $o = []): Expense
    {
        return Expense::create(array_merge([
            'company_id' => $this->tenant->id,
            'supplier_afm' => '998482379', 'supplier_name' => 'ΑΛΦΑΝΕΤ ΑΕ',
            'invoice_type' => '14.30', 'mydata_mark' => '400'.uniqid(),
            'net_total' => 100, 'vat_total' => 24, 'gross_total' => 124, 'source' => 'sync',
        ], $o));
    }

    public function test_needs_classification_scope(): void
    {
        $this->expense();                                                  // ✓ unclassified, has mark
        $this->expense(['classification_state' => 'classified']);          // ✗ already classified
        $this->expense(['mydata_mark' => null]);                           // ✗ no mark (manual/foreign)
        $this->expense(['mydata_state' => 'CANCELLED']);                   // ✗ cancelled

        $this->assertSame(1, Expense::query()->where('company_id', $this->tenant->id)->needsClassification()->count());
    }

    public function test_rule_resource_is_gr_mydata_only(): void
    {
        $this->assertTrue(ExpenseClassificationRuleResource::canAccess());

        Livewire::test(ListExpenseClassificationRules::class)->assertSuccessful();

        $ee = Company::create([
            'name' => 'EE', 'slug' => 'ee-'.uniqid(), 'country_code' => 'EE',
            'einvoice_provider' => 'ee-peppol', 'mydata_mode' => 'off',
        ]);
        Filament::setTenant($ee);
        $this->assertFalse(ExpenseClassificationRuleResource::canAccess());
    }

    public function test_create_rule_action_builds_a_rule_from_the_expense(): void
    {
        $expense = $this->expense();

        Livewire::test(ViewExpense::class, ['record' => $expense->id])
            ->callAction('create_rule', data: [
                'classification_type' => 'E3_102_001',
                'classification_category' => 'category2_5',
                'only_this_type' => true,
            ])
            ->assertHasNoActionErrors();

        $rule = ExpenseClassificationRule::query()->where('company_id', $this->tenant->id)->firstOrFail();
        $this->assertSame('998482379', $rule->supplier_afm);
        $this->assertSame('14.30', $rule->invoice_type);   // only_this_type → scoped
        $this->assertSame('E3_102_001', $rule->classification_type);
    }
}
