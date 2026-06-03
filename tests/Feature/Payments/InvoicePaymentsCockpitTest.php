<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\InvoiceBalance;
use App\Services\InvoiceBalanceData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The per-invoice payments cockpit behaviours (Phase 1): partial leaves the
 * invoice partially paid, overpayment → Overpaid, and «mark unpaid» (delete all
 * payments) restores the full balance — the ΤΙΜ385 phantom-payment fix.
 */
class InvoicePaymentsCockpitTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Invoice $invoice; // credit-term, gross 100, NOT auto-settled

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create(['name' => 'Pay', 'slug' => 'pay-'.uniqid(), 'country_code' => 'GR']);
        $customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Π', 'afm' => '123456789']);
        $type = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΤΙΜ', 'name' => 'Τ', 'invcount' => 1, 'mydata_type' => '1.1']);
        // credit-term so InvoiceBalance does NOT settle it at issue
        $credit = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Επί Πιστώσει', 'due_days' => 30]);

        $this->invoice = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΤΙΜ1', 'code' => 1,
            'invoice_type_id' => $type->id, 'customer_id' => $customer->id, 'issued_at' => now(),
            'local_status' => 'active', 'payment_method_id' => $credit->id,
        ]);
        $this->invoice->forceFill(['net_total' => 100, 'gross_total' => 100])->save();
    }

    private function pay(float $amount): Payment
    {
        return Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->invoice->customer_id,
            'invoice_id' => $this->invoice->id, 'amount' => $amount, 'pay_date' => now(),
        ]);
    }

    private function balance(): InvoiceBalanceData
    {
        return app(InvoiceBalance::class)->for($this->invoice->fresh());
    }

    public function test_partial_payment_leaves_invoice_partially_paid(): void
    {
        $this->pay(40);
        $b = $this->balance();
        $this->assertSame(40.0, (float) $b->paid);
        $this->assertSame(60.0, (float) $b->balance);
        $this->assertSame(PaymentStatus::Partial, $b->status); // partially paid

    }

    public function test_overpayment_is_allowed_and_marks_overpaid(): void
    {
        $this->pay(120);
        $b = $this->balance();
        $this->assertSame(-20.0, (float) $b->balance);
        $this->assertSame(PaymentStatus::Overpaid, $b->status);
    }

    public function test_mark_unpaid_deletes_payments_and_restores_full_balance(): void
    {
        $this->pay(100);                  // fully paid → balance 0
        $this->assertSame(0.0, (float) $this->balance()->balance);

        // «Σήμανση ως ανεξόφλητο» = delete all payments (PaymentObserver recomputes).
        foreach ($this->invoice->payments()->get() as $p) {
            $p->delete();
        }

        $b = $this->balance();
        $this->assertSame(0.0, (float) $b->paid);
        $this->assertSame(100.0, (float) $b->balance);
        $this->assertSame(PaymentStatus::Unpaid, $b->status);
    }
}
