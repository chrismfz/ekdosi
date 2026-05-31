<?php

namespace Tests\Feature\Expenses;

use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Models\Company;
use App\Models\Expense;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Έξοδα list page filters + tabs must not 500. Reproduces the live error
 * (Builder::where(Closure) on a null model) by driving the REAL Filament page
 * through Livewire so the table query, the SelectFilters and the getTabs()
 * modifyQueryUsing closures all run through the actual builder.
 */
class ExpensesListFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Exp', 'slug' => 'exp-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
            'afm' => '800000000',
        ]);

        $user = User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('x'),
        ]);
        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($this->tenant);
    }

    private function expense(string $source, ?string $category, ?string $type, ?string $state = 'VALID'): Expense
    {
        return Expense::create([
            'company_id' => $this->tenant->id,
            'mydata_mark' => (string) random_int(1, PHP_INT_MAX),
            'invoice_type' => $type,
            'issue_date' => now(),
            'net_total' => 100, 'vat_total' => 24, 'gross_total' => 124,
            'mydata_state' => $state,
            'source' => $source,
            'category' => $category,
        ]);
    }

    public function test_list_renders(): void
    {
        $this->expense('sync', null, '13.1');
        $this->expense('self_declared', 'payroll', '17.1');

        Livewire::test(ListExpenses::class)->assertOk()->loadTable();
    }

    public function test_source_filter_does_not_error(): void
    {
        $sync = $this->expense('sync', null, '13.1');
        $self = $this->expense('self_declared', 'intracommunity', '14.3');

        Livewire::test(ListExpenses::class)
            ->loadTable()
            ->filterTable('source', 'self_declared')
            ->assertCanSeeTableRecords([$self])
            ->assertCanNotSeeTableRecords([$sync]);
    }

    public function test_category_filter_does_not_error(): void
    {
        $payroll = $this->expense('self_declared', 'payroll', '17.1');
        $intra = $this->expense('self_declared', 'intracommunity', '14.3');

        Livewire::test(ListExpenses::class)
            ->loadTable()
            ->filterTable('category', 'payroll')
            ->assertCanSeeTableRecords([$payroll])
            ->assertCanNotSeeTableRecords([$intra]);
    }

    public function test_mydata_state_filter_does_not_error(): void
    {
        $valid = $this->expense('sync', null, '13.1', 'VALID');
        $cancelled = $this->expense('sync', null, '13.1', 'CANCELLED');

        Livewire::test(ListExpenses::class)
            ->loadTable()
            ->filterTable('mydata_state', 'CANCELLED')
            ->assertCanSeeTableRecords([$cancelled])
            ->assertCanNotSeeTableRecords([$valid]);
    }

    public function test_classification_state_filter_does_not_error(): void
    {
        $this->expense('sync', null, '13.1');

        Livewire::test(ListExpenses::class)
            ->loadTable()
            ->filterTable('classification_state', 'classified')
            ->assertOk();
    }

    public function test_tabs_do_not_error(): void
    {
        $sync = $this->expense('sync', null, '13.1');
        $ours = $this->expense('self_declared', 'intracommunity', '14.3');
        $payroll = $this->expense('self_declared', 'payroll', '17.1');

        foreach (['all', 'suppliers', 'ours', 'accounting'] as $tab) {
            Livewire::test(ListExpenses::class)
                ->set('activeTab', $tab)
                ->loadTable()
                ->assertOk();
        }
    }
}
