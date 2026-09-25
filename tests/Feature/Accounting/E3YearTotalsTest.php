<?php

namespace Tests\Feature\Accounting;

use App\Console\Commands\MyDataE3Snapshot;
use App\Filament\Pages\TaxOverview;
use App\Models\Company;
use App\Models\E3YearSnapshot;
use App\Models\User;
use App\Services\Accounting\E3YearTotals;
use App\Services\Accounting\IncomeTaxEstimate;
use App\Services\MyData\E3ReportRow;
use Filament\Facades\Filament;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ε3 (AADE's final classification) as the source of the «Φορολογικά» estimate
 * for closed years: the totals rule, the per-year snapshot, the page action and
 * the CLI back-fill.
 */
class E3YearTotalsTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-25 10:00:00');

        $this->tenant = Company::create([
            'name' => 'E3 OE', 'slug' => 'e3y-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox', 'afm' => '801280908',
            'mydata_aade_id_sandbox' => 'TESTUSER', 'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);
    }

    protected function tearDown(): void
    {
        TaxOverview::$testHandler = null;
        MyDataE3Snapshot::$testHandler = null;
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_totals_rule_reproduces_the_accountants_result_on_real_data(): void
    {
        // myip 2025, AADE RequestE3Info aggregated (category, type, value) — real figures.
        // The 98.000 «17.5» re-booked by the accountant as αγορά παγίου (category2_7 E3_882_002).
        $t = E3YearTotals::fromRows($this->rows($this->myip2025()));

        $this->assertEqualsWithDelta(163813.02, $t['income'], 0.001);
        $this->assertEqualsWithDelta(173270.72, $t['expense'], 0.001);
        $this->assertEqualsWithDelta(100196.29, $t['capex'], 0.001);
        $this->assertEqualsWithDelta(-9457.70, $t['income'] - $t['expense'], 0.001);
    }

    public function test_third_party_informational_and_closing_stock_rules(): void
    {
        $t = E3YearTotals::fromRows($this->rows([
            ['category1_3', 'E3_561_001', 1000], ['category1_7', 'E3_881_001', 500], ['category1_95', 'E3_596', 70],
            ['category2_4', 'E3_585_016', 300], ['category2_9', 'E3_585_016', 200], ['category2_95', 'E3_598_001', 40],
            ['category2_13', 'E3_201', 100], ['category2_14', 'E3_201', 60],     // opening +, closing −
            ['category2_12', 'E3_883_001', 25],                                  // intangible asset in another category
        ]));

        $this->assertEqualsWithDelta(1000.0, $t['income'], 0.001);            // not τρίτων / πληροφοριακά
        $this->assertEqualsWithDelta(340.0, $t['expense'], 0.001);            // 300 + 100 − 60
        $this->assertEqualsWithDelta(25.0, $t['capex'], 0.001);
    }

    public function test_a_closed_year_uses_the_e3_snapshot_and_keeps_local_as_cross_check(): void
    {
        E3YearTotals::refresh($this->tenant, 2025, $this->e3Mock([['category1_3', 'E3_561_001', 20000], ['category2_4', 'E3_585_016', 8000], ['category2_7', 'E3_882_001', 5000]]));

        $e = (new IncomeTaxEstimate($this->tenant))->forYear(2025);
        $this->assertSame('e3', $e['source']);
        $this->assertEqualsWithDelta(20000.0, $e['income_total'], 0.001);
        $this->assertEqualsWithDelta(8000.0, $e['expense_total'], 0.001);
        $this->assertEqualsWithDelta(5000.0, $e['capex'], 0.001);
        $this->assertEqualsWithDelta(12000.0, $e['profit'], 0.001);
        $this->assertEqualsWithDelta(2640.0, $e['tax'], 0.001);                // 22%
        $this->assertEqualsWithDelta(0.0, $e['local']['income'], 0.001);      // nothing local → cross-check shows it
        $this->assertFalse($e['expense_warning']);                            // the Ε3 is the accountant's own figure
    }

    public function test_a_mid_year_snapshot_never_becomes_the_final_figure(): void
    {
        // Fetched on 25/09/2025 (through = today) → once 2025 closes it is NOT the source.
        Carbon::setTestNow('2025-09-25 10:00:00');
        E3YearTotals::refresh($this->tenant, 2025, $this->e3Mock([['category1_3', 'E3_561_001', 1000]]));
        Carbon::setTestNow('2026-02-01 10:00:00');

        $e = (new IncomeTaxEstimate($this->tenant))->forYear(2025);
        $this->assertSame('local', $e['source']);
        $this->assertFalse($e['e3']['full_year']);
        $this->assertSame('2025-09-25', $e['e3']['through']);

        // Fetched ON 31/12 (window reaches 31/12, but the year wasn't over) → still not final.
        Carbon::setTestNow('2025-12-31 10:00:00');
        E3YearTotals::refresh($this->tenant, 2025, $this->e3Mock([['category1_3', 'E3_561_001', 1000]]));
        Carbon::setTestNow('2026-02-01 10:00:00');
        $this->assertSame('local', (new IncomeTaxEstimate($this->tenant))->forYear(2025)['source']);
    }

    public function test_capex_without_a_category_and_review_flags(): void
    {
        $t = E3YearTotals::fromRows([new E3ReportRow('E3_882_001', null, 300.0, 1)]);
        $this->assertEqualsWithDelta(300.0, $t['capex'], 0.001);             // asset purchase, whatever the category

        $flags = E3YearTotals::reviewFlags([
            ['type' => 'E3_880_001', 'category' => 'category1_4', 'value' => 5000.0, 'count' => 1],
            ['type' => 'E3_585_016', 'category' => 'category2_4', 'value' => 10.0, 'count' => 1],
        ]);
        $this->assertSame(['Πώληση παγίων (φορολογείται μόνο το κέρδος)' => 5000.0], $flags);
    }

    public function test_the_running_year_stays_local_even_with_a_snapshot(): void
    {
        E3YearTotals::refresh($this->tenant, 2026, $this->e3Mock([['category1_3', 'E3_561_001', 9999]]));

        $e = (new IncomeTaxEstimate($this->tenant))->forYear(2026);
        $this->assertSame('local', $e['source']);                             // Ε3 lags (posted per quarter)
        $this->assertEqualsWithDelta(9999.0, $e['e3']['income'], 0.001);     // shown as cross-check
        $this->assertSame('2026-09-25', $e['e3']['through']);                 // running year → up to today
    }

    public function test_refresh_overwrites_the_years_snapshot(): void
    {
        E3YearTotals::refresh($this->tenant, 2025, $this->e3Mock([['category1_3', 'E3_561_001', 100]]));
        E3YearTotals::refresh($this->tenant, 2025, $this->e3Mock([['category1_3', 'E3_561_001', 250]]));

        $this->assertSame(1, E3YearSnapshot::query()->where('company_id', $this->tenant->id)->count());
        $this->assertEqualsWithDelta(250.0, (float) E3YearSnapshot::query()->where('company_id', $this->tenant->id)->value('income'), 0.001);
    }

    public function test_the_page_action_fetches_the_selected_year(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]));
        Filament::setTenant($this->tenant);
        TaxOverview::$testHandler = $this->e3Mock([['category1_3', 'E3_561_001', 5000], ['category2_4', 'E3_585_016', 1000]]);

        Livewire::test(TaxOverview::class)
            ->set('year', 2025)
            ->callAction('refresh_e3')
            ->assertSee('Πηγή: Ε3 ΑΑΔΕ');

        $snap = E3YearSnapshot::query()->where('company_id', $this->tenant->id)->where('year', 2025)->first();
        $this->assertEqualsWithDelta(5000.0, (float) $snap->income, 0.001);
    }

    public function test_the_cli_backfills_years(): void
    {
        MyDataE3Snapshot::$testHandler = $this->e3Mock([['category1_3', 'E3_561_001', 700]]);

        $this->artisan('mydata:e3-snapshot', ['--tenant' => $this->tenant->slug, '--year' => ['2024']])
            ->expectsOutputToContain('αποθηκεύτηκε το Ε3')
            ->assertSuccessful();
        $this->assertSame(1, E3YearSnapshot::query()->where('company_id', $this->tenant->id)->where('year', 2024)->count());

        $this->artisan('mydata:e3-snapshot', ['--tenant' => $this->tenant->slug, '--year' => ['2024,2025']])->assertFailed();
    }

    /** @param list<array{0:string,1:string,2:float}> $rows */
    private function rows(array $rows): array
    {
        return array_map(fn ($r) => new E3ReportRow($r[1], $r[0], (float) $r[2], 1), $rows);
    }

    /** One RequestE3Info page carrying the given (category, type, value) entries. */
    private function e3Mock(array $rows): MockHandler
    {
        $items = '';
        foreach ($rows as $i => [$cat, $type, $value]) {
            $items .= sprintf(
                '<E3Info><V_Afm>801280908</V_Afm><V_Mark>4000000000%05d</V_Mark><IssueDate>2025-03-01T00:00:00</IssueDate>'
                .'<V_Class_Category>%s</V_Class_Category><V_Class_Type>%s</V_Class_Type><V_Class_Value>%.2f</V_Class_Value></E3Info>',
                $i + 1, $cat, $type, $value,
            );
        }

        return new MockHandler([new Response(200, [], '<?xml version="1.0" encoding="utf-8"?><RequestedE3Info xmlns="http://www.aade.gr/myDATA/invoice/v1.0">'.$items.'</RequestedE3Info>')]);
    }

    /** @return list<array{0:string,1:string,2:float}> */
    private function myip2025(): array
    {
        return [
            ['category1_10', 'E3_562', 400.00], ['category1_3', 'E3_561_001', 135764.52], ['category1_3', 'E3_561_003', 379.50],
            ['category1_3', 'E3_561_005', 10500.00], ['category1_5', 'E3_562', 16769.00],
            ['category2_12', 'E3_585_014', 309.64], ['category2_12', 'E3_585_016', 90.36],
            ['category2_12', 'E3_882_001', 49000.00], ['category2_12', 'E3_882_002', -49000.00],
            ['category2_3', 'E3_585_010', 23934.18], ['category2_4', 'E3_585_009', 6754.24], ['category2_4', 'E3_585_013', 960.79],
            ['category2_4', 'E3_585_015', 1350.00], ['category2_4', 'E3_585_016', 56028.63],
            ['category2_5', 'E3_585_014', 2600.11], ['category2_5', 'E3_585_016', 3196.70], ['category2_5', 'E3_586', 2363.20],
            ['category2_6', 'E3_581_001', 57890.82], ['category2_6', 'E3_581_002', 12674.25],
            ['category2_7', 'E3_882_001', 2196.29], ['category2_7', 'E3_882_002', 98000.00], ['category2_8', 'E3_587', 5117.80],
        ];
    }
}
