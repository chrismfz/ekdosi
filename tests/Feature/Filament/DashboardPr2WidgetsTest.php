<?php

namespace Tests\Feature\Filament;

use App\Enums\LeadStatus;
use App\Enums\QuoteStatus;
use App\Filament\Widgets\LeadsStats;
use App\Filament\Widgets\TopSuppliersChart;
use App\Models\Company;
use App\Models\Expense;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\User;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * #8 dashboard widgets PR-2: «Κορυφαίοι προμηθευτές» (expense chart) + «Αξία
 * pipeline» stat στο LeadsStats. Οι νέες υπολογιστικές διαδρομές (top-by-net
 * suppliers, quote-derived pipeline value από ανοιχτά leads) είναι αυτό που
 * ελέγχεται εδώ.
 */
class DashboardPr2WidgetsTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-17 12:00:00'));

        $this->tenant = Company::create([
            'name' => 'W', 'slug' => 'w-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);

        $operator = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $this->tenant->users()->attach($operator->id);
        Gate::before(fn () => true);
        $this->actingAs($operator);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function expense(string $supplier, float $net, array $overrides = []): void
    {
        Expense::create(array_merge([
            'company_id' => $this->tenant->id,
            'source' => 'manual',
            'issue_date' => '2026-05-01',
            'supplier_name' => $supplier,
            'net_total' => $net,
            'vat_total' => 0,
            'gross_total' => $net,
        ], $overrides));
    }

    public function test_top_suppliers_chart_ranks_by_signed_net_excluding_cancelled(): void
    {
        $this->expense('ΑΛΦΑ ΕΠΕ', 300);
        $this->expense('ΑΛΦΑ ΕΠΕ', 100);                                  // same supplier → 400
        $this->expense('ΑΛΦΑ ΕΠΕ', 100, ['invoice_type' => '5.1']);       // credit note → −100 ⇒ 300
        $this->expense('ΒΗΤΑ ΑΕ', 500);
        $this->expense('ΒΗΤΑ ΑΕ', 1000, ['mydata_state' => 'CANCELLED']); // AADE-cancelled → excluded
        $this->expense('', 9999);                                         // no supplier identity → excluded

        Filament::setTenant($this->tenant);
        $data = (new TopSuppliersChart)->getData();

        $this->assertSame([500.0, 300.0], $data['datasets'][0]['data']);
        $this->assertSame(['ΒΗΤΑ ΑΕ', 'ΑΛΦΑ ΕΠΕ'], $data['labels']);
    }

    public function test_top_suppliers_chart_is_empty_without_a_tenant(): void
    {
        $this->assertSame(['datasets' => [], 'labels' => []], (new TopSuppliersChart)->getData());
    }

    public function test_pipeline_value_sums_quotes_of_open_leads_only(): void
    {
        $openLead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Ανοιχτό', 'status' => LeadStatus::Quoted]);
        // Two LIVE quotes on the same open lead (a revision) → only the LATEST
        // counts, not the sum (no double-count of one deal).
        Quote::create(['company_id' => $this->tenant->id, 'lead_id' => $openLead->id, 'code' => 'ΠΡ-1', 'gross_total' => 620, 'status' => QuoteStatus::Sent]);
        Quote::create(['company_id' => $this->tenant->id, 'lead_id' => $openLead->id, 'code' => 'ΠΡ-1β', 'gross_total' => 680, 'status' => QuoteStatus::Sent]);
        // A dead (rejected) quote on the same open lead must NOT inflate pipeline.
        Quote::create(['company_id' => $this->tenant->id, 'lead_id' => $openLead->id, 'code' => 'ΠΡ-3', 'gross_total' => 5000, 'status' => QuoteStatus::Rejected]);

        $wonLead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Κερδισμένο', 'status' => LeadStatus::Won]);
        Quote::create(['company_id' => $this->tenant->id, 'lead_id' => $wonLead->id, 'code' => 'ΠΡ-2', 'gross_total' => 999, 'status' => QuoteStatus::Sent]);

        Filament::setTenant($this->tenant);

        Livewire::test(LeadsStats::class)
            ->assertSee('Αξία pipeline')
            ->assertSee('680,00 €')          // the latest live quote of the open lead
            ->assertDontSee('1.300,00 €')    // NOT 620 + 680 (no double-count)
            ->assertDontSee('999,00 €')      // the won lead's quote is excluded
            ->assertDontSee('5.000,00 €');   // the rejected quote is excluded
    }
}
