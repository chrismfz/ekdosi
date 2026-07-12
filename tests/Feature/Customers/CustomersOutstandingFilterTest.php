<?php

namespace Tests\Feature\Customers;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\Payment;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Customers-list "Υπόλοιπο" column + the balance_status filter
 * (χρεωστικοί/πιστωτικοί/μηδενικό) + the open-drafts filter — the debtor
 * option is the drill-down target of the dashboard's "Ανεξόφλητα" card.
 * Boots the real Filament list page so the modifyQueryUsing() balance
 * join, the aliased sortable column, and the filter queries all run
 * through the actual table builder (not just a bare query).
 */
class CustomersOutstandingFilterTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;
    private PaymentMethod $credit;
    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'T', 'slug' => 'cust-bal-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->credit = PaymentMethod::create([
            'company_id' => $this->tenant->id, 'description' => 'Πίστωση', 'due_days' => 30,
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ',
            'invcount' => 1, 'payment_method_id' => $this->credit->id,
        ]);

        $user = User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('x'),
        ]);
        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($this->tenant);
    }

    private function debtor(): Customer
    {
        $c = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Χρωστάει', 'is_active' => true]);
        Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'D'.uniqid(), 'code' => 1,
            'invoice_type_id' => $this->type->id, 'customer_id' => $c->id,
            'payment_method_id' => $this->credit->id, 'issued_at' => now(),
            'net_total' => 200, 'gross_total' => 248, 'local_status' => 'active',
        ]);

        return $c;
    }

    private function settled(): Customer
    {
        $c = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Τακτοποιημένος', 'is_active' => true]);
        Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'S'.uniqid(), 'code' => 2,
            'invoice_type_id' => $this->type->id, 'customer_id' => $c->id,
            'payment_method_id' => $this->credit->id, 'issued_at' => now(),
            'net_total' => 100, 'gross_total' => 124, 'local_status' => 'active',
        ]);
        Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $c->id,
            'pay_date' => now(), 'amount' => 124,
        ]);

        return $c;
    }

    /** Negative balance: an on-account payment with no invoice → they have credit. */
    private function creditor(): Customer
    {
        $c = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Πιστωτικός', 'is_active' => true]);
        Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $c->id,
            'pay_date' => now(), 'amount' => 150,
        ]);

        return $c;
    }

    /** Holds one unissued sale draft (πρόχειρο) — not counted in the balance (MON-5). */
    private function draftHolder(): Customer
    {
        $c = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Πρόχειρο', 'is_active' => true]);
        Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'DR'.uniqid(), 'code' => 3,
            'invoice_type_id' => $this->type->id, 'customer_id' => $c->id,
            'payment_method_id' => $this->credit->id, 'issued_at' => now(),
            'net_total' => 50, 'gross_total' => 62, 'local_status' => 'draft',
        ]);

        return $c;
    }

    public function test_list_renders_with_balance_column_and_shows_both_by_default(): void
    {
        $debtor = $this->debtor();
        $settled = $this->settled();

        Livewire::test(ListCustomers::class)
            ->assertOk()
            ->loadTable()
            ->assertCanSeeTableRecords([$debtor, $settled])
            ->assertTableColumnExists('outstanding_balance');
    }

    public function test_balance_filter_debtor_keeps_only_who_owes_us(): void
    {
        $debtor = $this->debtor();
        $settled = $this->settled();
        $creditor = $this->creditor();

        Livewire::test(ListCustomers::class)
            ->loadTable()
            ->filterTable('balance_status', 'debtor')
            ->assertCanSeeTableRecords([$debtor])
            ->assertCanNotSeeTableRecords([$settled, $creditor]);
    }

    public function test_balance_filter_creditor_keeps_only_who_has_credit(): void
    {
        $debtor = $this->debtor();
        $settled = $this->settled();
        $creditor = $this->creditor();

        Livewire::test(ListCustomers::class)
            ->loadTable()
            ->filterTable('balance_status', 'creditor')
            ->assertCanSeeTableRecords([$creditor])
            ->assertCanNotSeeTableRecords([$debtor, $settled]);
    }

    public function test_open_drafts_filter_keeps_only_customers_with_a_draft(): void
    {
        $withDraft = $this->draftHolder();
        $debtor = $this->debtor();  // issued, not a draft

        Livewire::test(ListCustomers::class)
            ->loadTable()
            ->filterTable('has_open_drafts', true)
            ->assertCanSeeTableRecords([$withDraft])
            ->assertCanNotSeeTableRecords([$debtor]);
    }

    public function test_balance_column_is_sortable_without_sql_error(): void
    {
        $this->debtor();
        $this->settled();

        Livewire::test(ListCustomers::class)
            ->loadTable()
            ->sortTable('outstanding_balance', 'desc')
            ->assertOk();
    }

    public function test_drilldown_url_carries_the_debtor_balance_filter(): void
    {
        // The «Ανεξόφλητα» dashboard card links here via
        // CustomerResource::getUrl('index', ['tableFilters' => [...]]).
        // Pin the generated URL's query-string shape: it MUST match the
        // tableFilters[balance_status][value]=debtor state the SelectFilter
        // above consumes (proven by the filterTable tests). Together they cover
        // the card→filter contract. (The actual query-string→filter hydration is
        // Filament-internal and only exercisable in a real browser — the
        // headless harness can't drive it; deferFilters(false) + the sort param
        // are what let the landed URL apply immediately.)
        $url = CustomerResource::getUrl('index', [
            'tableFilters' => ['balance_status' => ['value' => 'debtor']],
        ]);

        $this->assertStringContainsString('tableFilters%5Bbalance_status%5D%5Bvalue%5D=debtor', $url);
    }
}
