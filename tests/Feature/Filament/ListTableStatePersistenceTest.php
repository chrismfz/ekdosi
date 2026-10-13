<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Customers\Pages\CustomerLedger;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Invoices\RelationManagers\InvoicePaymentsRelationManager;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Resource LIST pages remember sort / filters / search for the session (so «πίσω»
 * from a record lands on the same list), while record-scoped tables (relation
 * managers, the Καρτέλα) do NOT carry a search/filter from one record to another.
 */
class ListTableStatePersistenceTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $type;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);

        $this->tenant = Company::create([
            'name' => 'Acme', 'slug' => 'persist-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->type = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'Τ', 'invcount' => 1, 'mydata_type' => '1.1']);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'C']);

        $this->actingAs(User::create(['name' => 'U', 'email' => 'u-'.uniqid().'@t.local', 'password' => bcrypt('x')]));
        Filament::setTenant($this->tenant);
    }

    private function invoice(string $invcode): Invoice
    {
        return Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => $invcode, 'code' => 1,
            'invoice_type_id' => $this->type->id, 'customer_id' => $this->customer->id,
            'local_status' => 'active', 'issued_at' => now(), 'company_name' => 'C',
            'net_total' => 100, 'gross_total' => 124,
        ]);
    }

    public function test_invoice_list_remembers_sort_filters_and_search_when_reopened(): void
    {
        Livewire::test(ListInvoices::class)
            ->sortTable('issued_at', 'asc')
            ->filterTable('payment_status', 'unpaid')
            ->searchTable('TPY1');

        $reopened = Livewire::test(ListInvoices::class)
            ->assertSet('tableSort', 'issued_at:asc')
            ->assertSet('tableSearch', 'TPY1');

        $this->assertSame('unpaid', $reopened->get('tableFilters.payment_status.value'));
    }

    public function test_a_filtered_deep_link_is_not_narrowed_by_a_leftover_search(): void
    {
        Livewire::test(ListInvoices::class)->searchTable('TPY1');

        // Dashboard card / overdue notification: ?filters[...] in the URL.
        Livewire::withQueryParams(['filters' => ['payment_status' => ['value' => 'unpaid']]])
            ->test(ListInvoices::class)
            ->assertSet('tableSearch', '')
            ->assertSet('tableFilters.payment_status.value', 'unpaid');

        // A plain visit still gets the remembered search back (reset the test
        // helper's query params — in a browser every request carries its own).
        Livewire::withQueryParams([])->test(ListInvoices::class)->assertSet('tableSearch', 'TPY1');
    }

    public function test_relation_manager_search_does_not_leak_to_another_record(): void
    {
        $a = $this->invoice('TPY1');
        $b = $this->invoice('TPY2');

        Livewire::test(InvoicePaymentsRelationManager::class, ['ownerRecord' => $a, 'pageClass' => ViewInvoice::class])
            ->searchTable('zzz');

        Livewire::test(InvoicePaymentsRelationManager::class, ['ownerRecord' => $b, 'pageClass' => ViewInvoice::class])
            ->assertSet('tableSearch', '');
    }

    public function test_customer_ledger_keeps_only_its_sort_not_the_year_filter(): void
    {
        $other = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Other']);

        Livewire::test(CustomerLedger::class, ['record' => $this->customer->id])
            ->sortTable('date', 'desc')
            ->filterTable('year', (string) now()->year);

        $next = Livewire::test(CustomerLedger::class, ['record' => $other->id])
            ->assertSet('tableSort', 'date:desc');

        $this->assertEmpty($next->get('tableFilters.year.value'), 'a year picked for one customer must not filter the next');
    }
}
