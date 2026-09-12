<?php

namespace Tests\Feature;

use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
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
        // firebed v5.12 (myDATA v2.0.2) added the E3_881_001–004 codes
        // (Πωλήσεις για λογαριασμό Τρίτων) that were missing from the enum, so the
        // option count grew 88 → 92.
        $this->assertCount(92, Codes::expenseClassTypeOptions());
        $this->assertCount(15, Codes::expenseClassCategoryOptions());

        $this->assertTrue(Codes::isValidExpenseClassType('E3_585_001'));
        $this->assertTrue(Codes::isValidExpenseClassType('E3_881_001')); // v2.0.2 addition
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

        $user = User::create([
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

    public function test_notes_action_saves_on_readonly_mydata_expense_and_view_renders_links(): void
    {
        $tenant = Company::create([
            'name' => 'Notes test', 'slug' => 'notes-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox', 'afm' => '801280908',
        ]);
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]));
        Filament::setTenant($tenant);

        // A myDATA-sourced (read-only) expense carrying the doc-level links.
        $expense = Expense::create([
            'company_id' => $tenant->id,
            'mydata_mark' => '400000000000123',
            'source' => 'sync',
            'qr_url' => 'https://mydatapi.aade.gr/myDATA/TimologioQR/QRInfo?q=ABC',
            'downloading_invoice_url' => 'https://einvoice.impact.gr/p/EL094468339/DEAD/F630',
        ]);

        // The View page (infolist incl. the new «Σύνδεσμοι παραστατικού» section)
        // renders, AND the notes action writes ONLY the notes column on a doc whose
        // full Edit is blocked — «αυτό είναι εισιτήριο Aegean».
        Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
            ->assertOk()
            ->assertSee('https://einvoice.impact.gr/p/EL094468339/DEAD/F630')
            ->callAction('notes', data: ['notes' => 'Εισιτήριο Aegean'])
            ->assertHasNoErrors();

        $expense->refresh();
        $this->assertSame('Εισιτήριο Aegean', $expense->notes);
        // The AADE-mirrored fields are untouched by the notes write.
        $this->assertSame('sync', $expense->source->value);
        $this->assertSame('400000000000123', $expense->mydata_mark);

        // A note of literally "0" must survive (blank(), not a falsy `?:` check).
        Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
            ->callAction('notes', data: ['notes' => '0'])
            ->assertHasNoErrors();
        $this->assertSame('0', $expense->refresh()->notes);

        // Emptying it stores null (not '').
        Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
            ->callAction('notes', data: ['notes' => ''])
            ->assertHasNoErrors();
        $this->assertNull($expense->refresh()->notes);
    }

    public function test_classify_action_mixed_mode_sets_per_line(): void
    {
        $tenant = Company::create([
            'name' => 'Mix test', 'slug' => 'mix-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox', 'afm' => '801280908',
        ]);
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]));
        Filament::setTenant($tenant);

        $expense = Expense::create([
            'company_id' => $tenant->id, 'mydata_mark' => '400000000000999', 'source' => 'sync',
        ]);
        // Two lines with DIFFERENT classifications → classificationIsMixed() is
        // true, so the action opens in «Μικτό» mode pre-filled per line. Submitting
        // (defaults) must persist each line distinctly and mark the doc classified.
        $l1 = ExpenseLine::create([
            'company_id' => $tenant->id, 'expense_id' => $expense->id, 'line_number' => 1, 'net_value' => 100,
            'classification_type' => 'E3_585_001', 'classification_category' => 'category2_3',
        ]);
        $l2 = ExpenseLine::create([
            'company_id' => $tenant->id, 'expense_id' => $expense->id, 'line_number' => 2, 'net_value' => 50,
            'classification_type' => 'E3_585_002', 'classification_category' => 'category2_4',
        ]);

        Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
            ->mountAction('classify')
            ->assertSchemaStateSet(['mode' => 'mixed'])   // opened in mixed mode
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame('E3_585_001', $l1->refresh()->classification_type);
        $this->assertSame('E3_585_002', $l2->refresh()->classification_type);
        $this->assertSame('classified', $expense->refresh()->classification_state);
    }
}
