<?php

namespace Tests\Feature\Payments;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\InvoiceBalance;
use App\Services\Payments\PaymentAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * #1 apply on-account credit to an invoice (re-point, net-zero) +
 * #2 manual per-invoice allocation of an έμβασμα.
 */
class ApplyCreditAndManualAllocationTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private int $creditMethodId;

    private int $typeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create(['name' => 'AC', 'slug' => 'ac-'.uniqid(), 'country_code' => 'GR']);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Π', 'afm' => '123456789']);
        $this->typeId = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΤΙΜ', 'name' => 'Τ', 'invcount' => 1, 'mydata_type' => '1.1'])->id;
        $this->creditMethodId = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Επί Πιστώσει', 'due_days' => 30])->id;
    }

    private function invoice(string $code, float $gross): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => $code, 'code' => random_int(1, 99999),
            'invoice_type_id' => $this->typeId, 'customer_id' => $this->customer->id, 'issued_at' => now()->subDays(5),
            'local_status' => 'active', 'payment_method_id' => $this->creditMethodId,
        ]);
        $inv->forceFill(['net_total' => $gross, 'gross_total' => $gross])->save();

        return $inv;
    }

    private function balance(Invoice $inv): float
    {
        return (float) app(InvoiceBalance::class)->for($inv->fresh())->balance;
    }

    private function onAccountCredit(): Payment
    {
        return Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'invoice_id' => null, 'kind' => 'payment', 'amount' => 500, 'pay_date' => now(), 'reference' => 'ΕΙΣ-X',
        ]);
    }

    public function test_apply_credit_moves_on_account_money_onto_invoice(): void
    {
        $inv = $this->invoice('ΤΙΜ1', 300);
        $this->onAccountCredit(); // €500 on-account

        $allocator = app(PaymentAllocator::class);
        $this->assertSame(500.0, $allocator->availableCredit($this->customer));

        $applied = $allocator->applyCredit($this->customer, $inv, 300);

        $this->assertSame(300.0, $applied);
        $this->assertSame(0.0, $this->balance($inv), 'invoice settled from credit');
        $this->assertSame(200.0, $allocator->availableCredit($this->customer), 'remaining credit 500−300');
        // No money was created: still exactly €500 of payments total.
        $this->assertSame(500.0, (float) Payment::where('customer_id', $this->customer->id)->sum('amount'));
    }

    public function test_apply_credit_stamps_its_own_reference(): void
    {
        $inv = $this->invoice('ΤΙΜ1b', 300);
        $credit = $this->onAccountCredit(); // €500, reference 'ΕΙΣ-X'

        app(PaymentAllocator::class)->applyCredit($this->customer, $inv, 300);

        // The applied portion (now pointing at the invoice) reads as its OWN
        // event (ΕΦΑ-…), not folded into the original έμβασμα 'ΕΙΣ-X' group.
        $applied = Payment::where('invoice_id', $inv->id)->first();
        $this->assertNotNull($applied);
        $this->assertStringStartsWith('ΕΦΑ-', (string) $applied->reference);
        // The leftover on-account row keeps its original έμβασμα reference.
        $this->assertSame('ΕΙΣ-X', Payment::whereNull('invoice_id')->first()->reference);
    }

    public function test_apply_credit_is_capped_by_invoice_balance(): void
    {
        $inv = $this->invoice('ΤΙΜ2', 120);
        $this->onAccountCredit(); // €500

        $applied = app(PaymentAllocator::class)->applyCredit($this->customer, $inv, 500);

        $this->assertSame(120.0, $applied, 'capped at the invoice balance');
        $this->assertSame(0.0, $this->balance($inv));
        $this->assertSame(380.0, app(PaymentAllocator::class)->availableCredit($this->customer));
    }

    public function test_manual_allocation_places_exact_amounts(): void
    {
        $a = $this->invoice('ΤΙΜ3', 1000);
        $b = $this->invoice('ΤΙΜ4', 1000);

        app(PaymentAllocator::class)->allocateManual($this->customer, [
            ['invoice_id' => $a->id, 'amount' => 200],
            ['invoice_id' => $b->id, 'amount' => 700],
        ], now());

        $this->assertSame(800.0, $this->balance($a), '1000 − 200');
        $this->assertSame(300.0, $this->balance($b), '1000 − 700');
    }

    public function test_manual_allocation_rejects_foreign_invoice(): void
    {
        $other = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Άλλος', 'afm' => '999999999']);
        $foreign = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΤΙΜ9', 'code' => 9,
            'invoice_type_id' => $this->typeId, 'customer_id' => $other->id, 'issued_at' => now(),
            'local_status' => 'active', 'payment_method_id' => $this->creditMethodId,
        ]);

        $this->expectException(InvalidArgumentException::class);
        app(PaymentAllocator::class)->allocateManual($this->customer, [
            ['invoice_id' => $foreign->id, 'amount' => 50],
        ], now());
    }
}
