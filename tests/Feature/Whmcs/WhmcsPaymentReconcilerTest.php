<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Models\User;
use App\Services\InvoiceBalance;
use App\Services\Whmcs\WhmcsPaymentReconciler;
use App\Support\Whmcs\WhmcsPaymentSyncCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Read-only detector: builds the worklist of open «επί πιστώσει» invoices that
 * WHMCS now reports Paid, caches it, and bell-notifies new items — WITHOUT
 * writing any money (the operator closes each with a one-click action).
 */
class WhmcsPaymentReconcilerTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private PaymentMethod $creditTerm;

    private InvoiceType $type;

    private User $operator;

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
        $this->operator = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $this->tenant->users()->attach($this->operator);
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

    private function filedRow(Invoice $invoice, int $whmcsId): void
    {
        PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id, 'whmcs_invoice_id' => $whmcsId, 'invoice_id' => $invoice->id,
            'payload' => ['status' => 'Paid'], 'match_reason' => PendingWhmcsInvoice::REASON_LINKED,
            'status' => PendingWhmcsInvoice::STATUS_FILED,
        ]);
    }

    private function reconciler(): WhmcsPaymentReconciler
    {
        return new WhmcsPaymentReconciler(app(InvoiceBalance::class));
    }

    public function test_detects_a_paid_open_invoice_caches_it_and_notifies(): void
    {
        $invoice = $this->openInvoice();
        $this->filedRow($invoice, 8001);

        $inbound = $this->reconciler()->reconcile($this->tenant, fn (int $id) => ['status' => 'Paid']);

        $this->assertSame([$invoice->id], $inbound);
        $this->assertSame([$invoice->id], WhmcsPaymentSyncCache::inboundIds($this->tenant->fresh()));
        // Detection writes NO money.
        $this->assertSame(0, Payment::where('company_id', $this->tenant->id)->count());
        // Bell notification landed for the tenant's operator.
        $this->assertSame(1, $this->operator->fresh()->notifications()->count());
    }

    public function test_does_not_detect_an_unpaid_invoice(): void
    {
        $invoice = $this->openInvoice();
        $this->filedRow($invoice, 8002);

        $inbound = $this->reconciler()->reconcile($this->tenant, fn (int $id) => ['status' => 'Unpaid']);

        $this->assertSame([], $inbound);
        $this->assertSame(0, $this->operator->fresh()->notifications()->count());
    }

    public function test_does_not_detect_a_cash_term_invoice(): void
    {
        $cash = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Μετρητά', 'due_days' => 0]);
        $invoice = $this->openInvoice(124.0, $cash);
        $this->filedRow($invoice, 8003);

        $inbound = $this->reconciler()->reconcile($this->tenant, fn (int $id) => ['status' => 'Paid']);

        $this->assertSame([], $inbound);
    }

    public function test_does_not_detect_an_aade_cancelled_invoice(): void
    {
        $invoice = $this->openInvoice();
        $invoice->forceFill(['mydata_state' => 'CANCELLED'])->save();
        $this->filedRow($invoice, 8004);

        $inbound = $this->reconciler()->reconcile($this->tenant, fn (int $id) => ['status' => 'Paid']);

        $this->assertSame([], $inbound);
    }

    public function test_notifies_only_new_items_across_runs(): void
    {
        $invoice = $this->openInvoice();
        $this->filedRow($invoice, 8005);
        $fetch = fn (int $id) => ['status' => 'Paid'];

        $this->reconciler()->reconcile($this->tenant, $fetch);
        $this->reconciler()->reconcile($this->tenant, $fetch);   // same item, already known

        // Second run must NOT re-ping — still exactly one notification.
        $this->assertSame(1, $this->operator->fresh()->notifications()->count());
        // …but the item stays in the cached worklist.
        $this->assertSame([$invoice->id], WhmcsPaymentSyncCache::inboundIds($this->tenant->fresh()));
    }
}
