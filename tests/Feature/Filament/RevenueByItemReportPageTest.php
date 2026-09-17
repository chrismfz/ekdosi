<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\RevenueByItemReport;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\User;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Render + wiring smoke test for the «Ισοζύγιο Ειδών/Υπηρεσιών» page: it boots,
 * defaults its year, and lists an item under its NORMALISED label (the dated
 * renewal parenthetical stripped) — the blade drill a service test can't cover.
 */
class RevenueByItemReportPageTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

        $this->tenant = Company::create([
            'name' => 'RBI', 'slug' => 'rbi-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΤΠΥ', 'name' => 'ΤΠΥ', 'invcount' => 1,
        ]);
        $customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'ΠΕΛΑΤΗΣ ΑΕ']);
        $invoice = Invoice::create([
            'company_id' => $this->tenant->id, 'customer_id' => $customer->id,
            'invoice_type_id' => $type->id, 'invcode' => 'ΤΠΥ1', 'code' => 1,
            'issued_at' => '2026-03-01 10:00:00', 'local_status' => 'active',
        ]);
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $invoice->id,
            'qty' => 1, 'price_per_item' => 480, 'vat_percent' => 24,
            'product_descr' => 'Cloud VPS 8GB (1/9/2026-31/8/2027)',
        ]);

        $user = User::create(['name' => 'Op', 'email' => 'rbi-'.uniqid().'@test.local', 'password' => bcrypt('x')]);
        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_page_renders_with_default_year_and_lists_the_normalised_item(): void
    {
        Livewire::test(RevenueByItemReport::class)
            ->assertOk()
            ->assertSet('year', 2026)
            ->assertSee('Ισοζύγιο Ειδών/Υπηρεσιών')
            ->assertSee('Cloud VPS 8GB')                       // normalised label
            ->assertDontSee('Cloud VPS 8GB (1/9/2026-31/8/2027)'); // NOT the dated raw text
    }

    public function test_a_year_without_sales_shows_the_empty_note(): void
    {
        Livewire::test(RevenueByItemReport::class)
            ->set('year', 2024)
            ->assertDontSee('Cloud VPS 8GB')
            ->assertSee('Δεν βρέθηκαν πωλήσεις ειδών');
    }

    public function test_display_rows_are_uncapped_below_the_limit_no_more_bucket(): void
    {
        $rows = Livewire::test(RevenueByItemReport::class)->instance()->displayRows();

        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]['is_more'] ?? false);
    }
}
