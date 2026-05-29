<?php

namespace Tests\Feature\Dashboard;

use App\Filament\Widgets\PeriodIncomeStats;
use App\Models\Company;
use App\Models\InvoiceType;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The filter-driven "period" cards boot and re-read the dashboard period
 * filter (InteractsWithPageFilters → PeriodFilter). Verifies the wiring
 * end-to-end through the real Livewire widget without a browser.
 */
class PeriodIncomeStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-15 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_widget_renders_and_reflects_the_selected_period_label(): void
    {
        $tenant = Company::create([
            'name' => 'T', 'slug' => 'period-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $pm = PaymentMethod::create(['company_id' => $tenant->id, 'description' => 'Μ', 'due_days' => 0]);
        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ',
            'invcount' => 1, 'payment_method_id' => $pm->id,
        ]);
        // One sale this month, one last year — so month vs year differ.
        Invoice::create([
            'company_id' => $tenant->id, 'invcode' => 'A'.uniqid(), 'code' => 1,
            'invoice_type_id' => $type->id, 'issued_at' => '2026-05-10 10:00:00',
            'net_total' => 100, 'gross_total' => 124,
        ]);
        Invoice::create([
            'company_id' => $tenant->id, 'invcode' => 'B'.uniqid(), 'code' => 2,
            'invoice_type_id' => $type->id, 'issued_at' => '2025-07-10 10:00:00',
            'net_total' => 500, 'gross_total' => 620,
        ]);

        $user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@x.test', 'password' => bcrypt('x')]);
        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($tenant);

        // Default (this month) — label + the three period cards present.
        Livewire::test(PeriodIncomeStats::class, ['pageFilters' => ['period' => 'this_month']])
            ->assertOk()
            ->assertSee('Έσοδα περιόδου (καθαρά)')
            ->assertSee('Παραστατικά περιόδου')
            ->assertSee('Τρέχων μήνας');

        // Switch to the year preset → the label updates (reactivity wired).
        Livewire::test(PeriodIncomeStats::class, ['pageFilters' => ['period' => 'year']])
            ->assertOk()
            ->assertSee('2026')
            ->assertDontSee('Τρέχων μήνας');
    }
}
