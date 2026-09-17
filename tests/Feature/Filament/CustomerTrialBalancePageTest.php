<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\CustomerTrialBalance;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\User;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Render + wiring smoke test for the «Ισοζύγιο Πελατών» page: it boots, defaults
 * its period, and lists a customer with a balance (the row's blade drill / totals
 * that a service-level test can't cover).
 */
class CustomerTrialBalancePageTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

        $this->tenant = Company::create([
            'name' => 'TBP', 'slug' => 'tbp-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΤΠΥ', 'name' => 'ΤΠΥ', 'invcount' => 1,
        ]);
        $creditTerm = PaymentMethod::create([
            'company_id' => $this->tenant->id, 'description' => 'Επί πιστώσει', 'due_days' => 30,
        ]);
        $customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'ΒΑΚΑΣ ΑΕ']);
        Invoice::create([
            'company_id' => $this->tenant->id, 'customer_id' => $customer->id,
            'invoice_type_id' => $type->id, 'payment_method_id' => $creditTerm->id,
            'invcode' => 'ΤΠΥ1', 'code' => 1, 'issued_at' => '2026-03-01 10:00:00',
            'net_total' => 124, 'gross_total' => 124, 'payable_total' => 124, 'local_status' => 'active',
        ]);

        $user = User::create(['name' => 'Op', 'email' => 'tbp-'.uniqid().'@test.local', 'password' => bcrypt('x')]);
        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_page_renders_with_default_period_and_lists_a_debtor(): void
    {
        Livewire::test(CustomerTrialBalance::class)
            ->assertOk()
            ->assertSet('from', '2026-01-01')
            ->assertSet('to', '2026-06-15')
            ->assertSee('ΒΑΚΑΣ ΑΕ')
            ->assertSee('Ισοζύγιο Πελατών');
    }

    public function test_period_before_the_invoice_shows_no_rows(): void
    {
        Livewire::test(CustomerTrialBalance::class)
            ->set('from', '2025-01-01')
            ->set('to', '2025-12-31')
            ->assertDontSee('ΒΑΚΑΣ ΑΕ')
            ->assertSee('Καμία κίνηση');
    }
}
