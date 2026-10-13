<?php

namespace Tests\Feature\Payments;

use App\Console\Commands\MigrateFromFirebird;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\InvoiceBalance;
use App\Services\Payments\ImportedCreditPool;
use App\Services\Payments\PaymentAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The import cleanup: FIFO-link a customer's imported «έναντι» on-account credits
 * onto their open invoices — net-zero, idempotent, pool-scoped — via
 * PaymentAllocator + the payments:apply-imported-credits command. Two pools:
 * Epsilon (transaction_id EPS:…) and the legacy Έκδοση ETL (legacy_id set).
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
        $this->assertSame(0.0, $allocator->availableCredit($this->customer, ImportedCreditPool::epsilon()), 'all EPS credit linked');
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
        $this->assertSame(0.0, $allocator->availableCredit($this->customer, ImportedCreditPool::epsilon()));
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

    private function legacyInvoice(string $code, float $gross, int $ageDays, int $legacyId): Invoice
    {
        $inv = $this->invoice($code, $gross, $ageDays);
        $inv->forceFill(['legacy_id' => $legacyId])->save();

        return $inv;
    }

    private function legacyCredit(float $amount, int $legacyId, int $ageDays): Payment
    {
        // What migrate:firebird writes: legacy PAYMENT_ID, no invoice, no transaction_id.
        return Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'invoice_id' => null, 'kind' => 'payment', 'amount' => $amount,
            'pay_date' => now()->subDays($ageDays), 'legacy_id' => $legacyId,
        ]);
    }

    public function test_firebird_pool_links_legacy_credit_and_leaves_eps_and_manual_alone(): void
    {
        $a = $this->legacyInvoice('ΤΠΥ1', 186, 4000, 96);
        $b = $this->legacyInvoice('ΤΠΥ2', 338, 3900, 237);
        $this->legacyCredit(150, 12, 3950);
        $this->legacyCredit(300, 16, 3800);
        $eps = $this->epsCredit(40, 'X', 10);
        $manual = Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'invoice_id' => null, 'kind' => 'payment', 'amount' => 25, 'pay_date' => now(),
        ]);

        $allocator = app(PaymentAllocator::class);
        $pool = ImportedCreditPool::firebird();

        $plan = $allocator->simulateImportedCreditsFifo($this->customer, $pool);
        $result = $allocator->applyImportedCreditsFifo($this->customer, $pool);

        $this->assertSame(450.0, $result['total_applied']);
        $this->assertSame($plan['allocations'], $result['allocations'], 'dry-run == real');
        $this->assertSame([['invcode' => 'ΤΠΥ1', 'amount' => 186.0], ['invcode' => 'ΤΠΥ2', 'amount' => 264.0]], $result['allocations']);
        $this->assertSame(0.0, $this->balance($a));
        $this->assertSame(74.0, $this->balance($b), '338 − 264');
        $this->assertStringStartsWith('ΕΦΑ-FB-', $result['reference']);

        // Net-zero (450 legacy + 40 EPS + 25 manual), other pools untouched.
        $this->assertSame(515.0, (float) Payment::where('customer_id', $this->customer->id)->sum('amount'));
        $this->assertNull($eps->fresh()->invoice_id);
        $this->assertNull($manual->fresh()->invoice_id);
        $this->assertSame(0.0, $allocator->availableCredit($this->customer, $pool));
        $this->assertSame(65.0, $allocator->availableCredit($this->customer));
    }

    public function test_firebird_split_keeps_legacy_remainder_in_the_pool(): void
    {
        // One legacy €300 payment, €186 open → split: 186 applied, 114 stays έναντι
        // carrying the legacy_id (so a re-run still sees it as imported credit).
        $a = $this->legacyInvoice('ΤΠΥ1', 186, 100, 96);
        $legacy = $this->legacyCredit(300, 16, 90);

        $allocator = app(PaymentAllocator::class);
        $allocator->applyImportedCreditsFifo($this->customer, ImportedCreditPool::firebird());

        $this->assertSame(0.0, $this->balance($a));
        $legacy->refresh();
        $this->assertNull($legacy->invoice_id);
        $this->assertSame(114.0, (float) $legacy->amount);
        $this->assertSame(16, (int) $legacy->legacy_id);
        $this->assertSame(114.0, $allocator->availableCredit($this->customer, ImportedCreditPool::firebird()));

        // The split-off applied part carries the legacy PAYMENT_ID (trail + ETL re-run guard).
        $part = Payment::where('customer_id', $this->customer->id)->where('invoice_id', $a->id)->sole();
        $this->assertSame(186.0, (float) $part->amount);
        $this->assertNull($part->legacy_id);
        $this->assertSame(PaymentAllocator::LEGACY_SPLIT_PREFIX.'16', $part->transaction_id);

        $again = $allocator->applyImportedCreditsFifo($this->customer, ImportedCreditPool::firebird());
        $this->assertSame(0.0, $again['total_applied'], 'no open invoice left → no-op');

        // An ETL re-run re-writes this row from the legacy VALUE (300): it must
        // subtract the split part, or the customer would be credited 186 twice.
        $this->assertSame(114.0, MigrateFromFirebird::legacyPaymentAmount($this->tenant->id, 16, '300.00'));
        $this->assertSame('70.00', MigrateFromFirebird::legacyPaymentAmount($this->tenant->id, 17, '70.00'), 'unsplit row: VALUE as-is');
    }

    public function test_after_firebird_sweep_a_new_receipt_lands_on_the_genuinely_open_invoice(): void
    {
        // The live symptom: legacy invoices fully covered by legacy on-account
        // payments, then a fresh invoice. Before the sweep a FIFO receipt landed on
        // the oldest legacy invoice; after it, it must land on the fresh one.
        $old = $this->legacyInvoice('ΤΠΥ1', 186, 4000, 96);
        $this->legacyCredit(186, 12, 3950);
        $fresh = $this->invoice('ΤΠΥ9001', 500, 3);

        $this->artisan('payments:apply-imported-credits', [
            '--company' => $this->tenant->slug, '--source' => 'firebird',
        ])->assertSuccessful();

        $receipt = app(PaymentAllocator::class)->allocate($this->customer, 100, now());

        $this->assertSame([['invcode' => 'ΤΠΥ9001', 'amount' => 100.0]], $receipt->allocations);
        $this->assertSame(0.0, $this->balance($old));
        $this->assertSame(400.0, $this->balance($fresh));
    }

    public function test_command_default_source_ignores_legacy_credit_and_rejects_unknown_source(): void
    {
        $a = $this->legacyInvoice('ΤΠΥ1', 186, 100, 96);
        $this->legacyCredit(186, 12, 90);

        $this->artisan('payments:apply-imported-credits', ['--company' => $this->tenant->slug])
            ->expectsOutputToContain('--source=firebird')
            ->assertSuccessful();
        $this->assertSame(186.0, $this->balance($a), 'default eps pool must not touch legacy rows');

        $this->artisan('payments:apply-imported-credits', ['--company' => $this->tenant->slug, '--source' => 'nope'])
            ->assertFailed();
        $this->assertSame(186.0, $this->balance($a));
    }

    public function test_firebird_pool_skips_non_positive_legacy_rows_and_dry_run_matches_real(): void
    {
        // The ETL writes raw, so a legacy zero/negative correction row can exist.
        // It must stay out of the pool (re-pointing it trips the amount > 0 guard)
        // and dry-run and real run must agree instead of the real run failing.
        $a = $this->legacyInvoice('ΤΠΥ1', 100, 100, 96);
        $neg = $this->legacyCredit(150, 30, 95);
        DB::table('payments')->where('id', $neg->id)->update(['amount' => -20]);
        $zero = $this->legacyCredit(150, 31, 94);
        DB::table('payments')->where('id', $zero->id)->update(['amount' => 0]);
        $this->legacyCredit(150, 32, 90);

        $allocator = app(PaymentAllocator::class);
        $pool = ImportedCreditPool::firebird();

        $plan = $allocator->simulateImportedCreditsFifo($this->customer, $pool);
        $result = $allocator->applyImportedCreditsFifo($this->customer, $pool);

        $this->assertSame(150.0, $plan['credit_before'], 'only the positive row counts');
        $this->assertSame($plan['allocations'], $result['allocations']);
        $this->assertSame($plan['credit_left'], $result['credit_left']);
        $this->assertSame(0.0, $this->balance($a));
        $this->assertSame(-20.0, (float) $neg->fresh()->amount, 'non-positive rows untouched');
        $this->assertNull($neg->fresh()->invoice_id);
        $this->assertSame(0.0, (float) $zero->fresh()->amount);
        $this->assertNull($zero->fresh()->invoice_id);
    }

    public function test_firebird_surplus_stays_on_account_and_never_lands_on_an_app_issued_invoice(): void
    {
        // Legacy credit > legacy debt: the surplus must NOT spill onto an invoice
        // issued in the app (its WHMCS link would push «paid» to WHMCS) — it stays
        // έναντι for the operator to apply deliberately.
        $old = $this->legacyInvoice('ΤΠΥ1', 100, 4000, 96);
        $this->legacyCredit(250, 12, 3950);
        $fresh = $this->invoice('ΤΠΥ9001', 500, 3);

        $allocator = app(PaymentAllocator::class);
        $pool = ImportedCreditPool::firebird();
        $plan = $allocator->simulateImportedCreditsFifo($this->customer, $pool);
        $result = $allocator->applyImportedCreditsFifo($this->customer, $pool);

        $this->assertSame([['invcode' => 'ΤΠΥ1', 'amount' => 100.0]], $result['allocations']);
        $this->assertSame($plan['allocations'], $result['allocations']);
        $this->assertSame(150.0, $result['credit_left']);
        $this->assertSame(0.0, $this->balance($old));
        $this->assertSame(500.0, $this->balance($fresh), 'app-issued invoice untouched');
        $this->assertSame(0, Payment::where('invoice_id', $fresh->id)->count());
    }

    public function test_etl_amount_subtracts_every_live_split_part_and_never_goes_negative(): void
    {
        $part = fn (float $amount, int $legacyId) => Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'invoice_id' => $this->invoice('ΤΙΜ'.uniqid(), 1000, 1)->id, 'kind' => 'payment', 'amount' => $amount,
            'pay_date' => now(), 'transaction_id' => PaymentAllocator::LEGACY_SPLIT_PREFIX.$legacyId,
        ]);
        $part(100, 40);
        $part(50, 40);
        $part(70, 40)->delete(); // a removed part no longer reduces the row

        $this->assertSame(150.0, MigrateFromFirebird::legacyPaymentAmount($this->tenant->id, 40, '300.00'));
        $this->assertSame(0.0, MigrateFromFirebird::legacyPaymentAmount($this->tenant->id, 40, '120.00'), 'clamped at zero');

        // Another tenant's FB-SPLIT:40 must not count.
        $other = Company::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'country_code' => 'GR']);
        $this->assertSame('300.00', MigrateFromFirebird::legacyPaymentAmount($other->id, 40, '300.00'));
    }
}
