<?php

namespace Tests\Feature\Accounting;

use App\Filament\Pages\TaxOverview;
use App\Models\Company;
use App\Models\E3YearSnapshot;
use App\Models\Expense;
use App\Models\User;
use App\Services\Accounting\E3YearTotals;
use App\Services\Accounting\IncomeTaxEstimate;
use App\Services\Accounting\MonthlyResult;
use Filament\Facades\Filament;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Φορολογικά» έσοδα/έξοδα ανά μήνα → τρίμηνο → έτος ({@see MonthlyResult})
 * and the running-year blend with the Ε3 it shares with the income-tax estimate.
 * Dates sit mid-month (sqlite DATE-cast caveat, see TaxOverviewTest).
 */
class MonthlyResultTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-25 10:00:00');

        $this->tenant = Company::create([
            'name' => 'Monthly OE', 'slug' => 'mon-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox', 'afm' => '801280908',
            'mydata_aade_id_sandbox' => 'TESTUSER', 'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_running_year_blends_each_month_and_the_estimate_reads_the_same_total(): void
    {
        $this->expense('sync', null, '2026-03-10', 2000);   // March: local suppliers 2.000
        $this->expense('sync', null, '2026-05-12', 1000);   // May:   local suppliers 1.000
        E3YearTotals::refresh($this->tenant, 2026, $this->e3([
            ['2026-03-31', 'category2_6', 'E3_581_001', 3000],  // payroll — only the Ε3 has it
            ['2026-03-10', 'category2_4', 'E3_585_016', 1000],  // March suppliers classified so far (< local)
            ['2026-05-12', 'category2_4', 'E3_585_016', 1500],  // May: a foreign invoice the accountant filed (> local)
            ['2026-06-30', 'category2_4', 'E3_585_007', 500],   // partners' ΕΦΚΑ (E3_585_007 → contributions)
        ]));

        $e = (new IncomeTaxEstimate($this->tenant))->forYear(2026);
        $m = $e['monthly'];

        $this->assertSame('blend', $m['mode']);
        $this->assertEqualsWithDelta(2000.0, $m['months'][3]['rest'], 0.001);
        $this->assertFalse($m['months'][3]['from_e3']['rest']);
        $this->assertEqualsWithDelta(3000.0, $m['months'][3]['payroll'], 0.001);
        $this->assertTrue($m['months'][3]['from_e3']['payroll']);
        $this->assertEqualsWithDelta(1500.0, $m['months'][5]['rest'], 0.001);
        $this->assertTrue($m['months'][5]['from_e3']['rest']);
        $this->assertEqualsWithDelta(500.0, $m['months'][6]['contributions'], 0.001);
        $this->assertCount(9, $m['months']);                                   // Jan → Sep (running year)
        $this->assertEqualsWithDelta(5000.0, $m['quarters'][1]['cell']['expense'], 0.001);   // March 2.000 + 3.000
        $this->assertEqualsWithDelta(2000.0, $m['quarters'][2]['cell']['expense'], 0.001);   // May 1.500 + June 500
        $this->assertSame([7, 8, 9], array_keys($m['quarters'][3]['months']));

        // Year = 2.000 + 3.000 + 1.500 + 500; the estimate uses exactly that.
        $this->assertEqualsWithDelta(7000.0, $m['year']['expense'], 0.001);
        $this->assertEqualsWithDelta(7000.0, $e['expense_total'], 0.001);
        $rest = collect($e['expense_blend'])->firstWhere('key', 'rest');
        $this->assertSame('mixed', $rest['source']);                          // local in March, Ε3 in May
    }

    public function test_personnel_is_compared_as_one_group(): void
    {
        // Our 17.1 already carries the employer contributions the Ε3 books apart as
        // E3_581_002 — compared separately, the 600 would be counted twice.
        $this->expense('self_declared', 'payroll', '2026-03-15', 3000, '17.1');
        E3YearTotals::refresh($this->tenant, 2026, $this->e3([
            ['2026-03-15', 'category2_6', 'E3_581_001', 2400],
            ['2026-03-15', 'category2_6', 'E3_581_002', 600],
        ]));

        $e = (new IncomeTaxEstimate($this->tenant))->forYear(2026);

        $this->assertEqualsWithDelta(3000.0, $e['expense_total'], 0.001);
        $this->assertSame('local', $e['source']);

        // Same total, but the SPLIT is the accountant's official one from the Ε3.
        $march = $e['monthly']['months'][3];
        $this->assertEqualsWithDelta(2400.0, $march['payroll'], 0.001);
        $this->assertEqualsWithDelta(600.0, $march['contributions'], 0.001);
        $this->assertTrue($march['from_e3']['contributions']);
        $this->assertFalse($march['from_e3']['payroll']);
    }

    public function test_a_closed_year_takes_every_month_from_the_e3(): void
    {
        $this->expense('sync', null, '2025-02-10', 999);    // local — ignored for a closed year with a full Ε3
        E3YearTotals::refresh($this->tenant, 2025, $this->e3([
            ['2025-01-15', 'category1_3', 'E3_561_001', 10000],
            ['2025-04-15', 'category2_6', 'E3_581_001', 3000],
            ['2025-11-15', 'category2_7', 'E3_882_002', 5000],  // αγορά παγίου — shown, not deducted
        ]));

        $e = (new IncomeTaxEstimate($this->tenant))->forYear(2025);
        $m = $e['monthly'];

        $this->assertSame('e3', $e['source']);
        $this->assertCount(12, $m['months']);
        $this->assertEqualsWithDelta(10000.0, $m['months'][1]['income'], 0.001);
        $this->assertEqualsWithDelta(3000.0, $m['months'][4]['payroll'], 0.001);
        $this->assertEqualsWithDelta(5000.0, $m['months'][11]['capex'], 0.001);
        $this->assertEqualsWithDelta(7000.0, $m['year']['result'], 0.001);
        // The table's year == the estimate's Ε3 figures.
        $this->assertEqualsWithDelta($e['income_total'], $m['year']['income'], 0.001);
        $this->assertEqualsWithDelta($e['expense_total'], $m['year']['expense'], 0.001);
    }

    public function test_a_snapshot_without_a_month_split_falls_back_but_the_year_still_matches(): void
    {
        $this->expense('sync', null, '2026-03-10', 2000);
        E3YearTotals::refresh($this->tenant, 2026, $this->e3([['2026-03-31', 'category2_6', 'E3_581_001', 3000]]));
        E3YearSnapshot::query()->where('company_id', $this->tenant->id)->update(['monthly' => null]);   // fetched before the column

        $e = (new IncomeTaxEstimate($this->tenant))->forYear(2026);
        $m = $e['monthly'];

        $this->assertFalse($m['monthly_available']);
        $this->assertEqualsWithDelta(0.0, $m['months'][3]['payroll'], 0.001);   // months: local only
        $this->assertEqualsWithDelta(5000.0, $m['year']['expense'], 0.001);     // year: blended once
        $this->assertEqualsWithDelta(5000.0, $e['expense_total'], 0.001);
    }

    public function test_the_page_shows_the_table(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]));
        Filament::setTenant($this->tenant);
        $this->expense('sync', null, '2026-03-10', 2000);
        E3YearTotals::refresh($this->tenant, 2026, $this->e3([['2026-03-31', 'category2_6', 'E3_581_001', 3000]]));

        Livewire::test(TaxOverview::class)
            ->assertSee('Έσοδα / έξοδα ανά τρίμηνο και μήνα')
            ->assertSee('Εισφορές (εργοδοτικές, ΕΦΚΑ)')
            ->assertSee('Σεπτέμβριος')
            ->assertSee('= από το Ε3');
    }

    private function expense(string $source, ?string $category, string $date, float $net, ?string $type = '1.1'): Expense
    {
        return Expense::create([
            'company_id' => $this->tenant->id, 'supplier_afm' => '123456789', 'supplier_name' => 'Χ',
            'source' => $source, 'category' => $category, 'invoice_type' => $type, 'issue_date' => $date,
            'net_total' => $net, 'vat_total' => 0, 'gross_total' => $net, 'currency' => 'EUR',
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
