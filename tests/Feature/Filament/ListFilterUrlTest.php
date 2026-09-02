<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\LeadsCalendar;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Lead;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\TableFilterUrl;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Drill-down links must actually filter the list they open.
 *
 * `ListRecords` binds the filter state as `#[Url(as: 'filters')]`, so a
 * `?tableFilters[...]` URL binds to NOTHING and the list lands unfiltered —
 * silently. Four links shipped that way (dashboard «Ανεξόφλητα» + «Πρόχειρα»,
 * the overdue-invoice notification, the leads-calendar banner). `TableFilterUrl`
 * is now the single place that knows the key; these tests drive the REAL URL
 * builders end-to-end (build the URL → replay its query string through Livewire
 * → assert the rows), so a wrong key can't pass again.
 */
class ListFilterUrlTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);

        $this->tenant = Company::create([
            'name' => 'Φ', 'slug' => 'f-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'afm' => '800000000',
        ]);
        $this->user = User::create(['name' => 'U', 'email' => 'u-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $this->tenant->users()->attach($this->user);
        $this->actingAs($this->user);
        Filament::setTenant($this->tenant);
    }

    /** The query params of a URL a resource's getUrl() produced. */
    private function paramsOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

        return $params;
    }

    public function test_the_key_is_the_bound_one_and_the_property_name_is_not(): void
    {
        Customer::create(['company_id' => $this->tenant->id, 'name' => 'ΑΛΦΑ']);

        $state = fn (array $query): mixed => data_get(
            Livewire::withQueryParams($query)->test(ListCustomers::class)->get('tableFilters'),
            'balance_status.value',
        );

        $this->assertSame('debtor', $state(TableFilterUrl::with(['balance_status' => ['value' => 'debtor']])));
        $this->assertNull(
            $state(['tableFilters' => ['balance_status' => ['value' => 'debtor']]]),
            'the property name is NOT the query-string key — this is the bug TableFilterUrl exists to prevent',
        );
    }

    public function test_the_dashboard_debtors_card_opens_a_filtered_customer_list(): void
    {
        $owing = Customer::create(['company_id' => $this->tenant->id, 'name' => 'ΧΡΕΩΣΤΗΣ']);
        $settled = Customer::create(['company_id' => $this->tenant->id, 'name' => 'ΕΝΤΑΞΕΙ']);
        $this->invoice($owing, 100);

        // The URL the widget builds, replayed exactly.
        $url = CustomerResource::getUrl('index', TableFilterUrl::with(
            ['balance_status' => ['value' => 'debtor']],
            ['sort' => 'outstanding_balance:desc'],
        ));

        Livewire::withQueryParams($this->paramsOf($url))
            ->test(ListCustomers::class)
            ->assertCanSeeTableRecords([$owing])
            ->assertCanNotSeeTableRecords([$settled]);
    }

    public function test_the_dashboard_drafts_card_opens_a_filtered_invoice_list(): void
    {
        $customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'ΠΕΛΑΤΗΣ']);
        $draft = $this->invoice($customer, 50, 'draft');
        $active = $this->invoice($customer, 60, 'active');

        $url = InvoiceResource::getUrl('index', TableFilterUrl::with(['local_status' => ['value' => 'draft']]));

        Livewire::withQueryParams($this->paramsOf($url))
            ->test(ListInvoices::class)
            ->assertCanSeeTableRecords([$draft])
            ->assertCanNotSeeTableRecords([$active]);
    }

    public function test_the_leads_calendar_banner_link_carries_its_operator_filter(): void
    {
        $other = User::create(['name' => 'Άλλος', 'email' => 'o-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $this->tenant->users()->attach($other);

        // A past next step, so both leads land in the «open» AND the «overdue» tab.
        $mine = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Δικό μου',
            'assigned_user_id' => $this->user->id, 'next_action_at' => now()->subDay()]);
        $theirs = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Άλλου',
            'assigned_user_id' => $other->id, 'next_action_at' => now()->subDay()]);

        $page = Livewire::test(LeadsCalendar::class)->set('operator', (string) $this->user->id);

        // BOTH banner links — the overdue one was operator-blind under an
        // operator-filtered count.
        foreach (['open', 'overdue'] as $tab) {
            Livewire::withQueryParams($this->paramsOf($page->instance()->leadsListUrl($tab)))
                ->test(ListLeads::class)
                ->assertCanSeeTableRecords([$mine])
                ->assertCanNotSeeTableRecords([$theirs]);
        }

        // No operator selected → no filter param at all (everyone's leads).
        $page->set('operator', '');
        $this->assertArrayNotHasKey(TableFilterUrl::KEY, $this->paramsOf($page->instance()->leadsListUrl('open')));
    }

    private function invoice(Customer $customer, float $gross, string $status = 'active'): Invoice
    {
        $type = InvoiceType::firstOrCreate(
            ['company_id' => $this->tenant->id, 'code' => 'TPY'],
            ['name' => 'Τιμολόγιο', 'invcount' => 1],
        );

        // due_days > 0 — a cash-term invoice is settled at issue and never counts
        // toward the balance (GET_CUSTOMER_BALANCE semantics, see CLAUDE.md).
        $method = PaymentMethod::firstOrCreate(
            ['company_id' => $this->tenant->id, 'name' => 'Επί πιστώσει'],
            ['due_days' => 30],
        );

        return Invoice::create([
            'company_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'invoice_type_id' => $type->id,
            'invcode' => 'TPY'.random_int(10000, 99999),
            'code' => random_int(1000, 9999),
            'issued_at' => now(),
            'net_total' => $gross,
            'vat_total' => 0,
            'gross_total' => $gross,
            'local_status' => $status,
            'payment_method_id' => $method->id,
        ]);
    }
}
