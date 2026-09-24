<?php

namespace Tests\Feature\Invoice;

use App\Actions\ConvertInformalToFiscal;
use App\Filament\Pages\InformalValueReport;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\User;
use App\Services\Accounting\InformalValue;
use App\Services\RecomputeInvoiceTotals;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Αξία άτυπων» — the one place the value of informal documents shows (they are
 * out of every money/VAT total by design): issued ones only, per customer / item /
 * series; a converted one was charged after all, so it is reported apart.
 */
class InformalValueReportTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $friend;

    private Customer $us;

    private InvoiceType $eso;

    private InvoiceType $dok;

    private InvoiceType $fiscal;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-25 10:00:00');

        $this->tenant = Company::create(['name' => 'Report test', 'slug' => 'rep-'.uniqid(), 'country_code' => 'GR']);
        $this->friend = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Φίλος']);
        $this->us = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Εμείς']);
        $this->eso = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΕΣΩ', 'name' => 'Εσωτερικά', 'invcount' => 1, 'is_informal' => true]);
        $this->dok = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΔΟΚ', 'name' => 'Δοκιμές', 'invcount' => 1, 'is_informal' => true]);
        $this->fiscal = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΤΠΥ', 'name' => 'Τιμολόγιο', 'invcount' => 1, 'mydata_type' => '2.1']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_sums_only_issued_unconverted_informal_documents(): void
    {
        $this->doc($this->eso, $this->friend, 'Hosting Basic (1/1/2026-31/12/2026)', 100);  // counts
        $this->doc($this->eso, $this->friend, 'Hosting Basic (1/1/2027-31/12/2027)', 100);  // counts — same item
        $this->doc($this->dok, $this->us, 'Domain test.gr', 20);                           // counts
        $this->doc($this->eso, $this->friend, 'Draft', 999, status: 'draft');               // not issued
        $this->doc($this->eso, $this->friend, 'Cancelled', 999, status: 'cancelled');       // cancelled
        $this->doc($this->eso, $this->friend, 'Last year', 999, date: '2025-06-01');        // other year
        $this->doc($this->fiscal, $this->friend, 'Real sale', 999);                         // fiscal
        $converted = $this->doc($this->eso, $this->friend, 'Paid after all', 50);           // converted…
        $fiscal = app(ConvertInformalToFiscal::class)($converted, $this->fiscal);

        // …but while the fiscal is still a draft nothing was charged: still unbilled.
        $pending = app(InformalValue::class)->build($this->tenant, 2026);
        $this->assertSame(4, $pending['total_docs']);
        $this->assertSame(0, $pending['converted_docs']);
        $this->assertStringContainsString('(πρόχειρο)', (string) collect($pending['documents'])->firstWhere('id', $converted->id)['converted_to']);

        $fiscal->update(['local_status' => 'active']);                                       // …issued: charged
        $r = app(InformalValue::class)->build($this->tenant, 2026);

        $this->assertSame(3, $r['total_docs']);
        $this->assertEqualsWithDelta(220.0, $r['total_net'], 0.001);
        $this->assertEqualsWithDelta(272.8, $r['total_gross'], 0.001);
        $this->assertSame(1, $r['converted_docs']);
        $this->assertEqualsWithDelta(62.0, $r['converted_gross'], 0.001);
        $this->assertCount(4, $r['documents'], 'the CSV list carries the converted one too, marked');

        $this->assertSame(['Φίλος', 'Εμείς'], array_column($r['by_customer'], 'label'));
        $this->assertSame([2, 1], array_column($r['by_customer'], 'docs'));
        // Renewals of the same package fold into one item (dated suffix stripped).
        $this->assertSame('Hosting Basic', $r['by_item'][0]['label']);
        $this->assertEqualsWithDelta(2.0, $r['by_item'][0]['qty'], 0.001);
        $this->assertCount(2, $r['by_series']);

        // One series only.
        $this->assertEqualsWithDelta(20.0, app(InformalValue::class)->build($this->tenant, 2026, $this->dok->id)['total_net'], 0.001);
    }

    public function test_the_page_renders_filters_and_exports(): void
    {
        $this->doc($this->eso, $this->friend, 'Hosting Basic', 100);
        Gate::before(fn () => true);
        $this->actingAs(User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('x')]));
        Filament::setTenant($this->tenant);

        Livewire::test(InformalValueReport::class)
            ->assertOk()
            ->assertSet('year', 2026)
            ->assertSee('Αξία άτυπων')
            ->assertSee('Hosting Basic')
            ->set('series', (string) $this->dok->id)
            ->assertDontSee('Hosting Basic')
            ->assertSee('Δεν βρέθηκαν εκδομένα άτυπα')
            ->set('series', '')
            ->callAction('export_csv')
            ->assertFileDownloaded('axia-atypon-2026.csv');
    }

    private function doc(InvoiceType $type, Customer $customer, string $descr, float $price, string $status = 'active', ?string $date = null): Invoice
    {
        $numbered = $status !== 'draft';
        $invoice = Invoice::create(array_filter([
            'company_id' => $this->tenant->id, 'invoice_type_id' => $type->id, 'customer_id' => $customer->id,
            'issued_at' => ($date ?? '2026-05-10').' 10:00:00', 'local_status' => $status,
            'invcode' => $numbered ? $type->code.uniqid() : null, 'code' => $numbered ? 1 : null,
        ], fn ($v) => $v !== null));
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $invoice->id,
            'qty' => 1, 'price_per_item' => $price, 'vat_percent' => 24, 'product_descr' => $descr,
        ]);

        return app(RecomputeInvoiceTotals::class)($invoice)->fresh();
    }
}
