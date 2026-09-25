<?php

namespace Tests\Feature\Accounting;

use App\Filament\Pages\TaxOverview;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Accounting\IncomeTaxEstimate;
use App\Support\Accounting\IncomeTaxProfile;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Φορολογικά»: the income-tax estimate (φόρος / παρακρατήσεις / προκαταβολή /
 * υπόλοιπο, prior-year prepayment estimated or ΒΕΒΑΙΩΜΕΝΗ, running-year
 * projection) and the per-quarter ΦΠΑ with the πιστωτικό carried forward.
 *
 * Dates sit mid-period on purpose: sqlite stores a DATE cast as «Y-m-d 00:00:00»,
 * so a row ON the last day of a window would lexically fall outside it (MariaDB
 * truncates — prod is unaffected).
 */
class TaxOverviewTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $type;

    private PaymentMethod $method;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-02 10:00:00');

        $this->tenant = Company::create([
            'name' => 'Tax OE', 'slug' => 'tax-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->method = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Μετρητά', 'due_days' => 0]);
        $this->type = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1, 'mydata_type' => '2.1']);

        // 2025: sales 10.000 (+ 200 withheld), supplier 2.000 (Q1, input VAT 480),
        // payroll 3.000 (17.1), income adjustment 500 (17.3 — lands in expenses).
        $this->invoice('2025-05-10', 10000, 12400, withheld: 200);
        $this->expense('sync', null, null, '2025-02-10', 2000, 480);
        $this->expense('self_declared', 'payroll', '17.1', '2025-06-20', 3000);
        $this->expense('self_declared', 'adjustments', '17.3', '2025-04-15', 500);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_closed_year_settles_tax_withholding_and_prepayment(): void
    {
        $e = (new IncomeTaxEstimate($this->tenant))->forYear(2025);

        $this->assertEqualsWithDelta(10500.0, $e['income_total'], 0.001);      // 17.3 moved to income
        $this->assertEqualsWithDelta(500.0, $e['income_adjustments'], 0.001);
        $this->assertEqualsWithDelta(5000.0, $e['expense_total'], 0.001);      // supplier + payroll, NOT the 17.3
        $this->assertEqualsWithDelta(5500.0, $e['profit'], 0.001);
        $this->assertEqualsWithDelta(1210.0, $e['tax'], 0.001);                // 22%
        $this->assertEqualsWithDelta(200.0, $e['withheld'], 0.001);
        $this->assertEqualsWithDelta(768.0, $e['prepayment_next'], 0.001);     // 80% × 1210 − 200
        $this->assertSame('missing', $e['prior_source']);                      // nothing entered
        $this->assertNull($e['prior_hint']);                                   // no 2024 data to hint from
        $this->assertEqualsWithDelta(1778.0, $e['payable'], 0.001);            // 1210 − 200 + 768 − 0
        $this->assertEqualsWithDelta(1778.0, $e['headline_payable'], 0.001);   // closed year: its payable
        $this->assertNull($e['monthly_saving']);                               // no «save per month» for a closed year
        $this->assertNull($e['projection']);
        $this->assertNotContains('adjustments', array_column($e['expense_breakdown'], 'bucket'));
    }

    public function test_the_running_year_uses_last_years_prepayment_and_projects_to_year_end(): void
    {
        $this->invoice('2026-03-10', 5000, 6200);
        $svc = fn () => (new IncomeTaxEstimate($this->tenant->fresh()))->forYear(2026);

        $e = $svc();
        // Our estimate from 2025 is only a HINT — never subtracted (earlier years'
        // expenses may be missing, which would fake a refund).
        $this->assertSame('missing', $e['prior_source']);
        $this->assertEqualsWithDelta(0.0, $e['prior_prepayment'], 0.001);
        $this->assertEqualsWithDelta(768.0, $e['prior_hint'], 0.001);
        $this->assertEqualsWithDelta(1980.0, $e['payable'], 0.001);             // 1100 − 0 + 880 − 0
        $this->assertSame(183, $e['projection']['elapsed_days']);               // 1/1 → 2/7
        $this->assertEqualsWithDelta(round(5000 * 365 / 183, 2), $e['projection']['profit'], 0.02);
        // The headline is the PROJECTION (a full prior prepayment against a partial
        // year's tax would read as a fake refund), spread over Jul–Dec = 6 months.
        $this->assertEqualsWithDelta($e['projection']['payable'], $e['headline_payable'], 0.001);
        $this->assertEqualsWithDelta(round($e['projection']['payable'] / 6, 2), $e['monthly_saving'], 0.001);

        // The ΒΕΒΑΙΩΜΕΝΗ amount from the profile wins over our estimate.
        $this->tenant->forceFill(['income_tax_profile' => (new IncomeTaxProfile(22, 80, [2026 => 900]))->toArray()])->save();
        $e = $svc();
        $this->assertSame('assessed', $e['prior_source']);
        $this->assertEqualsWithDelta(1080.0, $e['payable'], 0.001);             // 1100 + 880 − 900
    }

    public function test_a_loss_owes_no_tax_and_the_prior_prepayment_comes_back(): void
    {
        $this->expense('sync', null, null, '2026-03-10', 4000, 960);            // loss year
        $e = (new IncomeTaxEstimate($this->tenant))->forYear(2026);

        $this->assertEqualsWithDelta(0.0, $e['tax'], 0.001);
        $this->assertEqualsWithDelta(0.0, $e['prepayment_next'], 0.001);
        $this->assertEqualsWithDelta(0.0, $e['payable'], 0.001);                // no guessed prepayment → no fake refund
        $this->assertEqualsWithDelta(0.0, $e['monthly_saving'], 0.001);

        // With the ΒΕΒΑΙΩΜΕΝΗ prepayment entered, the loss year gets it back.
        $this->tenant->forceFill(['income_tax_profile' => (new IncomeTaxProfile(22, 80, [2026 => 768]))->toArray()])->save();
        $this->assertEqualsWithDelta(-768.0, (new IncomeTaxEstimate($this->tenant->fresh()))->forYear(2026)['payable'], 0.001);
    }

    public function test_a_year_with_barely_any_expenses_is_flagged(): void
    {
        $this->invoice('2024-05-10', 50000, 62000);
        $this->expense('sync', null, null, '2024-05-12', 1000, 240);           // 2% of income → likely not imported

        $svc = new IncomeTaxEstimate($this->tenant);
        $this->assertTrue($svc->forYear(2024)['expense_warning']);
        $this->assertFalse($svc->forYear(2025)['expense_warning']);             // 5.000 of 10.500
    }

    public function test_no_projection_before_a_month_of_data(): void
    {
        Carbon::setTestNow('2026-01-10 10:00:00');
        $this->invoice('2026-01-05', 1000, 1240);

        $e = (new IncomeTaxEstimate($this->tenant))->forYear(2026);
        $this->assertNull($e['projection']);
        $this->assertNull($e['headline_payable']);                              // «λίγα δεδομένα», not a number
        $this->assertNull($e['monthly_saving']);
    }

    public function test_the_page_shows_vat_per_quarter_with_the_credit_carried_forward(): void
    {
        $this->actAsOperator();

        $page = Livewire::test(TaxOverview::class)
            ->assertOk()
            ->assertSet('year', 2026)
            ->assertSee('Εκτίμηση φόρου εισοδήματος')
            ->set('year', 2025)
            ->assertSee('Τρίμηνο 1')
            ->assertSee('Μάιος')
            ->assertSee('Μισθοδοσία');

        $vat = $page->instance()->vat();
        // Q1: only input VAT 480 → credit carried; Q2: output 2400 − carried 480.
        $this->assertEqualsWithDelta(0.0, $vat['quarters'][0]['payable'], 0.001);
        $this->assertEqualsWithDelta(480.0, $vat['quarters'][0]['carry_out'], 0.001);
        $this->assertEqualsWithDelta(480.0, $vat['quarters'][1]['carried_in'], 0.001);
        $this->assertEqualsWithDelta(1920.0, $vat['quarters'][1]['payable'], 0.001);
        $this->assertEqualsWithDelta(1920.0, $vat['payable_total'], 0.001);
        // Three DISTINCT months per quarter (monthsOfQuarter used to repeat the
        // first one when handed a CarbonImmutable), May holding the sale's VAT.
        $this->assertSame(['04/2025', '05/2025', '06/2025'], array_map(fn ($m) => $m->label, $vat['quarters'][1]['months']));
        $this->assertEqualsWithDelta(2400.0, $vat['quarters'][1]['months'][1]->outputVat, 0.001);
    }

    public function test_the_profile_action_saves_rates_and_assessed_prepayments(): void
    {
        $this->actAsOperator();

        Livewire::test(TaxOverview::class)
            ->callAction('tax_profile', data: [
                'rate' => 22,
                'prepayment_rate' => 40,
                'assessed' => [['year' => 2026, 'amount' => 900]],
            ])
            ->assertHasNoActionErrors();

        $p = IncomeTaxProfile::for($this->tenant->fresh());
        $this->assertSame(40.0, $p->prepaymentRate);
        $this->assertSame(900.0, $p->assessedPrepaymentFor(2026));
    }

    public function test_the_profile_rejects_the_same_year_twice(): void
    {
        $this->actAsOperator();

        Livewire::test(TaxOverview::class)
            ->callAction('tax_profile', data: [
                'rate' => 22,
                'prepayment_rate' => 80,
                'assessed' => [['year' => 2026, 'amount' => 900], ['year' => 2026, 'amount' => 1200]],
            ])
            ->assertHasActionErrors();

        $this->assertNull(IncomeTaxProfile::for($this->tenant->fresh())->assessedPrepaymentFor(2026));
    }

    public function test_the_page_is_gated(): void
    {
        $this->actingAs(User::create(['name' => 'No', 'email' => 'no-'.uniqid().'@t.local', 'password' => bcrypt('x')]));
        Filament::setTenant($this->tenant);

        $this->assertFalse(TaxOverview::canAccess());
        $this->assertFalse(TaxOverview::canEditProfile());
    }

    public function test_the_page_is_greek_tenants_only(): void
    {
        $this->actAsOperator();
        $ee = Company::create(['name' => 'EE OU', 'slug' => 'ee-'.uniqid(), 'country_code' => 'EE']);
        Filament::setTenant($ee);

        $this->assertFalse(TaxOverview::canAccess());                          // Greek tax model, not Estonian
    }

    private function actAsOperator(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]));
        Filament::setTenant($this->tenant);
    }

    private function invoice(string $date, float $net, float $gross, float $withheld = 0): Invoice
    {
        $c = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Πελ '.uniqid(), 'afm' => (string) random_int(100000000, 999999999)]);

        return Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'I'.uniqid(), 'code' => random_int(1, 99999),
            'invoice_type_id' => $this->type->id, 'payment_method_id' => $this->method->id, 'customer_id' => $c->id,
            'issued_at' => $date.' 10:00:00', 'local_status' => 'active',
            'net_total' => $net, 'gross_total' => $gross, 'header_discount_percent' => 0,
            'withhold_amount' => $withheld ?: null,
        ]);
    }

    private function expense(string $source, ?string $category, ?string $type, string $date, float $net, float $vat = 0): Expense
    {
        return Expense::create([
            'company_id' => $this->tenant->id, 'supplier_afm' => '123456789', 'supplier_name' => 'Χ',
            'source' => $source, 'category' => $category, 'invoice_type' => $type, 'issue_date' => $date,
            'net_total' => $net, 'vat_total' => $vat, 'gross_total' => $net + $vat, 'currency' => 'EUR',
        ]);
    }
}
