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
use Tests\TestCase;

/**
 * The Epsilon-import cleanup: FIFO-link a customer's imported «έναντι» on-account
 * credits (transaction_id EPS:…) onto their open invoices — net-zero, idempotent,
 * EPS-scoped — via PaymentAllocator + the payments:apply-imported-credits command.
 */
class ApplyImportedCreditsTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private int $creditMethodId;

    private int $typeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create(['name' => 'AIC', 'slug' => 'aic-'.uniqid(), 'country_code' => 'GR']);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Πελάτης', 'afm' => '123456789']);
        $this->typeId = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΤΙΜ', 'name' => 'Τ', 'invcount' => 1, 'mydata_type' => '1.1'])->id;
        $this->creditMethodId = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Επί Πιστώσει', 'due_days' => 30])->id;
    }

    private function invoice(string $code, float $gross, int $ageDays): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => $code, 'code' => random_int(1, 99999),
            'invoice_type_id' => $this->typeId, 'customer_id' => $this->customer->id, 'issued_at' => now()->subDays($ageDays),
            'local_status' => 'active', 'payment_method_id' => $this->creditMethodId,
        ]);
        $inv->forceFill(['net_total' => $gross, 'gross_total' => $gross])->save();

        return $inv;
    }

    private function epsCredit(float $amount, string $key, int $ageDays): Payment
    {
        return Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'invoice_id' => null, 'kind' => 'payment', 'amount' => $amount,
            'pay_date' => now()->subDays($ageDays), 'transaction_id' => 'EPS:R:'.$key,
            'notes' => 'Εισαγωγή Epsilon — έμβασμα πελάτη (έναντι)',
        ]);
    }

    private function balance(Invoice $inv): float
    {
        return (float) app(InvoiceBalance::class)->for($inv->fresh(['paymentMethod']))->balance;
    }

    public function test_sweep_links_eps_credit_fifo_net_zero_and_leaves_manual_advance(): void
    {
        // Oldest → newest. Σ invoices 350 == Σ EPS credit 350.
        $a = $this->invoice('ΤΙΜ1', 100, 30);
        $b = $this->invoice('ΤΙΜ2', 50, 20);
        $c = $this->invoice('ΤΙΜ3', 200, 10);
        $this->epsCredit(300, 'A', 40);
        $this->epsCredit(50, 'B', 35);
        // A genuine (non-EPS) advance that must NOT be touched by the sweep.
        $manual = Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'invoice_id' => null, 'kind' => 'payment', 'amount' => 80,
            'pay_date' => now(), 'reference' => 'ΕΙΣ-manual',
        ]);

        $allocator = app(PaymentAllocator::class);

        // Dry-run preview: correct total, and it writes NOTHING.
        $plan = $allocator->simulateImportedCreditsFifo($this->customer);
        $this->assertSame(350.0, $plan['total_applied']);
        $this->assertSame(100.0, $this->balance($a), 'simulate must not write');

        $result = $allocator->applyImportedCreditsFifo($this->customer);

        $this->assertSame(350.0, $result['total_applied']);
        $this->assertSame(0.0, $this->balance($a));
        $this->assertSame(0.0, $this->balance($b));
        $this->assertSame(0.0, $this->balance($c));

        // Net-zero: total payment magnitude unchanged (350 EPS + 80 manual).
        $this->assertSame(430.0, (float) Payment::where('customer_id', $this->customer->id)->sum('amount'));

        // The genuine advance is still on-account, untouched.
        $this->assertNull($manual->fresh()->invoice_id);
        $this->assertSame(0.0, $allocator->availableCredit($this->customer, 'EPS:'), 'all EPS credit linked');
        $this->assertSame(80.0, $allocator->availableCredit($this->customer), 'only the non-EPS advance remains on-account');

        // Simulate and apply agreed on the per-invoice plan.
        $this->assertSame(
            [
                ['invcode' => 'ΤΙΜ1', 'amount' => 100.0],
                ['invcode' => 'ΤΙΜ2', 'amount' => 50.0],
                ['invcode' => 'ΤΙΜ3', 'amount' => 200.0],
            ],
            $result['allocations'],
        );
        $this->assertSame($plan['allocations'], $result['allocations']);
    }

    public function test_sweep_is_idempotent(): void
    {
        $a = $this->invoice('ΤΙΜ1', 100, 10);
        $this->epsCredit(100, 'A', 20);
        $allocator = app(PaymentAllocator::class);

        $allocator->applyImportedCreditsFifo($this->customer);
        $this->assertSame(0.0, $this->balance($a));

        $second = $allocator->applyImportedCreditsFifo($this->customer);
        $this->assertSame(0.0, $second['total_applied'], 're-run is a no-op');
        $this->assertSame(0.0, $this->balance($a));
    }

    public function test_partial_when_credit_is_less_than_open_invoices(): void
    {
        // €120 EPS credit vs €300 open across two invoices → oldest fully paid,
        // the newer left partially open, remaining credit exhausted.
        $a = $this->invoice('ΤΙΜ1', 100, 20);
        $b = $this->invoice('ΤΙΜ2', 200, 10);
        $this->epsCredit(120, 'A', 30);

        $allocator = app(PaymentAllocator::class);
        $allocator->applyImportedCreditsFifo($this->customer);

        $this->assertSame(0.0, $this->balance($a), 'oldest settled first');
        $this->assertSame(180.0, $this->balance($b), '200 − 20 leftover credit');
        $this->assertSame(0.0, $allocator->availableCredit($this->customer, 'EPS:'));
    }

    public function test_command_dry_run_writes_nothing_then_real_run_links(): void
    {
        $a = $this->invoice('ΤΙΜ1', 100, 10);
        $this->epsCredit(100, 'A', 20);

        $this->artisan('payments:apply-imported-credits', ['--company' => $this->tenant->slug, '--dry-run' => true])
            ->assertSuccessful();
        $this->assertSame(100.0, $this->balance($a), 'dry-run must not write');

        $this->artisan('payments:apply-imported-credits', ['--company' => $this->tenant->slug])
            ->assertSuccessful();
        $this->assertSame(0.0, $this->balance($a), 'real run links the credit');
    }

    public function test_dry_run_matches_real_run_even_with_an_eps_refund(): void
    {
        // The importer creates no EPS refunds, but if one ever existed it must
        // reduce the NET credit identically in the preview and the real run.
        $inv = $this->invoice('ΤΙΜ1', 100, 10);
        $this->epsCredit(100, 'A', 20);
        Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'invoice_id' => null, 'kind' => 'refund', 'amount' => 30,
            'pay_date' => now()->subDays(15), 'transaction_id' => 'EPS:R:REF',
        ]);

        $allocator = app(PaymentAllocator::class);

        $plan = $allocator->simulateImportedCreditsFifo($this->customer);
        $this->assertSame(70.0, $plan['credit_before'], 'net EPS credit = 100 − 30');
        $this->assertSame(70.0, $plan['total_applied']);

        $result = $allocator->applyImportedCreditsFifo($this->customer);
        $this->assertSame($plan['total_applied'], $result['total_applied'], 'dry-run == real');
        $this->assertSame(30.0, $this->balance($inv), 'invoice 100 − 70 applied');
    }
}
