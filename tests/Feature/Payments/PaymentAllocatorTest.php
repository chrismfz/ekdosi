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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_transaction_id_is_stamped_on_every_row_of_the_receipt(): void
    {
        // €2000 over €1500 → A + B settled + €500 on-account; the same Stripe/
        // bank txn id rides on all three rows (group-level identifier).
        $res = app(PaymentAllocator::class)->allocate(
            $this->customer, 2000, now(), null, null, null, 'pi_3RxAbC123',
        );

        $rows = Payment::where('reference', $res->reference)->get();
        $this->assertCount(3, $rows); // A, B, on-account
        foreach ($rows as $row) {
            $this->assertSame('pi_3RxAbC123', $row->transaction_id);
        }
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

    public function test_allocate_to_invoice_targets_the_chosen_caps_at_its_balance_and_parks_remainder(): void
    {
        // Pay €800 onto the NEWER B (€500) — a targeted receipt, not FIFO: B is
        // settled (capped at its own €500), the €300 overpayment is parked
        // on-account, and the older A (€1000) is NOT touched.
        $res = app(PaymentAllocator::class)->allocateToInvoice($this->customer, $this->b, 800, now());

        $this->assertSame(0.0, $this->balance($this->b), 'chosen invoice settled (capped at €500)');
        $this->assertSame(1000.0, $this->balance($this->a), 'the oldest was NOT touched (targeted, not FIFO)');
        $this->assertSame(500.0, $res->allocatedToInvoices(), 'only the invoice balance landed on it');
        $this->assertSame(300.0, $res->onAccount, '€300 overpayment parked on-account');
    }

    /**
     * The lock fix reads each invoice balance as a LOCKING/current read (FOR
     * UPDATE) inside the allocation transaction, so two concurrent receipts on the
     * same invoice serialise their check-then-write instead of both capping at the
     * same pre-write balance and overpaying. On sqlite (no FOR UPDATE / row MVCC)
     * this proves the portable half — the locking code path computes the SAME
     * correct allocation even when nested under an OUTER transaction that already
     * read `payments` first (the REPEATABLE-READ snapshot hazard, cf. MON-3 in
     * InvoiceBalanceTest). The true lost-update-under-contention proof is
     * MariaDB-only (deferred, like the InvoiceNumberer / InvoiceBalance probes).
     */
    public function test_locking_allocation_is_correct_when_nested_under_an_outer_read(): void
    {
        // FIFO allocate(): €1200 over €1500 → A full, B €300 owed — unchanged by
        // the locking read, even after an outer read pins the payments snapshot.
        $fifo = DB::transaction(function () {
            DB::table('payments')->count(); // establish the outer read view FIRST

            return app(PaymentAllocator::class)->allocate($this->customer, 1200, now());
        });
        $this->assertSame(0.0, $this->balance($this->a));
        $this->assertSame(300.0, $this->balance($this->b));
        $this->assertSame(0.0, $fifo->onAccount);
        $this->assertSame(1200.0, $fifo->allocatedToInvoices());

        // Invoice-targeted allocateToInvoice(): pay the remaining €300 of B under
        // the same nested-read hazard → B settled, nothing over-applied.
        $targeted = DB::transaction(function () {
            DB::table('payments')->count();

            return app(PaymentAllocator::class)->allocateToInvoice($this->customer, $this->b, 300, now());
        });
        $this->assertSame(0.0, $this->balance($this->b), 'B settled, not overpaid');
        $this->assertSame(300.0, $targeted->allocatedToInvoices());
        $this->assertSame(0.0, $targeted->onAccount);
    }
}
