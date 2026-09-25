<?php

namespace Tests\Feature\MyData;

use App\Console\Commands\MyDataImportExpenses;
use App\Models\Company;
use App\Models\Expense;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * `mydata:import-expenses` — whole-year back-fill, quarter by quarter, through
 * the same idempotent ExpenseImporter as the console.
 */
class MyDataImportExpensesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-25 10:00:00');

        $this->tenant = Company::create([
            'name' => 'Import test', 'slug' => 'imp-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox', 'afm' => '801280908',
            'mydata_aade_id_sandbox' => 'TESTUSER', 'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);
    }

    protected function tearDown(): void
    {
        MyDataImportExpenses::$testHandler = null;
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_year_is_fetched_per_quarter_and_stays_idempotent(): void
    {
        // Four quarterly calls (self-declared only) — the same doc every time: the
        // first quarter creates it, the other three skip the existing MARK.
        MyDataImportExpenses::$testHandler = $this->responses(4, $this->payrollDoc());

        $this->artisan('mydata:import-expenses', ['--tenant' => $this->tenant->slug, '--year' => ['2025'], '--only' => 'self'])
            ->expectsOutputToContain('καταχωρήθηκαν 1 νέα έξοδα')
            ->assertSuccessful();

        $this->assertSame(1, Expense::query()->where('company_id', $this->tenant->id)->count());
        $e = Expense::query()->where('company_id', $this->tenant->id)->first();
        $this->assertSame('payroll', $e->category);
        $this->assertSame(0, MyDataImportExpenses::$testHandler->count()); // exactly 4 calls made
    }

    public function test_the_running_year_stops_at_the_current_quarter(): void
    {
        // 2026 on 25/09 → Q1–Q3 only, both directions = 6 calls.
        MyDataImportExpenses::$testHandler = $this->responses(6, $this->emptyDoc());

        $this->artisan('mydata:import-expenses', ['--tenant' => $this->tenant->slug, '--year' => ['2026']])
            ->assertSuccessful();

        $this->assertSame(0, MyDataImportExpenses::$testHandler->count());
    }

    public function test_bad_arguments_fail_without_calling_aade(): void
    {
        $this->artisan('mydata:import-expenses', ['--tenant' => 'nope', '--year' => ['2025']])->assertFailed();
        $this->artisan('mydata:import-expenses', ['--tenant' => $this->tenant->slug])->assertFailed();
        $this->artisan('mydata:import-expenses', ['--tenant' => $this->tenant->slug, '--year' => ['2030']])->assertFailed();
        $this->artisan('mydata:import-expenses', ['--tenant' => $this->tenant->slug, '--year' => ['2025'], '--only' => 'x'])->assertFailed();
        $this->artisan('mydata:import-expenses', ['--tenant' => $this->tenant->slug, '--year' => ['2023,2024']])->assertFailed(); // not a silent «2023 only»
    }

    /** N DISTINCT responses — a Response body is a one-shot stream, so reusing one instance reads empty. */
    private function responses(int $n, string $body): MockHandler
    {
        return new MockHandler(array_map(fn () => new Response(200, [], $body), range(1, $n)));
    }

    private function payrollDoc(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
    <invoicesDoc>
        <invoice>
            <mark>500000000000777</mark>
            <issuer><vatNumber>801280908</vatNumber><country>GR</country></issuer>
            <invoiceHeader><series>A</series><aa>7</aa><issueDate>2025-03-31</issueDate><invoiceType>17.1</invoiceType><currency>EUR</currency></invoiceHeader>
            <invoiceDetails><lineNumber>1</lineNumber><netValue>3000.00</netValue><vatCategory>8</vatCategory><vatAmount>0.00</vatAmount></invoiceDetails>
            <invoiceSummary><totalNetValue>3000.00</totalNetValue><totalVatAmount>0.00</totalVatAmount><totalGrossValue>3000.00</totalGrossValue></invoiceSummary>
        </invoice>
    </invoicesDoc>
</RequestedDoc>
XML;
    }

    private function emptyDoc(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0"></RequestedDoc>';
    }
}
