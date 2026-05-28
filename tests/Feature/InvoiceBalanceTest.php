<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\InvoiceBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceBalanceTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;
    private InvoiceType $type;
    private PaymentMethod $credit;
    private PaymentMethod $cash;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 't', 'slug' => 't-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->credit = PaymentMethod::create([
            'company_id' => $this->tenant->id, 'description' => 'Πίστωση', 'due_days' => 30,
        ]);
        $this->cash = PaymentMethod::create([
            'company_id' => $this->tenant->id, 'description' => 'Μετρητά', 'due_days' => 0,
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ',
            'invcount' => 1, 'payment_method_id' => $this->cash->id,
        ]);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'C']);
    }

    private function invoice(array $attrs = []): Invoice
    {
        $state = $attrs['mydata_state'] ?? null;
        unset($attrs['mydata_state']);
        $inv = Invoice::create(array_merge([
            'company_id' => $this->tenant->id,
            'invcode' => 'ΤΠΥ'.uniqid(), 'code' => 1,
            'invoice_type_id' => $this->type->id,
            'customer_id' => $this->customer->id,
            'payment_method_id' => $this->credit->id,
            'issued_at' => '2026-05-10 10:00:00',
            'net_total' => 100, 'gross_total' => 124,
        ], $attrs));
        if ($state !== null) {
            $inv->forceFill(['mydata_state' => $state])->save();
        }

        return $inv;
    }

    private function svc(): InvoiceBalance
    {
        return app(InvoiceBalance::class);
    }

    public function test_unpaid_credit_term_invoice(): void
    {
        $b = $this->svc()->for($this->invoice());
        $this->assertSame(PaymentStatus::Unpaid, $b->status);
        $this->assertSame(124.0, $b->balance);
        $this->assertSame(124.0, $b->owed);
    }

    public function test_cash_term_invoice_is_paid_at_issue(): void
    {
        $b = $this->svc()->for($this->invoice(['payment_method_id' => $this->cash->id]));
        $this->assertSame(PaymentStatus::Paid, $b->status);
        // Figures must agree with the Paid badge: nothing outstanding.
        $this->assertSame(0.0, $b->balance);
        $this->assertSame($b->owed, $b->paid);
    }

    public function test_invoice_with_no_payment_method_is_settled(): void
    {
        // null payment_method → cash-term → settled, balance 0 (not a
        // "€X outstanding next to Paid" contradiction).
        $b = $this->svc()->for($this->invoice(['payment_method_id' => null]));
        $this->assertSame(PaymentStatus::Paid, $b->status);
        $this->assertSame(0.0, $b->balance);
    }

    public function test_partial_then_full_payment_via_observer_cache(): void
    {
        $inv = $this->invoice();

        Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'invoice_id' => $inv->id, 'amount' => 24, 'pay_date' => '2026-05-11',
        ]);
        $inv->refresh();
        $this->assertSame('partial', $inv->payment_status);
        $this->assertSame('24.00', (string) $inv->paid_total);

        Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'invoice_id' => $inv->id, 'amount' => 100, 'pay_date' => '2026-05-12',
        ]);
        $inv->refresh();
        $this->assertSame('paid', $inv->payment_status);
    }

    public function test_overpayment(): void
    {
        $inv = $this->invoice();
        Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'invoice_id' => $inv->id, 'amount' => 200, 'pay_date' => '2026-05-11',
        ]);
        $this->assertSame(PaymentStatus::Overpaid, $this->svc()->for($inv->refresh())->status);
    }

    public function test_soft_deleted_payment_is_excluded_then_restored(): void
    {
        $inv = $this->invoice();
        $p = Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'invoice_id' => $inv->id, 'amount' => 124, 'pay_date' => '2026-05-11',
        ]);
        $this->assertSame('paid', $inv->refresh()->payment_status);

        $p->delete();
        $this->assertSame('unpaid', $inv->refresh()->payment_status);

        $p->restore();
        $this->assertSame('paid', $inv->refresh()->payment_status);
    }

    public function test_on_account_payment_does_not_touch_invoice_cache(): void
    {
        $inv = $this->invoice();
        Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'invoice_id' => null, 'amount' => 500, 'pay_date' => '2026-05-11',
        ]);
        $inv->refresh();
        $this->assertNull($inv->payment_status);   // never recomputed
    }

    public function test_reallocating_payment_recomputes_both_invoices(): void
    {
        $a = $this->invoice();
        $b = $this->invoice();
        $p = Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'invoice_id' => $a->id, 'amount' => 124, 'pay_date' => '2026-05-11',
        ]);
        $this->assertSame('paid', $a->refresh()->payment_status);

        $p->update(['invoice_id' => $b->id]);
        $this->assertSame('unpaid', $a->refresh()->payment_status);
        $this->assertSame('paid', $b->refresh()->payment_status);
    }

    public function test_non_cancelled_credit_notes_reduce_owed(): void
    {
        $original = $this->invoice(['mydata_state' => 'VALID']);

        // A CANCELLED credit note must NOT reduce owed.
        $this->invoice(['gross_total' => 124, 'credited_invoice_id' => $original->id, 'mydata_state' => 'CANCELLED']);
        $this->assertSame(PaymentStatus::Unpaid, $this->svc()->for($original)->status);

        // An issued credit note (null state = filed-or-pending, not
        // cancelled) for the full amount → credited.
        $this->invoice(['gross_total' => 124, 'credited_invoice_id' => $original->id, 'mydata_state' => null]);
        $b = $this->svc()->for($original);
        $this->assertSame(PaymentStatus::Credited, $b->status);
        $this->assertSame(124.0, $b->credited);
        $this->assertSame(0.0, $b->owed);
    }
}
