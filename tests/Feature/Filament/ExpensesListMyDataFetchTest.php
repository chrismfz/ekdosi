<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\MyDataConsoleExpenses;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Πακέτο 2 — the «Άντληση από myDATA» entry point on the Έξοδα list: a one-click
 * fetch that lands on the console worklist, plus a «τελευταία άντληση» tip. Both
 * gated on the same access as the expenses console.
 */
class ExpensesListMyDataFetchTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $mode = 'sandbox', string $provider = 'gr-mydata'): Company
    {
        return Company::create([
            'name' => 'E', 'slug' => 'e-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => $provider, 'mydata_mode' => $mode,
            'afm' => '801280908', 'mydata_aade_id_sandbox' => 'U', 'mydata_subscription_key_sandbox' => 'K',
        ]);
    }

    private function actAdmin(Company $tenant): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'A', 'email' => 'a-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]));
        Filament::setTenant($tenant);
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
            <invoiceDetails><lineNumber>1</lineNumber><netValue>100.00</netValue><vatCategory>1</vatCategory><vatAmount>24.00</vatAmount></invoiceDetails>
            <invoiceSummary><totalNetValue>100.00</totalNetValue><totalVatAmount>24.00</totalVatAmount><totalGrossValue>124.00</totalGrossValue></invoiceSummary>
        </invoice>
    </invoicesDoc>
</RequestedDoc>
XML;
    }

    #[Test]
    public function an_admin_on_a_mydata_tenant_sees_the_fetch_button(): void
    {
        $this->actAdmin($this->tenant());

        Livewire::test(ListExpenses::class)->assertActionExists('fetchFromMyData');
    }

    #[Test]
    public function the_button_is_absent_for_a_non_mydata_tenant(): void
    {
        $this->actAdmin($this->tenant('off', 'none'));

        Livewire::test(ListExpenses::class)->assertActionDoesNotExist('fetchFromMyData');
    }

    #[Test]
    public function an_operator_without_console_access_sees_neither_button_nor_tip(): void
    {
        $tenant = $this->tenant();

        // Realistic operator: can do everything EXCEPT open the expenses console.
        Gate::before(fn ($user, string $ability) => $ability === 'View:MyDataConsoleExpenses' ? false : true);
        $this->actingAs(User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]));
        Filament::setTenant($tenant);

        // Seed a snapshot so the tip WOULD show if the gate were missing.
        MyDataConsoleExpenses::$testHandler = new MockHandler([new Response(200, [], $this->orphanDoc())]);
        try {
            MyDataConsoleExpenses::refreshSnapshot($tenant, now()->startOfQuarter(), now());
        } finally {
            MyDataConsoleExpenses::$testHandler = null;
        }

        Livewire::test(ListExpenses::class)
            ->assertActionDoesNotExist('fetchFromMyData')
            ->assertDontSee('Τελευταία άντληση myDATA');
    }

    #[Test]
    public function the_subheading_shows_the_last_fetch_after_a_snapshot_exists(): void
    {
        $tenant = $this->tenant();
        $this->actAdmin($tenant);

        // No fetch yet → prompt; then seed a snapshot → «τελευταία άντληση».
        Livewire::test(ListExpenses::class)->assertSee('δεν έχει γίνει άντληση');

        MyDataConsoleExpenses::$testHandler = new MockHandler([new Response(200, [], $this->orphanDoc())]);
        try {
            MyDataConsoleExpenses::refreshSnapshot($tenant, now()->startOfQuarter(), now());
        } finally {
            MyDataConsoleExpenses::$testHandler = null;
        }

        Livewire::test(ListExpenses::class)
            ->assertSee('Τελευταία άντληση myDATA')
            ->assertSee('αδέσποτα έξοδα');
    }

    #[Test]
    public function the_button_fetches_and_redirects_to_the_console(): void
    {
        $tenant = $this->tenant();
        $this->actAdmin($tenant);

        MyDataConsoleExpenses::$testHandler = new MockHandler([new Response(200, [], $this->orphanDoc())]);
        try {
            Livewire::test(ListExpenses::class)
                ->callAction('fetchFromMyData')
                ->assertRedirect(MyDataConsoleExpenses::getUrl(['tenant' => $tenant]));
        } finally {
            MyDataConsoleExpenses::$testHandler = null;
        }

        // The fetch seeded the console snapshot (and imported nothing).
        $this->assertSame(1, MyDataConsoleExpenses::lastOrphanCount($tenant->id));
        $this->assertDatabaseMissing('expenses', ['company_id' => $tenant->id, 'mydata_mark' => '400012434052701']);
    }
}
