<?php

namespace Tests\Feature\Expenses;

use App\Enums\ExpenseSource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Models\Company;
use App\Models\Expense;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Expenses polish — manual entry (a supplier doc not in myDATA) + the private
 * document attachment download. Header totals are recomputed from the lines and
 * company_id + line_number are stamped by the page (BelongsToCompany doesn't
 * auto-fill them). myDATA-sourced expenses stay read-only.
 */
class ManualExpenseEntryTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->tenant = Company::create([
            'name' => 'T', 'slug' => 't-'.uniqid(), 'country_code' => 'GR',
        ]);
        $this->user = User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]);
        $this->user->companies()->attach($this->tenant->id);
        $this->actingAs($this->user);
        Filament::setTenant($this->tenant);
    }

    #[Test]
    public function it_creates_a_manual_expense_computing_totals_and_stamping_lines(): void
    {
        Livewire::test(CreateExpense::class)
            ->fillForm([
                'supplier_name' => 'Foreign GmbH',
                'issue_date' => '2026-06-01',
                'currency' => 'EUR',
                'lines' => [
                    ['item_descr' => 'Service A', 'quantity' => 1, 'vat_category' => 1, 'net_value' => 100, 'vat_amount' => 24],
                    ['item_descr' => 'Service B', 'quantity' => 2, 'vat_category' => 1, 'net_value' => 50, 'vat_amount' => 12],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $expense = Expense::where('company_id', $this->tenant->id)->sole();
        $this->assertSame(ExpenseSource::Manual, $expense->source);
        $this->assertSame('Foreign GmbH', $expense->supplier_name);
        $this->assertEquals(150.0, (float) $expense->net_total);
        $this->assertEquals(36.0, (float) $expense->vat_total);
        $this->assertEquals(186.0, (float) $expense->gross_total);

        $lines = $expense->lines()->orderBy('line_number')->get();
        $this->assertCount(2, $lines);
        $this->assertSame([1, 2], $lines->pluck('line_number')->all());
        $this->assertSame($this->tenant->id, $lines->first()->company_id); // stamped, not null
    }

    #[Test]
    public function editing_re_syncs_lines_and_recomputes_totals(): void
    {
        // Start with one line (€100 + €24).
        $expense = Expense::create([
            'company_id' => $this->tenant->id, 'source' => 'manual', 'issue_date' => '2026-06-01',
            'net_total' => 100, 'vat_total' => 24, 'gross_total' => 124,
        ]);
        $expense->lines()->create([
            'company_id' => $this->tenant->id, 'line_number' => 1,
            'item_descr' => 'Old', 'quantity' => 1, 'vat_category' => 1, 'net_value' => 100, 'vat_amount' => 24,
        ]);

        // Replace with two different lines → totals + lines must re-sync.
        Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()])
            ->fillForm([
                'lines' => [
                    ['item_descr' => 'New A', 'quantity' => 1, 'vat_category' => 1, 'net_value' => 200, 'vat_amount' => 48],
                    ['item_descr' => 'New B', 'quantity' => 1, 'vat_category' => 1, 'net_value' => 10, 'vat_amount' => 0],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $expense->refresh();
        $this->assertEquals(210.0, (float) $expense->net_total);
        $this->assertEquals(48.0, (float) $expense->vat_total);
        $this->assertEquals(258.0, (float) $expense->gross_total);

        $lines = $expense->lines()->orderBy('line_number')->get();
        $this->assertCount(2, $lines);                                  // old line gone, two new
        $this->assertSame(['New A', 'New B'], $lines->pluck('item_descr')->all());
        $this->assertSame([1, 2], $lines->pluck('line_number')->all());
    }

    #[Test]
    public function only_manual_expenses_are_editable(): void
    {
        $manual = Expense::create(['company_id' => $this->tenant->id, 'source' => 'manual', 'issue_date' => '2026-06-01']);
        $synced = Expense::create(['company_id' => $this->tenant->id, 'source' => 'sync', 'issue_date' => '2026-06-01', 'mydata_mark' => 'M1']);

        $this->assertTrue(ExpenseResource::canEdit($manual));
        $this->assertFalse(ExpenseResource::canEdit($synced));
    }

    #[Test]
    public function the_document_download_is_signed_auth_and_tenant_checked(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('expense-documents/doc.pdf', '%PDF-1.4 fake');
        $expense = Expense::create([
            'company_id' => $this->tenant->id, 'source' => 'manual',
            'issue_date' => '2026-06-01', 'document_path' => 'expense-documents/doc.pdf',
        ]);

        $url = URL::temporarySignedRoute('expenses.document.download', now()->addMinutes(5), ['expense' => $expense]);

        // Member of the tenant → 200.
        $this->get($url)->assertOk();

        // A user who is NOT a member of the expense's company → 403 (cross-tenant guard).
        $outsider = User::create(['name' => 'X', 'email' => 'x-'.uniqid().'@test.local', 'password' => bcrypt('x')]);
        $this->actingAs($outsider)->get($url)->assertForbidden();
    }

    #[Test]
    public function an_unsigned_document_url_is_rejected(): void
    {
        $expense = Expense::create([
            'company_id' => $this->tenant->id, 'source' => 'manual',
            'issue_date' => '2026-06-01', 'document_path' => 'expense-documents/doc.pdf',
        ]);

        // No valid signature → 403 from the `signed` middleware.
        $this->get(route('expenses.document.download', ['expense' => $expense]))->assertForbidden();
    }
}
