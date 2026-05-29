<?php

namespace Tests\Feature;

use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Models\Company;
use App\Models\Expense;
use App\Support\MyData\Codes;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * E5 — per-document expense classification (local only). Covers the Codes
 * validation/options (delegated to firebed's §8 enums) and the ViewExpense
 * "Χαρακτηρισμός" action writing the header columns + state.
 */
class ExpenseClassificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_codes_expense_classification_helpers(): void
    {
        $this->assertCount(88, Codes::expenseClassTypeOptions());
        $this->assertCount(15, Codes::expenseClassCategoryOptions());

        $this->assertTrue(Codes::isValidExpenseClassType('E3_585_001'));
        $this->assertTrue(Codes::isValidExpenseClassCategory('category2_3'));
        $this->assertFalse(Codes::isValidExpenseClassType('E3_BOGUS'));
        $this->assertFalse(Codes::isValidExpenseClassCategory('category9_9'));

        // Options carry the Greek label alongside the code.
        $this->assertStringContainsString('—', Codes::expenseClassCategoryOptions()['category2_1']);
    }

    public function test_classify_action_sets_header_classification(): void
    {
        $tenant = Company::create([
            'name' => 'Cls test',
            'slug' => 'cls-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '801280908',
        ]);

        $user = \App\Models\User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]);
        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($tenant);

        $expense = Expense::create([
            'company_id' => $tenant->id,
            'mydata_mark' => '400000000000123',
            'source' => 'sync',
        ]);

        $this->assertNull($expense->classification_state);

        Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
            ->callAction('classify', data: [
                'classification_type' => 'E3_585_001',
                'classification_category' => 'category2_3',
            ])
            ->assertHasNoErrors();

        $expense->refresh();
        $this->assertSame('E3_585_001', $expense->classification_type);
        $this->assertSame('category2_3', $expense->classification_category);
        $this->assertSame('classified', $expense->classification_state);

        // A forged code (bypassing the Select options) is rejected server-side
        // by Filament's options-derived `in` rule — the action never persists
        // it. Documents the security property the review confirmed.
        Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
            ->callAction('classify', data: [
                'classification_type' => 'E3_BOGUS',
                'classification_category' => 'category2_3',
            ])
            ->assertHasActionErrors(['classification_type']);

        $expense->refresh();
        $this->assertSame('E3_585_001', $expense->classification_type, 'forged code must not overwrite the valid one');
    }
}
