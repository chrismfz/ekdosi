<?php

namespace Tests\Feature\Accounting;

use App\Filament\Pages\AgedReceivables as AgedReceivablesPage;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Accounting\AgedReceivablesReport;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class AgedReceivablesTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $old;

    private Customer $recent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'AR OE', 'slug' => 'ar-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
        ]);
        // Credit-term method (due_days > 0) → invoices are tracked receivables.
        $pm = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Επί πιστώσει', 'due_days' => 30]);
        $type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'Τιμολόγιο', 'invcount' => 1, 'mydata_type' => '2.1',
        ]);

        $this->old = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Παλιός', 'afm' => '111']);
        $this->recent = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Πρόσφατος', 'afm' => '222']);

        $this->invoice($type->id, $pm->id, $this->old->id, now()->subDays(100), 200, 248);   // → 90+
        $this->invoice($type->id, $pm->id, $this->recent->id, now()->subDays(10), 50, 62);    // → 0-30
    }

    private function invoice(int $typeId, int $pmId, int $customerId, $issuedAt, float $net, float $gross): void
    {
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'I'.uniqid(), 'code' => 1,
            'invoice_type_id' => $typeId, 'payment_method_id' => $pmId, 'customer_id' => $customerId,
            'issued_at' => $issuedAt, 'local_status' => 'active',
            'net_total' => $net, 'gross_total' => $gross, 'header_discount_percent' => 0,
        ]);
        // payment_status is a guarded money-cache column; mark unpaid so the report's
        // candidate query picks it up (no payments recorded).
        $inv->forceFill(['payment_status' => 'unpaid'])->saveQuietly();
    }

    public function test_report_buckets_by_age_and_foots_totals(): void
    {
        $result = app(AgedReceivablesReport::class)->build($this->tenant);

        $this->assertCount(2, $result->rows);

        // Biggest debtor first.
        $this->assertSame($this->old->id, $result->rows[0]->customerId);

        $oldRow = collect($result->rows)->firstWhere('customerId', $this->old->id);
        $this->assertGreaterThan(0, $oldRow->b90plus, 'a 100-day-old invoice lands in 90+');
        $this->assertSame(0.0, $oldRow->b0_30);

        $recentRow = collect($result->rows)->firstWhere('customerId', $this->recent->id);
        $this->assertGreaterThan(0, $recentRow->b0_30, 'a 10-day-old invoice lands in 0-30');
        $this->assertSame(0.0, $recentRow->b90plus);

        // Column totals foot the rows.
        $this->assertEqualsWithDelta(
            $oldRow->b90plus + $recentRow->b0_30,
            $result->grandTotal(),
            0.01,
        );
    }

    public function test_page_renders_with_rows(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]));
        Filament::setTenant($this->tenant);

        Livewire::test(AgedReceivablesPage::class)
            ->assertSuccessful()
            ->assertSee('Ηλικίωση οφειλών')
            ->assertSee('Παλιός')
            ->assertSee('Πρόσφατος')
            ->assertSee('Σύνολα');
    }
}
