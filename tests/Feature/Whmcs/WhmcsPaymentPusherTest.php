<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Services\InvoiceBalance;
use App\Services\Whmcs\PaymentPushResult;
use App\Services\Whmcs\WhmcsPaymentPusher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Outbound push (ekdosi settlement → WHMCS mark-paid) — the four brakes:
 * opt-in, idempotent (claim marker), anti-echo (real ekdosi payment only),
 * live credit-term only. Exercised with injected fetch/push callables (no HTTP).
 */
class WhmcsPaymentPusherTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private PaymentMethod $creditTerm;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'PS', 'slug' => 'ps-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'whmcs_push_payments' => true,
        ]);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Δήμος', 'afm' => '090000045']);
        $this->creditTerm = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Πίστωση', 'due_days' => 30]);
        $this->type = InvoiceType::create(['company_id' => $this->tenant->id, 'name' => 'ΤΙΜ', 'code' => 'ΤΙΜ', 'invcount' => 1]);
    }

    private function pusher(): WhmcsPaymentPusher
    {
        return new WhmcsPaymentPusher(app(InvoiceBalance::class));
    }

    private function invoice(?PaymentMethod $pm = null): Invoice
    {
        return Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΤΙΜ'.uniqid(), 'code' => random_int(1, 99999),
            'invoice_type_id' => $this->type->id, 'customer_id' => $this->customer->id,
            'issued_at' => '2026-05-20 10:00:00', 'net_total' => 100.0, 'gross_total' => 124.0,
            'local_status' => 'active', 'payment_method_id' => ($pm ?? $this->creditTerm)->id,
        ]);
    }

    private function filedRow(Invoice $invoice, int $whmcsId): PendingWhmcsInvoice
    {
        return PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id, 'whmcs_invoice_id' => $whmcsId, 'invoice_id' => $invoice->id,
            'payload' => ['status' => 'Unpaid'], 'match_reason' => PendingWhmcsInvoice::REASON_LINKED,
            'status' => PendingWhmcsInvoice::STATUS_FILED,
        ]);
    }

    /** Record a real ekdosi payment that settles the invoice. */
    private function settle(Invoice $invoice, float $amount = 124.0, ?string $txn = null): void
    {
        Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id, 'invoice_id' => $invoice->id,
            'kind' => 'payment', 'amount' => $amount, 'pay_date' => '2026-05-25', 'transaction_id' => $txn,
        ]);
    }

    /** @param array<int, array{id:int,amt:float,tx:string}> $sink */
    private function recorder(array &$sink): callable
    {
        return function (int $id, float $amount, string $tx) use (&$sink): void {
            $sink[] = ['id' => $id, 'amt' => $amount, 'tx' => $tx];
        };
    }

    public function test_pushes_when_settled_and_whmcs_unpaid(): void
    {
        $invoice = $this->invoice();
        $row = $this->filedRow($invoice, 5101);
        $this->settle($invoice);
        $pushed = [];

        $result = $this->pusher()->push(
            $invoice->fresh(),
            fn (int $id) => ['status' => 'Unpaid', 'balance' => 124.0],
            $this->recorder($pushed),
        );

        $this->assertSame(PaymentPushResult::Pushed, $result);
        $this->assertCount(1, $pushed);
        $this->assertSame(5101, $pushed[0]['id']);
        $this->assertEqualsWithDelta(124.0, $pushed[0]['amt'], 0.001);
        $this->assertSame('ekdosi-paid:'.$invoice->id, $pushed[0]['tx']);
        $this->assertNotNull($row->fresh()->whmcs_payment_pushed_at);   // marker claimed
    }

    public function test_skips_when_tenant_not_opted_in(): void
    {
        $this->tenant->update(['whmcs_push_payments' => false]);
        $invoice = $this->invoice();
        $this->filedRow($invoice, 5102);
        $this->settle($invoice);
        $pushed = [];

        $result = $this->pusher()->push($invoice->fresh(), fn (int $id) => ['status' => 'Unpaid'], $this->recorder($pushed));

        $this->assertSame(PaymentPushResult::Skipped, $result);
        $this->assertCount(0, $pushed);
    }

    public function test_already_paid_stamps_marker_without_pushing(): void
    {
        $invoice = $this->invoice();
        $row = $this->filedRow($invoice, 5103);
        $this->settle($invoice);
        $pushed = [];

        $result = $this->pusher()->push($invoice->fresh(), fn (int $id) => ['status' => 'Paid'], $this->recorder($pushed));

        $this->assertSame(PaymentPushResult::AlreadyPaid, $result);
        $this->assertCount(0, $pushed);
        $this->assertNotNull($row->fresh()->whmcs_payment_pushed_at);
    }

    public function test_anti_echo_does_not_push_an_inbound_synced_settlement(): void
    {
        // Settled ONLY by an inbound-sync payment (whmcs-paid:*) → must never be
        // pushed back to WHMCS (it was already paid there).
        $invoice = $this->invoice();
        $this->filedRow($invoice, 5104);
        $this->settle($invoice, 124.0, 'whmcs-paid:5104');
        $pushed = [];

        $result = $this->pusher()->push($invoice->fresh(), fn (int $id) => ['status' => 'Unpaid', 'balance' => 124.0], $this->recorder($pushed));

        $this->assertSame(PaymentPushResult::Skipped, $result);
        $this->assertCount(0, $pushed);
    }

    public function test_is_idempotent_across_runs(): void
    {
        $invoice = $this->invoice();
        $this->filedRow($invoice, 5105);
        $this->settle($invoice);
        $fetch = fn (int $id) => ['status' => 'Unpaid', 'balance' => 124.0];
        $pushed = [];

        $this->pusher()->push($invoice->fresh(), $fetch, $this->recorder($pushed));
        $second = $this->pusher()->push($invoice->fresh(), $fetch, $this->recorder($pushed));

        $this->assertSame(PaymentPushResult::Skipped, $second, 'second run must not re-push');
        $this->assertCount(1, $pushed);
    }

    public function test_failed_push_releases_the_marker_for_retry(): void
    {
        $invoice = $this->invoice();
        $row = $this->filedRow($invoice, 5106);
        $this->settle($invoice);
        $throwing = function (int $id, float $a, string $tx): void {
            throw new RuntimeException('WHMCS 500');
        };

        $result = $this->pusher()->push($invoice->fresh(), fn (int $id) => ['status' => 'Unpaid', 'balance' => 124.0], $throwing);

        $this->assertSame(PaymentPushResult::Failed, $result);
        $this->assertNull($row->fresh()->whmcs_payment_pushed_at, 'marker released so a later run retries');
    }

    public function test_skips_an_unsettled_invoice(): void
    {
        // Still open locally (no payment) → not a settlement to push.
        $invoice = $this->invoice();
        $this->filedRow($invoice, 5107);
        $pushed = [];

        $result = $this->pusher()->push($invoice->fresh(), fn (int $id) => ['status' => 'Unpaid'], $this->recorder($pushed));

        $this->assertSame(PaymentPushResult::Skipped, $result);
        $this->assertCount(0, $pushed);
    }

    public function test_skips_a_cash_term_invoice(): void
    {
        $cash = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Μετρητά', 'due_days' => 0]);
        $invoice = $this->invoice($cash);
        $this->filedRow($invoice, 5108);
        // cash-term settles at issue; a recorded payment doesn't make it a credit-term receivable
        $this->settle($invoice);
        $pushed = [];

        $result = $this->pusher()->push($invoice->fresh(), fn (int $id) => ['status' => 'Unpaid'], $this->recorder($pushed));

        $this->assertSame(PaymentPushResult::Skipped, $result);
        $this->assertCount(0, $pushed);
    }
}
