<?php

namespace Tests\Feature\CustomerLedger;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\CustomerLedger\CustomerLedgerBuilder;
use App\Services\CustomerLedger\ReceiptAllocationSummary;
use App\Services\Payments\PaymentAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Φ3 — collapse the N Payment rows of one «έμβασμα/είσπραξη» (sharing one
 * `payments.reference`) into ONE ledger credit row with a drill-down
 * `allocations` list. Grouping N credits of total X into one credit of X
 * must NOT change the running balance (verified here + in
 * CustomerLedgerBuilderTest).
 */
class CustomerLedgerReceiptGroupingTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private InvoiceType $type;

    private PaymentMethod $credit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Φ3', 'slug' => 'f3-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Π']);
        $this->credit = PaymentMethod::create([
            'company_id' => $this->tenant->id, 'description' => 'Επί Πιστώσει',
            'name' => 'Credit', 'due_days' => 30, 'is_active' => true,
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΤΙΜ', 'name' => 'Τ',
            'invcount' => 1, 'mydata_type' => '1.1', 'payment_method_id' => $this->credit->id,
        ]);
    }

    private function invoice(string $code, float $gross, string $issuedAt): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => $code, 'code' => random_int(1, 99999),
            'invoice_type_id' => $this->type->id, 'customer_id' => $this->customer->id,
            'issued_at' => $issuedAt, 'local_status' => 'active',
            'payment_method_id' => $this->credit->id,
        ]);
        $inv->forceFill(['net_total' => $gross, 'gross_total' => $gross])->save();

        return $inv;
    }

    public function test_embasma_collapses_to_one_row_with_allocations(): void
    {
        // Two credit-term invoices €1000 + €500, then an έμβασμα €1200 via
        // PaymentAllocator → ΤΙΜ1 fully (1000), ΤΙΜ2 partial (200), no remainder.
        $a = $this->invoice('ΤΙΜ1', 1000.0, now()->subDays(10)->toDateString());
        $b = $this->invoice('ΤΙΜ2', 500.0, now()->subDays(5)->toDateString());

        app(PaymentAllocator::class)->allocate($this->customer, 1200.0, now());

        $ledger = app(CustomerLedgerBuilder::class)->buildLedgerOnly($this->customer);

        // 2 invoice rows + exactly ONE collapsed payment row.
        $paymentRows = array_values(array_filter($ledger, fn ($r) => $r['type'] === 'payment'));
        $this->assertCount(1, $paymentRows);

        $receipt = $paymentRows[0];
        $this->assertTrue($receipt['is_receipt_group']);
        $this->assertSame(1200.0, $receipt['credit']);
        $this->assertNull($receipt['payment_id']);
        $this->assertStringContainsString('Έμβασμα', $receipt['reference']);

        // Two allocations: ΤΙΜ1 €1000 + ΤΙΜ2 €200 (no on-account remainder).
        $this->assertCount(2, $receipt['allocations']);
        $byCode = collect($receipt['allocations'])->keyBy('invcode');
        $this->assertSame(1000.0, $byCode['ΤΙΜ1']['amount']);
        $this->assertSame(200.0, $byCode['ΤΙΜ2']['amount']);
        $this->assertSame($a->id, $byCode['ΤΙΜ1']['invoice_id']);
        $this->assertSame($b->id, $byCode['ΤΙΜ2']['invoice_id']);

        // Allocations sum to the row credit.
        $this->assertSame(1200.0, round(collect($receipt['allocations'])->sum('amount'), 2));
    }

    public function test_running_balance_identical_to_separate_credits(): void
    {
        // Φ3 invariant: grouping must NOT move the running balance.
        $this->invoice('ΤΙΜ1', 1000.0, now()->subDays(10)->toDateString());
        $this->invoice('ΤΙΜ2', 500.0, now()->subDays(5)->toDateString());

        app(PaymentAllocator::class)->allocate($this->customer, 1200.0, now());

        $ledger = app(CustomerLedgerBuilder::class)->buildLedgerOnly($this->customer);
        $receipt = collect($ledger)->firstWhere('is_receipt_group', true);

        // 1000 + 500 invoices - 1200 receipt = 300 outstanding.
        $this->assertSame(300.0, $receipt['running_balance']);
        $this->assertSame(300.0, app(CustomerLedgerBuilder::class)->build($this->customer)->stats['balance']);
    }

    public function test_on_account_remainder_shows_as_pistosi_allocation(): void
    {
        // Overpay: €2000 over €1500 of invoices → both settled + €500 on-account.
        $this->invoice('ΤΙΜ1', 1000.0, now()->subDays(10)->toDateString());
        $this->invoice('ΤΙΜ2', 500.0, now()->subDays(5)->toDateString());

        app(PaymentAllocator::class)->allocate($this->customer, 2000.0, now());

        $ledger = app(CustomerLedgerBuilder::class)->buildLedgerOnly($this->customer);
        $receipt = collect($ledger)->firstWhere('is_receipt_group', true);

        $this->assertSame(2000.0, $receipt['credit']);
        $this->assertCount(3, $receipt['allocations']);
        $onAccount = collect($receipt['allocations'])->firstWhere('invoice_id', null);
        $this->assertNotNull($onAccount);
        $this->assertSame('Πίστωση / προκαταβολή', $onAccount['label']);
        $this->assertSame(500.0, $onAccount['amount']);
    }

    public function test_null_reference_payment_stays_its_own_row(): void
    {
        $this->invoice('ΤΙΜ1', 1000.0, now()->subDays(10)->toDateString());

        // A plain manual / on-account payment with NO reference.
        Payment::create([
            'company_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'invoice_id' => null,
            'amount' => 300.0,
            'pay_date' => now()->toDateString(),
            'reference' => null,
        ]);

        $ledger = app(CustomerLedgerBuilder::class)->buildLedgerOnly($this->customer);
        $paymentRows = array_values(array_filter($ledger, fn ($r) => $r['type'] === 'payment'));

        $this->assertCount(1, $paymentRows);
        $row = $paymentRows[0];
        $this->assertFalse($row['is_receipt_group']);
        $this->assertNull($row['allocations']);
        $this->assertStringStartsWith('Πληρωμή #', $row['reference']);
        $this->assertNotNull($row['payment_id']);
    }

    public function test_grouped_and_ungrouped_coexist(): void
    {
        $this->invoice('ΤΙΜ1', 1000.0, now()->subDays(10)->toDateString());

        // An έμβασμα (referenced) + a separate plain payment (null ref).
        app(PaymentAllocator::class)->allocate($this->customer, 400.0, now()->subDays(3));
        Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'invoice_id' => null, 'amount' => 100.0, 'pay_date' => now()->toDateString(),
            'reference' => null,
        ]);

        $ledger = app(CustomerLedgerBuilder::class)->buildLedgerOnly($this->customer);
        $paymentRows = array_values(array_filter($ledger, fn ($r) => $r['type'] === 'payment'));

        $this->assertCount(2, $paymentRows);
        $grouped = collect($paymentRows)->where('is_receipt_group', true)->values();
        $plain = collect($paymentRows)->where('is_receipt_group', false)->values();
        $this->assertCount(1, $grouped);
        $this->assertCount(1, $plain);
        $this->assertSame(400.0, $grouped[0]['credit']);
        $this->assertSame(100.0, $plain[0]['credit']);

        // Balance unaffected by grouping: 1000 - 400 - 100 = 500.
        $this->assertSame(500.0, app(CustomerLedgerBuilder::class)->build($this->customer)->stats['balance']);
    }

    public function test_single_payment_embasma_still_groups(): void
    {
        // A receipt that lands entirely on one invoice (one Payment row, ref set)
        // still becomes a one-allocation group row — consistent.
        $this->invoice('ΤΙΜ1', 1000.0, now()->subDays(10)->toDateString());

        app(PaymentAllocator::class)->allocate($this->customer, 400.0, now());

        $ledger = app(CustomerLedgerBuilder::class)->buildLedgerOnly($this->customer);
        $receipt = collect($ledger)->firstWhere('is_receipt_group', true);

        $this->assertNotNull($receipt);
        $this->assertSame(400.0, $receipt['credit']);
        $this->assertCount(1, $receipt['allocations']);
        $this->assertSame('ΤΙΜ1', $receipt['allocations'][0]['invcode']);
    }

    public function test_allocation_summary_helper_formats_inline(): void
    {
        $fmt = fn ($v) => number_format((float) $v, 2, ',', '.');
        $summary = ReceiptAllocationSummary::describe('Έμβασμα ΕΙΣ-1', [
            ['label' => 'ΤΙΜ1', 'amount' => 1000.0, 'invoice_id' => 5, 'invcode' => 'ΤΙΜ1'],
            ['label' => 'ΤΙΜ2', 'amount' => 200.0, 'invoice_id' => 6, 'invcode' => 'ΤΙΜ2'],
            ['label' => 'Πίστωση / προκαταβολή', 'amount' => 0.0, 'invoice_id' => null, 'invcode' => null],
        ], $fmt);

        $this->assertSame('Έμβασμα ΕΙΣ-1 (ΤΙΜ1: 1.000,00 · ΤΙΜ2: 200,00 · Πίστωση: 0,00)', $summary);
    }
}
