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
use App\Services\Whmcs\WhmcsPaymentSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inbound payment sync (WHMCS → ekdosi): a filed, WHMCS-linked invoice issued
 * επί πιστώσει (open receivable) that later gets paid in WHMCS is settled in
 * ekdosi by recording a Payment for its outstanding balance — money-write in
 * ekdosi only, only-if-open, dedup'd.
 */
class WhmcsPaymentSyncerTest extends TestCase
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
            'name' => 'PS', 'slug' => 'ps-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Δήμος', 'afm' => '090000045']);
        $this->creditTerm = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Πίστωση', 'due_days' => 30]);
        $this->type = InvoiceType::create(['company_id' => $this->tenant->id, 'name' => 'ΤΙΜ', 'code' => 'ΤΙΜ', 'invcount' => 1]);
    }

    private function openInvoice(float $gross = 124.0, ?PaymentMethod $pm = null): Invoice
    {
        return Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΤΙΜ'.uniqid(), 'code' => random_int(1, 99999),
            'invoice_type_id' => $this->type->id, 'customer_id' => $this->customer->id,
            'issued_at' => '2026-05-20 10:00:00', 'net_total' => round($gross / 1.24, 2), 'gross_total' => $gross,
            'local_status' => 'active', 'payment_method_id' => ($pm ?? $this->creditTerm)->id,
        ]);
    }

    private function filedRow(Invoice $invoice, int $whmcsId): PendingWhmcsInvoice
    {
        return PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id, 'whmcs_invoice_id' => $whmcsId, 'invoice_id' => $invoice->id,
            'payload' => ['status' => 'Paid'], 'match_reason' => PendingWhmcsInvoice::REASON_LINKED,
            'status' => PendingWhmcsInvoice::STATUS_FILED,
        ]);
    }

    private function syncer(): WhmcsPaymentSyncer
    {
        return new WhmcsPaymentSyncer(app(InvoiceBalance::class));
    }

    public function test_records_a_payment_when_the_open_invoice_is_paid_in_whmcs(): void
    {
        $invoice = $this->openInvoice(124.0);
        $this->filedRow($invoice, 5001);

        $result = $this->syncer()->syncTenant(
            $this->tenant,
            fn (int $id) => ['status' => 'Paid', 'datepaid' => '2026-05-22 09:00:00'],
        );

        $this->assertSame(1, $result->recorded);
        $payment = Payment::where('company_id', $this->tenant->id)->where('transaction_id', 'whmcs-paid:5001')->first();
        $this->assertNotNull($payment);
        $this->assertEqualsWithDelta(124.0, (float) $payment->amount, 0.001);
        $this->assertSame('2026-05-22', $payment->pay_date->format('Y-m-d'));
        // The receivable is now closed.
        $this->assertEqualsWithDelta(0.0, app(InvoiceBalance::class)->for($invoice->fresh())->balance, 0.001);
    }

    public function test_does_not_record_when_whmcs_still_unpaid(): void
    {
        $invoice = $this->openInvoice(124.0);
        $this->filedRow($invoice, 5002);

        $result = $this->syncer()->syncTenant($this->tenant, fn (int $id) => ['status' => 'Unpaid']);

        $this->assertSame(0, $result->recorded);
        $this->assertSame(0, Payment::where('company_id', $this->tenant->id)->count());
    }

    public function test_skips_a_cash_term_already_settled_invoice(): void
    {
        // due_days=0 → settled at issue → balance 0 → never over-paid even if WHMCS says Paid.
        $cash = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Μετρητά', 'due_days' => 0]);
        $invoice = $this->openInvoice(124.0, $cash);
        $this->filedRow($invoice, 5003);

        $result = $this->syncer()->syncTenant($this->tenant, fn (int $id) => ['status' => 'Paid']);

        $this->assertSame(0, $result->recorded);
        $this->assertSame(0, Payment::where('company_id', $this->tenant->id)->count());
    }

    public function test_is_idempotent_across_runs(): void
    {
        $invoice = $this->openInvoice(124.0);
        $this->filedRow($invoice, 5004);
        $fetch = fn (int $id) => ['status' => 'Paid', 'datepaid' => '2026-05-22'];

        $this->syncer()->syncTenant($this->tenant, $fetch);
        $second = $this->syncer()->syncTenant($this->tenant, $fetch);

        $this->assertSame(0, $second->recorded, 'second run must not double-record');
        $this->assertSame(1, Payment::where('company_id', $this->tenant->id)->count());
    }
}
