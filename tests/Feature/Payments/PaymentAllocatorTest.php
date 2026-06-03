<?php

namespace Tests\Feature\Payments;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Services\InvoiceBalance;
use App\Services\Payments\PaymentAllocator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One «έμβασμα» allocated FIFO across open invoices + on-account remainder.
 */
class PaymentAllocatorTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private Invoice $a; // €1000, older

    private Invoice $b; // €500, newer

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create(['name' => 'Alloc', 'slug' => 'al-'.uniqid(), 'country_code' => 'GR']);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Π', 'afm' => '123456789']);
        $type = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΤΙΜ', 'name' => 'Τ', 'invcount' => 1, 'mydata_type' => '1.1']);
        $credit = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Επί Πιστώσει', 'due_days' => 30]);

        $this->a = $this->invoice('ΤΙΜ1', 1000, now()->subDays(10), $type->id, $credit->id);
        $this->b = $this->invoice('ΤΙΜ2', 500, now()->subDays(2), $type->id, $credit->id);
    }

    private function invoice(string $code, float $gross, Carbon $issued, int $typeId, int $methodId): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => $code, 'code' => random_int(1, 99999),
            'invoice_type_id' => $typeId, 'customer_id' => $this->customer->id, 'issued_at' => $issued,
            'local_status' => 'active', 'payment_method_id' => $methodId,
        ]);
        $inv->forceFill(['net_total' => $gross, 'gross_total' => $gross])->save();

        return $inv;
    }

    private function balance(Invoice $inv): float
    {
        return (float) app(InvoiceBalance::class)->for($inv->fresh())->balance;
    }

    public function test_partial_receipt_settles_oldest_first(): void
    {
        // owes 1500, pays 1200 → A fully (1000), B partial (200), 300 still owed.
        $res = app(PaymentAllocator::class)->allocate($this->customer, 1200, now());

        $this->assertSame(0.0, $this->balance($this->a));
        $this->assertSame(300.0, $this->balance($this->b));
        $this->assertSame(0.0, $res->onAccount);
        $this->assertSame(1200.0, $res->allocatedToInvoices());
        $this->assertDatabaseMissing('payments', ['reference' => $res->reference, 'invoice_id' => null]);
    }

    public function test_draft_invoices_are_not_paid_amount_goes_on_account(): void
    {
        // A DRAFT credit-term invoice must NOT receive a receipt (not yet issued).
        $draft = $this->a;
        $draft->update(['local_status' => 'draft']); // A is now draft
        // B stays active (€500).

        $res = app(PaymentAllocator::class)->allocate($this->customer, 1000, now());

        $this->assertSame(1000.0, $this->balance($draft), 'draft stays fully owed');
        $this->assertSame(0.0, $this->balance($this->b), 'active B settled (€500)');
        $this->assertSame(500.0, $res->onAccount, 'the €500 that could not land on the draft → on-account');
    }

    public function test_overpayment_settles_all_and_parks_remainder_on_account(): void
    {
        // owes 1500, pays 2000 → both settled + 500 on-account credit.
        $res = app(PaymentAllocator::class)->allocate($this->customer, 2000, now());

        $this->assertSame(0.0, $this->balance($this->a));
        $this->assertSame(0.0, $this->balance($this->b));
        $this->assertSame(500.0, $res->onAccount);
        $this->assertDatabaseHas('payments', [
            'reference' => $res->reference, 'invoice_id' => null, 'customer_id' => $this->customer->id,
        ]);
    }
}
