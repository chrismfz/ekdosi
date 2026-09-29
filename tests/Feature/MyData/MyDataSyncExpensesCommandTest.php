<?php

namespace Tests\Feature\MyData;

use App\Console\Commands\MyDataE3Snapshot;
use App\Console\Commands\MyDataImportExpenses;
use App\Console\Commands\MyDataSyncExpenses;
use App\Filament\Pages\ScheduleSettings;
use App\Models\Company;
use App\Models\E3YearSnapshot;
use App\Models\Expense;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * `mydata:sync-expenses` — the scheduled, --tenant-only twin of mydata:import-expenses
 * that keeps «Φορολογικά» fed with supplier docs + our 13/14/17.x (default ON).
 */
class MyDataSyncExpensesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        MyDataImportExpenses::$testHandler = null;
        MyDataE3Snapshot::$testHandler = null;
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_window_is_the_running_year_plus_last_year_until_july(): void
    {
        $this->assertSame([2025, 2026], MyDataSyncExpenses::windowYears(Carbon::parse('2026-01-10')));
        $this->assertSame([2025, 2026], MyDataSyncExpenses::windowYears(Carbon::parse('2026-07-31')));
        $this->assertSame([2026], MyDataSyncExpenses::windowYears(Carbon::parse('2026-08-01')));
        $this->assertSame([2026], MyDataSyncExpenses::windowYears(Carbon::parse('2026-12-31')));
    }

    public function test_it_imports_both_directions_and_holds_manual_lookalikes(): void
    {
        Carbon::setTestNow('2026-09-25 04:40:00');
        $tenant = $this->readableTenant();
        // A hand-typed payroll the AADE 17.1 below duplicates → held, not double-booked.
        Expense::create([
            'company_id' => $tenant->id, 'source' => 'manual', 'issue_date' => '2026-03-31',
            'net_total' => 3000, 'vat_total' => 0, 'gross_total' => 3000,
        ]);

        // 25/09/2026 → 2026 Q1–Q3 × (suppliers, self) = 6 calls; the 17.1 comes back on the self side.
        $empty = '<?xml version="1.0" encoding="utf-8"?><RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0"></RequestedDoc>';
        MyDataImportExpenses::$testHandler = new MockHandler([
            new Response(200, [], $empty), new Response(200, [], $this->payrollDoc('500000000000901', '2026-03-31', '3000.00')),
            new Response(200, [], $empty), new Response(200, [], $this->payrollDoc('500000000000902', '2026-06-30', '3100.00')),
            new Response(200, [], $empty), new Response(200, [], $empty),
        ]);

        MyDataE3Snapshot::$testHandler = $this->e3Response(1);   // one Ε3 call for 2026

        $this->artisan('mydata:sync-expenses', ['--tenant' => $tenant->slug, '--gap' => 0])
            ->expectsOutputToContain('500000000000901')
            ->assertSuccessful();

        $this->assertSame(0, MyDataImportExpenses::$testHandler->count(), 'exactly 6 AADE calls');
        $this->assertSame(0, MyDataE3Snapshot::$testHandler->count());
        $this->assertSame(1, E3YearSnapshot::query()->where('company_id', $tenant->id)->where('year', 2026)->count(), 'the Ε3 snapshot is refreshed too');
        $marks = Expense::query()->where('company_id', $tenant->id)->whereNotNull('mydata_mark')->pluck('mydata_mark')->all();
        $this->assertSame(['500000000000902'], $marks, 'Q2 payroll imported; the Q1 look-alike held');
    }

    public function test_newest_year_goes_first_and_a_failure_is_raised_not_swallowed(): void
    {
        // 10/01/2026 → window [2025, 2026], run as 2026 THEN 2025, each Ε3 first. 2026
        // (Ε3 + Q1's 2 calls) succeeds and books its 17.1; the 2025 docs then error →
        // the command THROWS (TenantScheduleSweep only reports exceptions), with 2026
        // and the 2025 Ε3 already stored.
        Carbon::setTestNow('2026-01-10 04:40:00');
        $tenant = $this->readableTenant();
        $empty = '<?xml version="1.0" encoding="utf-8"?><RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0"></RequestedDoc>';
        MyDataImportExpenses::$testHandler = new MockHandler([
            new Response(200, [], $empty),
            new Response(200, [], $this->payrollDoc('500000000000950', '2026-01-05', '1200.00')),
            new Response(500, [], 'boom'),
        ]);
        MyDataE3Snapshot::$testHandler = $this->e3Response(2);

        try {
            $this->artisan('mydata:sync-expenses', ['--tenant' => $tenant->slug, '--gap' => 0])->run();
            $this->fail('a failed year must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('έξοδα 2025', $e->getMessage());
            $this->assertStringNotContainsString('Ε3', $e->getMessage());
        }

        $this->assertSame([2025, 2026], E3YearSnapshot::query()->where('company_id', $tenant->id)->orderBy('year')->pluck('year')->all());

        $this->assertSame(['500000000000950'], Expense::query()->where('company_id', $tenant->id)->pluck('mydata_mark')->all());
    }

    public function test_older_snapshots_without_a_month_split_are_healed_once(): void
    {
        Carbon::setTestNow('2026-09-25 04:40:00');
        $tenant = $this->readableTenant();
        foreach ([2023, 2024] as $y) {   // fetched before the `monthly` column existed
            E3YearSnapshot::create(['company_id' => $tenant->id, 'year' => $y, 'rows' => [], 'monthly' => null,
                'through' => "{$y}-12-31", 'fetched_at' => now()]);
        }
        $empty = '<?xml version="1.0" encoding="utf-8"?><RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0"></RequestedDoc>';
        MyDataImportExpenses::$testHandler = new MockHandler(array_map(fn () => new Response(200, [], $empty), range(1, 6)));
        MyDataE3Snapshot::$testHandler = $this->e3Response(3);   // 2026 + the two heals

        $this->artisan('mydata:sync-expenses', ['--tenant' => $tenant->slug, '--gap' => 0])->assertSuccessful();

        $this->assertSame(0, MyDataE3Snapshot::$testHandler->count());
        $this->assertSame(0, E3YearSnapshot::query()->where('company_id', $tenant->id)->whereNull('monthly')->count());
    }

    public function test_a_tenant_that_cannot_read_mydata_is_skipped_without_calling_aade(): void
    {
        $tenant = Company::create(['name' => 'EE', 'slug' => 'ee-'.uniqid(), 'country_code' => 'EE', 'einvoice_provider' => 'none']);
        MyDataImportExpenses::$testHandler = new MockHandler([]);   // any call would throw

        $this->artisan('mydata:sync-expenses', ['--tenant' => $tenant->slug])->assertSuccessful();
    }

    public function test_it_is_scheduled_per_readable_tenant_default_on_and_on_the_page(): void
    {
        $console = (string) file_get_contents(base_path('routes/console.php'));

        $this->assertMatchesRegularExpression(
            '~\$sweepTenants\(\s*Company::myDataReadable\(\),\s*\'mydata:sync-expenses\'~s',
            $console,
        );
        $this->assertStringContainsString("->name('mydata-sync-expenses-all')", $console);
        $this->assertStringContainsString("\$scheduleEnabled('mydata_sync_expenses_enabled')", $console);

        $this->assertTrue((bool) config('ekdosi.schedule.mydata_sync_expenses_enabled'), 'default ON');
        $this->assertNotSame('', (string) config('ekdosi.schedule.mydata_sync_expenses_cron'));
        $this->assertContains('mydata_sync_expenses_enabled', ScheduleSettings::taskKeys());
        $this->assertContains('mydata_sync_expenses_cron', ScheduleSettings::timingKeys());
    }

    /** N one-shot RequestE3Info responses (one per year) carrying a payroll row. */
    private function e3Response(int $n): MockHandler
    {
        $body = '<?xml version="1.0" encoding="utf-8"?><RequestedE3Info xmlns="http://www.aade.gr/myDATA/invoice/v1.0">'
            .'<E3Info><V_Afm>801280908</V_Afm><V_Mark>400000000000001</V_Mark><IssueDate>2026-03-31T00:00:00</IssueDate>'
            .'<V_Class_Category>category2_6</V_Class_Category><V_Class_Type>E3_581_001</V_Class_Type><V_Class_Value>3000.00</V_Class_Value></E3Info>'
            .'</RequestedE3Info>';

        return new MockHandler(array_map(fn () => new Response(200, [], $body), range(1, $n)));
    }

    private function readableTenant(): Company
    {
        return Company::create([
            'name' => 'Sync test', 'slug' => 'sync-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox', 'afm' => '801280908',
            'mydata_aade_id_sandbox' => 'TESTUSER', 'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);
    }

    private function payrollDoc(string $mark, string $date, string $amount): string
    {
        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
    <invoicesDoc>
        <invoice>
            <mark>{$mark}</mark>
            <issuer><vatNumber>801280908</vatNumber><country>GR</country></issuer>
            <invoiceHeader><series>A</series><aa>{$mark}</aa><issueDate>{$date}</issueDate><invoiceType>17.1</invoiceType><currency>EUR</currency></invoiceHeader>
            <invoiceDetails><lineNumber>1</lineNumber><netValue>{$amount}</netValue><vatCategory>8</vatCategory><vatAmount>0.00</vatAmount></invoiceDetails>
            <invoiceSummary><totalNetValue>{$amount}</totalNetValue><totalVatAmount>0.00</totalVatAmount><totalGrossValue>{$amount}</totalGrossValue></invoiceSummary>
        </invoice>
    </invoicesDoc>
</RequestedDoc>
XML;
    }
}
