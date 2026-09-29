<?php

namespace Tests\Feature\Accounting;

use App\Filament\Pages\TaxOverview;
use App\Models\Company;
use App\Models\E3YearSnapshot;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Accounting\E3YearTotals;
use App\Services\Accounting\IncomeTaxEstimate;
use App\Services\Assistant\Tools\TaxOverviewTool;
use App\Support\Accounting\IncomeTaxProfile;
use Filament\Facades\Filament;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The running-year 31/12 projection PER CATEGORY ({@see IncomeTaxEstimate::project}) and
 * the warnings above the estimate. Today = 25/09/2026 (day 268 of 365).
 */
class ProjectionTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-25 10:00:00');

        $this->tenant = Company::create([
            'name' => 'Proj OE', 'slug' => 'pr-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox', 'afm' => '801280908',
            'mydata_aade_id_sandbox' => 'TESTUSER', 'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_monthly_payroll_is_projected_with_the_median_rate_and_a_christmas_bonus(): void
    {
        foreach (range(1, 7) as $m) {
            $this->expense('self_declared', 'payroll', '17.1', sprintf('2026-%02d-15', $m), 3000);
        }
        $this->expense('self_declared', 'payroll', '17.1', '2026-06-20', 3000);   // June: leave allowance → 6.000

        $p = (new IncomeTaxEstimate($this->tenant))->forYear(2026)['projection']['method']['personnel'];

        $this->assertSame('monthly_median', $p['method']);
        $this->assertEqualsWithDelta(3000.0, $p['monthly_rate'], 0.001);   // median(3000, 6000, 3000) — the bonus doesn't skew it
        $this->assertSame(5, $p['months_remaining']);                       // Aug → Dec
        $this->assertEqualsWithDelta(3000.0, $p['christmas_bonus'], 0.001);
        $this->assertEqualsWithDelta(24000 + 5 * 3000 + 3000, $p['value'], 0.001);
    }

    public function test_one_skipped_month_keeps_the_monthly_rate(): void
    {
        foreach ([1, 2, 3, 4, 6, 7] as $m) {   // the accountant skipped May
            $this->expense('self_declared', 'payroll', '17.1', sprintf('2026-%02d-15', $m), 3000);
        }

        $p = (new IncomeTaxEstimate($this->tenant))->forYear(2026)['projection']['method']['personnel'];

        $this->assertSame('monthly_median', $p['method']);
        $this->assertEqualsWithDelta(3000.0, $p['monthly_rate'], 0.001);
    }

    public function test_quarterly_payroll_is_averaged_since_january(): void
    {
        $this->expense('self_declared', 'payroll', '17.1', '2026-03-20', 9000);
        $this->expense('self_declared', 'payroll', '17.1', '2026-06-20', 9000);

        $p = (new IncomeTaxEstimate($this->tenant))->forYear(2026)['projection']['method']['personnel'];

        $this->assertSame('average_per_month', $p['method']);
        $this->assertEqualsWithDelta(3000.0, $p['monthly_rate'], 0.001);   // 18.000 / 6 months, not / 4
        $this->assertSame(6, $p['months_remaining']);
    }

    public function test_income_follows_last_years_pattern_and_depreciation_last_years_amount(): void
    {
        // 2025 (full-year Ε3): 1.000 a month, 9.000 in December; αποσβέσεις 5.000 at the close.
        $rows = [];
        foreach (range(1, 12) as $m) {
            $rows[] = [sprintf('2025-%02d-15', $m), 'category1_3', 'E3_561_001', $m === 12 ? 9000 : 1000];
        }
        $rows[] = ['2025-12-31', 'category2_8', 'E3_587', 5000];
        E3YearTotals::refresh($this->tenant, 2025, $this->e3($rows));
        $this->invoice('2026-05-15', 8833.33);

        $m = (new IncomeTaxEstimate($this->tenant))->forYear(2026)['projection']['method'];

        // By 25/09 last year had done 8×1.000 + 1.000×25/30 of 20.000 → 44,17%.
        $this->assertSame('seasonal', $m['income']['method']);
        $this->assertEqualsWithDelta(0.4417, $m['income']['share_done'], 0.0001);
        $this->assertEqualsWithDelta(8833.33 / 0.4417, $m['income']['value'], 0.5);
        $this->assertSame('previous_year', $m['depreciation']['method']);
        $this->assertEqualsWithDelta(5000.0, $m['depreciation']['value'], 0.001);
    }

    public function test_expected_partner_insurance_counts_only_while_none_has_appeared(): void
    {
        $this->tenant->forceFill(['income_tax_profile' => (new IncomeTaxProfile(expectedPartnerInsurance: 6000))->toArray()])->save();

        $e = (new IncomeTaxEstimate($this->tenant->fresh()))->forYear(2026);
        $this->assertEqualsWithDelta(6000.0, $e['projection']['method']['partner_insurance']['added'], 0.001);
        $this->assertStringNotContainsString('εισφορές εταίρων', implode(' ', $e['warnings']));

        // The accountant posts it → never added on top.
        E3YearTotals::refresh($this->tenant, 2026, $this->e3([['2026-09-10', 'category2_4', 'E3_585_007', 4500]]));
        $e = (new IncomeTaxEstimate($this->tenant->fresh()))->forYear(2026);
        $this->assertEqualsWithDelta(0.0, $e['projection']['method']['partner_insurance']['added'], 0.001);
        $this->assertEqualsWithDelta(4500.0, $e['projection']['method']['partner_insurance']['present'], 0.001);
    }

    public function test_warnings_name_the_missing_payroll_months_and_a_stale_e3(): void
    {
        foreach (range(1, 7) as $m) {
            $this->expense('self_declared', 'payroll', '17.1', sprintf('2026-%02d-15', $m), 3000);
        }
        E3YearTotals::refresh($this->tenant, 2026, $this->e3([['2026-03-10', 'category2_4', 'E3_585_016', 10]]));
        E3YearSnapshot::query()->where('company_id', $this->tenant->id)->update(['fetched_at' => now()->subDays(5)]);

        $w = implode(' | ', (new IncomeTaxEstimate($this->tenant))->forYear(2026)['warnings']);

        $this->assertStringContainsString('δεν έχει περαστεί ακόμα για Αύγουστος', $w);
        $this->assertStringContainsString('Το Ε3 ανανεώθηκε τελευταία στις 20/09/2026', $w);
        $this->assertStringContainsString('εισφορές εταίρων', $w);

        $this->assertSame($w, implode(' | ', (new TaxOverviewTool)->run($this->tenant, [])['warnings']));
    }

    public function test_the_page_explains_the_projection(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]));
        Filament::setTenant($this->tenant);
        foreach (range(1, 7) as $m) {
            $this->expense('self_declared', 'payroll', '17.1', sprintf('2026-%02d-15', $m), 3000);
        }

        Livewire::test(TaxOverview::class)
            ->assertSee('Πώς προβλέφθηκε η 31/12')
            ->assertSee('δώρο Χριστουγέννων')
            ->assertSee('δεν έχει περαστεί ακόμα για Αύγουστος');
    }

    private function expense(string $source, ?string $category, string $type, string $date, float $net): Expense
    {
        return Expense::create([
            'company_id' => $this->tenant->id, 'supplier_afm' => '123456789', 'supplier_name' => 'Χ',
            'source' => $source, 'category' => $category, 'invoice_type' => $type, 'issue_date' => $date,
            'net_total' => $net, 'vat_total' => 0, 'gross_total' => $net, 'currency' => 'EUR',
        ]);
    }

    private function invoice(string $date, float $net): Invoice
    {
        $type = InvoiceType::firstOrCreate(['company_id' => $this->tenant->id, 'code' => 'TPY'], ['name' => 'ΤΠΥ', 'invcount' => 1, 'mydata_type' => '2.1']);
        $method = PaymentMethod::firstOrCreate(['company_id' => $this->tenant->id, 'description' => 'Μετρητά'], ['due_days' => 0]);

        return Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'I'.uniqid(), 'code' => random_int(1, 99999),
            'invoice_type_id' => $type->id, 'payment_method_id' => $method->id,
            'issued_at' => $date.' 10:00:00', 'local_status' => 'active',
            'net_total' => $net, 'gross_total' => round($net * 1.24, 2), 'header_discount_percent' => 0,
        ]);
    }

    /** @param list<array{0:string,1:string,2:string,3:float}> $rows (issueDate, category, type, value) */
    private function e3(array $rows): MockHandler
    {
        $items = '';
        foreach ($rows as $i => [$date, $cat, $type, $value]) {
            $items .= sprintf(
                '<E3Info><V_Afm>801280908</V_Afm><V_Mark>4000000000%05d</V_Mark><IssueDate>%sT00:00:00</IssueDate>'
                .'<V_Class_Category>%s</V_Class_Category><V_Class_Type>%s</V_Class_Type><V_Class_Value>%.2f</V_Class_Value></E3Info>',
                $i + 1, $date, $cat, $type, $value,
            );
        }

        return new MockHandler([new Response(200, [], '<?xml version="1.0" encoding="utf-8"?><RequestedE3Info xmlns="http://www.aade.gr/myDATA/invoice/v1.0">'.$items.'</RequestedE3Info>')]);
    }
}
