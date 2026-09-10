<?php

namespace Tests\Feature\WhmcsInbox;

use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Models\VatCategory;
use App\Services\InvoiceBalance;
use App\Services\WhmcsInbox\WhmcsInvoiceFiler;
use App\Services\WhmcsInbox\WhmcsReceiptRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * When a WHMCS invoice is ALREADY PAID at the moment we issue it in ekdosi
 * (the Eurobank vPOS / έμβασμα case), the issue path auto-records the matching
 * receipt on the invoice — the money-trail (gateway + transaction id + date) —
 * so the Καρτέλα shows HOW it was paid instead of just «settled at issue». The
 * receipt is a plain, editable/deletable Payment (no myDATA impact) and nets the
 * invoice to zero. Off-mode tenant → NullSubmitter (no AADE round-trip).
 */
class WhmcsReceiptOnIssueTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private PaymentMethod $cash;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'T', 'slug' => 'r-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        VatCategory::create(['company_id' => $this->tenant->id, 'name' => '24%', 'rate' => 24.00, 'is_default' => true]);
        $this->cash = PaymentMethod::create([
            'company_id' => $this->tenant->id, 'name' => 'Ηλεκτρονικά', 'due_days' => 0, 'is_active' => true,
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1,
            'payment_method_id' => $this->cash->id,
        ]);
        $this->customer = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'ΑΚΜΕ', 'afm' => '111111111',
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra  merged into the payload (status/datepaid/transactions…)
     */
    private function makePending(array $extra = []): PendingWhmcsInvoice
    {
        return PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id,
            'whmcs_invoice_id' => 8888,
            'payload' => array_merge([
                'invoiceid' => 8888,
                'userid' => 1,
                'date' => '2026-05-20',
                'total' => '124.00',
                'items' => ['item' => [['description' => 'Hosting', 'amount' => '124.00', 'taxed' => '1']]],
            ], $extra),
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED,
            'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
            'customer_id' => $this->customer->id,
        ]);
    }

    private function file(PendingWhmcsInvoice $pending): Invoice
    {
        return app(WhmcsInvoiceFiler::class)->file(
            $this->tenant, $pending, $this->customer, $this->type, filedByUserId: null,
        )->invoice;
    }

    private const PAID = [
        'status' => 'Paid',
        'datepaid' => '2026-05-21 10:30:00',
        'paymentmethod' => 'eurobank',
        'transactions' => ['transaction' => [
            ['id' => 1, 'gateway' => 'eurobank', 'transid' => 'VPOS-84213', 'date' => '2026-05-21 10:30:00', 'amountin' => '124.00', 'amountout' => '0.00'],
        ]],
    ];

    public function test_paid_whmcs_invoice_records_the_receipt_and_nets_to_zero(): void
    {
        $invoice = $this->file($this->makePending(self::PAID));

        $payment = Payment::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame('124.00', (string) $payment->amount);
        // Stable WHMCS key in transaction_id (shared with the syncer's dedup); the
        // real vPOS ref lives in the note so both reach the Καρτέλα.
        $this->assertSame('whmcs-paid:8888', $payment->transaction_id);
        $this->assertStringContainsString('VPOS-84213', (string) $payment->notes, 'the real vPOS ref, in the note');
        $this->assertSame('2026-05-21', $payment->pay_date->toDateString());
        $this->assertSame($this->cash->id, $payment->payment_method_id, 'inherits the invoice payment method');
        $this->assertStringContainsString('WHMCS #8888', (string) $payment->notes);

        // Cash-term invoice now tracked from the real row → paid in full, no phantom.
        $data = app(InvoiceBalance::class)->for($invoice->fresh());
        $this->assertSame(PaymentStatus::Paid, $data->status);
        $this->assertSame(0.0, (float) $data->balance);
    }

    public function test_unpaid_whmcs_invoice_records_nothing(): void
    {
        $invoice = $this->file($this->makePending(['status' => 'Unpaid']));

        $this->assertSame(0, Payment::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_paid_without_transaction_detail_falls_back_to_deterministic_id(): void
    {
        $invoice = $this->file($this->makePending([
            'status' => 'Paid',
            'datepaid' => '2026-05-21',
            'paymentmethod' => 'banktransfer',
        ]));

        $payment = Payment::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame('whmcs-paid:8888', $payment->transaction_id);
        $this->assertStringContainsString('banktransfer', (string) $payment->notes);
    }

    public function test_recording_is_idempotent_no_double_receipt(): void
    {
        $pending = $this->makePending(self::PAID);
        $invoice = $this->file($pending);
        $this->assertSame(1, Payment::query()->where('invoice_id', $invoice->id)->count());

        // A second run (e.g. a retry) must not stack a second receipt.
        $again = app(WhmcsReceiptRecorder::class)->recordIfPaid($pending->fresh(), $invoice->fresh());
        $this->assertSame(0.0, $again);
        $this->assertSame(1, Payment::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_no_second_receipt_even_after_the_auto_receipt_is_refunded(): void
    {
        // The dedup is keyed on the shared whmcs-paid id, NOT on the net-paid sum —
        // so a fully-refunded auto-receipt (net back to 0) still can't be re-added
        // (guards finding #3 + the WhmcsPaymentSyncer double after a refund).
        $pending = $this->makePending(self::PAID);
        $invoice = $this->file($pending);

        // Operator refunds the auto-receipt → payments net to 0 on the invoice.
        Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id, 'kind' => 'refund', 'amount' => 124, 'pay_date' => now(),
        ]);

        $again = app(WhmcsReceiptRecorder::class)->recordIfPaid($pending->fresh(), $invoice->fresh());
        $this->assertSame(0.0, $again, 'the whmcs-paid key still exists → no duplicate receipt');
        $this->assertSame(1, Payment::query()->where('invoice_id', $invoice->id)->where('kind', 'payment')->count());
    }
}
