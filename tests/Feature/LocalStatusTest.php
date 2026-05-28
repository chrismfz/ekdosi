<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\CustomerLedger\CustomerLedgerBuilder;
use App\Services\Dashboard\DashboardMetrics;
use App\Support\InvoiceScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Local status (draft/active/cancelled) orthogonal to mydata_state, and
 * the InvoiceScope::live predicate that excludes cancelled invoices from
 * every money surface.
 */
class LocalStatusTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;
    private PaymentMethod $credit;
    private InvoiceType $type;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 't', 'slug' => 't-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->credit = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Πίστωση', 'due_days' => 30]);
        $this->type = InvoiceType::create(['company_id' => $this->tenant->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1]);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'C']);
    }

    private function makeInvoice(array $attrs = []): Invoice
    {
        // mydata_state is a forceFill-only cache column (not mass-assignable).
        $mydataState = $attrs['mydata_state'] ?? null;
        unset($attrs['mydata_state']);

        $inv = Invoice::create(array_merge([
            'company_id' => $this->tenant->id, 'invcode' => 'ΤΠΥ'.uniqid(), 'code' => 1,
            'invoice_type_id' => $this->type->id, 'customer_id' => $this->customer->id,
            'payment_method_id' => $this->credit->id, 'issued_at' => '2026-05-10 10:00:00',
            'net_total' => 100, 'gross_total' => 124, 'local_status' => 'active',
        ], $attrs));

        if ($mydataState !== null) {
            $inv->forceFill(['mydata_state' => $mydataState])->save();
        }

        return $inv;
    }

    private function metrics(): DashboardMetrics
    {
        return new DashboardMetrics($this->tenant);
    }

    public function test_cancelled_invoice_excluded_from_every_money_surface(): void
    {
        $inv = $this->makeInvoice(['local_status' => 'active', 'mydata_state' => null]);

        $this->assertSame(124.0, $this->metrics()->outstandingReceivables());
        $this->assertSame(124.0, app(CustomerLedgerBuilder::class)->build($this->customer)->stats['balance']);
        $this->assertSame(1, $this->metrics()->unfiledCount());
        $this->assertSame(100.0, $this->metrics()->income(
            Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'))->net);

        $inv->update(['local_status' => 'cancelled']);

        $this->assertSame(0.0, $this->metrics()->outstandingReceivables());
        $this->assertSame(0.0, app(CustomerLedgerBuilder::class)->build($this->customer)->stats['balance']);
        $this->assertSame(0, $this->metrics()->unfiledCount());
        $this->assertSame(0.0, $this->metrics()->income(
            Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'))->net);
    }

    public function test_cancel_detaches_payment_to_on_account_credit(): void
    {
        // Mimics the cancel_local action: invoice paid in full, then
        // cancelled → payment detaches to on-account, leaving the
        // customer with a credit (negative ledger balance).
        $inv = $this->makeInvoice();
        Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'invoice_id' => $inv->id, 'amount' => 124, 'pay_date' => '2026-05-11',
        ]);
        $this->assertSame(0.0, app(CustomerLedgerBuilder::class)->build($this->customer)->stats['balance']);

        DB::transaction(function () use ($inv) {
            $inv->payments()->get()->each->update(['invoice_id' => null]);   // → on-account
            $inv->update(['local_status' => 'cancelled']);
        });

        // Invoice excluded (cancelled); the 124 payment is now a credit.
        $this->assertSame(-124.0, app(CustomerLedgerBuilder::class)->build($this->customer)->stats['balance']);
    }

    public function test_invoicescope_live_excludes_cancelled_and_aade_cancelled(): void
    {
        $this->makeInvoice(['local_status' => 'active', 'mydata_state' => null]);    // live
        $this->makeInvoice(['local_status' => 'cancelled', 'mydata_state' => null]); // local-cancelled
        $this->makeInvoice(['local_status' => 'active', 'mydata_state' => 'CANCELLED']); // AADE-cancelled

        $count = InvoiceScope::live(DB::table('invoices')->where('company_id', $this->tenant->id))->count();
        $this->assertSame(1, $count);
    }

    public function test_reconciliation_matches_local_vs_mydata_disagreements(): void
    {
        $this->makeInvoice(['local_status' => 'cancelled', 'mydata_state' => 'VALID']);     // ⚠ needs AADE cancel
        $this->makeInvoice(['local_status' => 'active', 'mydata_state' => 'CANCELLED']);    // ⚠ contradiction
        $this->makeInvoice(['local_status' => 'active', 'mydata_state' => 'VALID']);        // ok
        $this->makeInvoice(['local_status' => 'cancelled', 'mydata_state' => null]);        // ok (dropped draft)

        $mismatches = Invoice::query()
            ->where('company_id', $this->tenant->id)
            ->where(function ($q) {
                $q->where(fn ($w) => $w->where('local_status', 'cancelled')->where('mydata_state', 'VALID'))
                    ->orWhere(fn ($w) => $w->where('local_status', 'active')->where('mydata_state', 'CANCELLED'));
            })
            ->count();

        $this->assertSame(2, $mismatches);
    }
}
