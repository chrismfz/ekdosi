<?php

namespace Tests\Feature\MyData;

use App\Filament\Pages\MyDataConsoleExpenses;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The read-only `mydata:refresh-expenses` cron caches the SAME snapshot the
 * «Κονσόλα myDATA — Έξοδα» page restores on mount — so opening the console (or
 * reading the Έξοδα-list badge) shows fresh αδέσποτα without an interactive fetch,
 * and WITHOUT importing anything.
 */
class RefreshExpensesSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'Exp cron', 'slug' => 'expcron-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
            'afm' => '801280908',
            'mydata_aade_id_sandbox' => 'TESTUSER',
            'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);
    }

    private function orphanDoc(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
    <invoicesDoc>
        <invoice>
            <mark>400012434052701</mark>
            <issuer><vatNumber>998482379</vatNumber><country>GR</country><name>ΑΛΦΑΝΕΤ ΑΕ</name></issuer>
            <counterpart><vatNumber>801280908</vatNumber><country>GR</country></counterpart>
            <invoiceHeader><series>A</series><aa>42</aa><issueDate>2026-01-15</issueDate><invoiceType>1.1</invoiceType></invoiceHeader>
            <invoiceDetails>
                <lineNumber>1</lineNumber><netValue>100.00</netValue><vatCategory>1</vatCategory><vatAmount>24.00</vatAmount>
            </invoiceDetails>
            <invoiceSummary><totalNetValue>100.00</totalNetValue><totalVatAmount>24.00</totalVatAmount><totalGrossValue>124.00</totalGrossValue></invoiceSummary>
        </invoice>
    </invoicesDoc>
</RequestedDoc>
XML;
    }

    public function test_auto_only_refreshes_only_opted_in_tenants(): void
    {
        $optedIn = Company::create([
            'name' => 'In', 'slug' => 'in-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox', 'afm' => '801280901',
            'mydata_aade_id_sandbox' => 'U', 'mydata_subscription_key_sandbox' => 'K',
            'mydata_auto_fetch_expenses' => true,
        ]);
        $optedOut = Company::create([
            'name' => 'Out', 'slug' => 'out-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox', 'afm' => '801280902',
            'mydata_aade_id_sandbox' => 'U', 'mydata_subscription_key_sandbox' => 'K',
        ]);

        // Exactly ONE AADE response: if --auto-only wrongly swept the opted-out
        // tenant too, the second fetch would run the mock dry (→ non-zero exit).
        MyDataConsoleExpenses::$testHandler = new MockHandler([new Response(200, [], $this->orphanDoc())]);

        try {
            $this->artisan('mydata:refresh-expenses', ['--auto-only' => true, '--gap' => 0])
                ->assertExitCode(0);

            $this->assertNotNull(MyDataConsoleExpenses::lastFetchAt($optedIn->id), 'opted-in tenant refreshed');
            $this->assertNull(MyDataConsoleExpenses::lastFetchAt($optedOut->id), 'opted-out tenant skipped');
        } finally {
            MyDataConsoleExpenses::$testHandler = null;
        }
    }

    public function test_the_command_caches_a_snapshot_the_console_restores(): void
    {
        $tenant = $this->tenant();
        MyDataConsoleExpenses::$testHandler = new MockHandler([new Response(200, [], $this->orphanDoc())]);

        try {
            $exit = $this->artisan('mydata:refresh-expenses', ['--tenant' => $tenant->slug, '--gap' => 0])
                ->assertExitCode(0)
                ->run();

            // The snapshot is cached for the tenant: fresh timestamp + 1 orphan.
            $this->assertNotNull(MyDataConsoleExpenses::lastFetchAt($tenant->id));
            $this->assertSame(1, MyDataConsoleExpenses::lastOrphanCount($tenant->id));

            // It did NOT import — no expense rows created (read-only).
            $this->assertDatabaseMissing('expenses', ['company_id' => $tenant->id, 'mydata_mark' => '400012434052701']);

            // The console restores exactly that snapshot on mount (no second fetch).
            Gate::before(fn () => true);
            $this->actingAs(User::create([
                'name' => 'Op', 'email' => 'op-'.uniqid().'@test.local', 'password' => bcrypt('x'),
            ]));
            Filament::setTenant($tenant);

            Livewire::test(MyDataConsoleExpenses::class)
                ->assertSet('ran', true)
                ->assertSee('400012434052701');
        } finally {
            MyDataConsoleExpenses::$testHandler = null;
        }
    }
}
