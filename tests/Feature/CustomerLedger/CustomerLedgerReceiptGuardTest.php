<?php

namespace Tests\Feature\CustomerLedger;

use App\Filament\Resources\Customers\Pages\CustomerLedger;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Guard on the customer-level «Είσπραξη (έμβασμα)» action: it FIFO-allocates over
 * the customer's OPEN invoices and parks any remainder as on-account credit, so a
 * receipt bigger than the open balance (or a customer with nothing open at all)
 * silently makes the customer πιστωτικός — the real footgun that bit us (a
 * cash-term receipt belongs on the invoice's own «Πληρωμές» tab, not here). The
 * guard forces an explicit acknowledgement exactly when some of the amount would
 * overflow into credit, and stays out of the way when it all lands on invoices.
 */
class CustomerLedgerReceiptGuardTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private PaymentMethod $cash;

    private PaymentMethod $credit;

    private InvoiceType $type;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Guard Test',
            'slug' => 'guard-'.uniqid(),
            'country_code' => 'GR',
        ]);
        $this->cash = PaymentMethod::create([
            'company_id' => $this->tenant->id, 'description' => 'Μετρητοίς', 'due_days' => 0,
        ]);
        $this->credit = PaymentMethod::create([
            'company_id' => $this->tenant->id, 'description' => 'Επί πιστώσει 30', 'due_days' => 30,
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΤΠΥ', 'name' => 'ΤΠΥ', 'invcount' => 0,
        ]);
        $this->customer = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'Πελάτης', 'afm' => '123456789',
        ]);

        $user = User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('x'),
        ]);
        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($this->tenant);
    }

    private function invoice(PaymentMethod $method, float $gross): Invoice
    {
        self::$seq++;
        $inv = Invoice::create([
            'company_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'invoice_type_id' => $this->type->id,
            'payment_method_id' => $method->id,
            'invcode' => 'ΤΠΥ'.self::$seq,
            'code' => self::$seq,
            'issued_at' => now()->subDay(),
            'local_status' => 'active',
            'mydata_state' => 'VALID',
        ]);
        $inv->forceFill(['net_total' => $gross, 'gross_total' => $gross])->save();

        return $inv;
    }

    private function page(): Testable
    {
        return Livewire::test(CustomerLedger::class, ['record' => $this->customer->id]);
    }

    public function test_receipt_on_customer_with_no_open_invoices_requires_acknowledgement(): void
    {
        // Only a cash-term (settled-at-issue) invoice → nothing open to absorb a receipt.
        $this->invoice($this->cash, 50);

        $this->page()
            ->callAction('record_receipt', [
                'amount' => 50,
                'pay_date' => now()->toDateString(),
            ])
            ->assertHasActionErrors(['acknowledge_credit']);

        // Nothing was written while the guard blocked.
        $this->assertSame(0, Payment::query()->where('customer_id', $this->customer->id)->count());
    }

    public function test_receipt_proceeds_once_acknowledged_and_records_on_account_credit(): void
    {
        $this->invoice($this->cash, 50);

        $this->page()
            ->callAction('record_receipt', [
                'amount' => 50,
                'pay_date' => now()->toDateString(),
                'acknowledge_credit' => true,
            ])
            ->assertHasNoActionErrors();

        // Deliberate on-account credit (invoice_id null) — acknowledged.
        $payment = Payment::query()->where('customer_id', $this->customer->id)->sole();
        $this->assertNull($payment->invoice_id);
        $this->assertSame('50.00', (string) $payment->amount);
    }

    public function test_receipt_needs_no_acknowledgement_when_open_credit_term_invoice_exists(): void
    {
        // An open επί-πιστώσει invoice can absorb the receipt → checkbox is hidden,
        // not required, and the έμβασμα allocates straight onto the invoice.
        $inv = $this->invoice($this->credit, 50);

        $this->page()
            ->callAction('record_receipt', [
                'amount' => 50,
                'pay_date' => now()->toDateString(),
            ])
            ->assertHasNoActionErrors();

        $payment = Payment::query()->where('customer_id', $this->customer->id)->sole();
        $this->assertSame($inv->id, $payment->invoice_id);
    }

    public function test_receipt_requires_acknowledgement_when_amount_overshoots_open_balance(): void
    {
        // €50 open credit-term invoice, but a €500 receipt → €450 will be parked
        // on-account. The overflow must be acknowledged even though SOME lands on
        // an invoice (the guard fires on the remainder, not only on zero-open).
        $inv = $this->invoice($this->credit, 50);

        $this->page()
            ->callAction('record_receipt', [
                'amount' => 500,
                'pay_date' => now()->toDateString(),
            ])
            ->assertHasActionErrors(['acknowledge_credit']);

        // With the acknowledgement it proceeds: €50 onto the invoice + €450 on-account.
        $this->page()
            ->callAction('record_receipt', [
                'amount' => 500,
                'pay_date' => now()->toDateString(),
                'acknowledge_credit' => true,
            ])
            ->assertHasNoActionErrors();

        $onInvoice = Payment::query()->where('customer_id', $this->customer->id)->where('invoice_id', $inv->id)->sole();
        $this->assertSame('50.00', (string) $onInvoice->amount);
        $onAccount = Payment::query()->where('customer_id', $this->customer->id)->whereNull('invoice_id')->sole();
        $this->assertSame('450.00', (string) $onAccount->amount);
    }
}
