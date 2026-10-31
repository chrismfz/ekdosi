<?php

namespace Tests\Feature\Accounting;

use App\Filament\Pages\WithholdingsReport;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\User;
use App\Services\Accounting\LedgerBook;
use App\Services\Accounting\WithholdingsReport as WithholdingsReportService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Παρακρατήσεις» — the tax customers withheld on our invoices, per customer and per
 * document, with the SAME year total «Φορολογικά» shows (both read LedgerBook).
 */
class WithholdingsReportTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $tpy;

    private InvoiceType $pis;

    private Customer $alpha;

    private Customer $other;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 18:00:00');

        $this->tenant = Company::create(['name' => 'SW', 'slug' => 'sw-'.uniqid(), 'country_code' => 'GR']);
        $this->tpy = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΤΠΥ', 'name' => 'Τιμολόγιο', 'invcount' => 1, 'mydata_type' => '2.1']);
        $this->pis = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΠΙΣ', 'name' => 'Πιστωτικό', 'invcount' => 1, 'mydata_type' => '5.2', 'is_credit' => true]);
        $this->alpha = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Πελάτης Α ΑΕ', 'afm' => '094000000']);
        $this->other = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Άλλος ΑΕ', 'afm' => '099000000']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_lists_withheld_documents_per_customer_with_the_tax_page_total(): void
    {
        $this->doc($this->tpy, $this->alpha, 3080, 616);
        $this->doc($this->tpy, $this->alpha, 16325, 3265);
        $this->doc($this->tpy, $this->other, 1000, 200);
        $this->doc($this->pis, $this->alpha, 500, 100);                         // credit → −100
        $this->doc($this->tpy, $this->alpha, 3080, 616, status: 'cancelled');   // not live
        $this->doc($this->tpy, $this->alpha, 3080, 616, date: '2025-12-31');    // other year
        $this->doc($this->tpy, $this->alpha, 1, null);                          // no withholding

        $r = app(WithholdingsReportService::class)->build($this->tenant, 2026);

        $this->assertSame(4, $r['total_docs']);
        $this->assertEqualsWithDelta(616 + 3265 + 200 - 100, $r['total_withheld'], 0.001);

        // The «Φορολογικά» figure (IncomeTaxEstimate reads LedgerBook::incomeWithheld()).
        $book = (new LedgerBook($this->tenant))->forPeriod(
            CarbonImmutable::create(2026, 1, 1)->startOfDay(), CarbonImmutable::now()->endOfDay());
        $this->assertEqualsWithDelta($book->incomeWithheld(), $r['total_withheld'], 0.001);

        $this->assertSame(['Πελάτης Α ΑΕ', 'Άλλος ΑΕ'], array_column($r['by_customer'], 'customer'));
        $this->assertSame(3, $r['by_customer'][0]['docs']);
        $this->assertEqualsWithDelta(3781.0, $r['by_customer'][0]['withheld'], 0.001);
        $this->assertSame('094000000', $r['by_customer'][0]['afm']);

        $first = collect($r['documents'])->firstWhere('withheld', 616.0);
        $this->assertEqualsWithDelta(20.0, $first['rate'], 0.001);
        $this->assertTrue(collect($r['documents'])->firstWhere('withheld', -100.0)['is_credit']);

        $this->assertEqualsWithDelta(616, app(WithholdingsReportService::class)->build($this->tenant, 2025)['total_withheld'], 0.001);
    }

    public function test_the_page_renders_switches_year_and_exports(): void
    {
        $this->doc($this->tpy, $this->alpha, 3080, 616);
        $this->signIn();

        Livewire::test(WithholdingsReport::class)
            ->assertOk()
            ->assertSet('year', 2026)
            ->assertSee('Παρακρατήσεις φόρου')
            ->assertSee('Πελάτης Α ΑΕ')
            ->set('year', 2025)
            ->assertDontSee('Πελάτης Α ΑΕ')
            ->assertSee('Δεν βρέθηκαν παραστατικά με παρακράτηση')
            ->set('year', 2026)
            ->callAction('export_csv')
            ->assertFileDownloaded('parakratiseis-2026.csv');
    }

    public function test_the_invoice_list_filters_to_withheld_documents(): void
    {
        $withheld = $this->doc($this->tpy, $this->alpha, 3080, 616);
        $plain = $this->doc($this->tpy, $this->other, 100, null);
        $this->signIn();

        Livewire::test(ListInvoices::class)
            ->filterTable('withheld')
            ->assertCanSeeTableRecords([$withheld])
            ->assertCanNotSeeTableRecords([$plain]);
    }

    private function signIn(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('x')]));
        Filament::setTenant($this->tenant);
    }

    private function doc(InvoiceType $type, Customer $customer, float $net, ?float $withheld, string $status = 'active', ?string $date = null): Invoice
    {
        $invoice = Invoice::create([
            'company_id' => $this->tenant->id, 'invoice_type_id' => $type->id, 'customer_id' => $customer->id,
            'issued_at' => ($date ?? '2026-05-10').' 10:00:00', 'local_status' => $status,
            'invcode' => $type->code.uniqid(), 'code' => 1,
        ]);
        $invoice->forceFill([
            'net_total' => $net, 'gross_total' => round($net * 1.24, 2), 'withhold_amount' => $withheld,
        ])->save();

        return $invoice;
    }
}
