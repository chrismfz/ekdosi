<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\CashJournalReport;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Render + wiring smoke test for the «Ταμειακό ημερολόγιο» page: it boots, defaults
 * to the current month, and lists an account with its method breakdown + totals.
 */
class CashJournalReportPageTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-15 12:00:00'));

        $this->tenant = Company::create([
            'name' => 'CJ', 'slug' => 'cj-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'ΠΕΛΑΤΗΣ ΑΕ']);
        $method = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Κάρτα']);
        $account = BankAccount::create(['company_id' => $this->tenant->id, 'bank_name' => 'ΑΛΦΑ ΤΡΑΠΕΖΑ', 'iban' => 'GR123']);

        Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $customer->id,
            'kind' => 'payment', 'amount' => 248, 'payment_method_id' => $method->id,
            'bank_account_id' => $account->id, 'pay_date' => '2026-05-10',
        ]);

        $user = User::create(['name' => 'Op', 'email' => 'cj-'.uniqid().'@test.local', 'password' => bcrypt('x')]);
        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_page_renders_with_default_month_and_lists_the_account(): void
    {
        Livewire::test(CashJournalReport::class)
            ->assertOk()
            ->assertSet('from', '2026-05-01')
            ->assertSet('to', '2026-05-15')
            ->assertSee('Ταμειακό ημερολόγιο')
            ->assertSee('ΑΛΦΑ ΤΡΑΠΕΖΑ — GR123')
            ->assertSee('Κάρτα')
            ->assertSee('248,00');
    }

    public function test_period_without_movements_shows_the_empty_note(): void
    {
        Livewire::test(CashJournalReport::class)
            ->set('from', '2026-03-01')
            ->set('to', '2026-03-31')
            ->assertDontSee('ΑΛΦΑ ΤΡΑΠΕΖΑ')
            ->assertSee('Καμία ταμειακή κίνηση');
    }
}
