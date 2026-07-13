<?php

namespace Tests\Feature\Whmcs;

use App\Jobs\PushWhmcsPaymentJob;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Services\Whmcs\WhmcsInvoiceFetcher;
use App\Services\Whmcs\WhmcsPaymentPusherFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Auto-push (opt-in): recording a REAL settling payment on a WHMCS-linked
 * επί-πιστώσει invoice dispatches PushWhmcsPaymentJob, which marks the WHMCS
 * invoice paid via WhmcsPaymentPusher. Opt-out / inbound-origin / unlinked /
 * partial payments never dispatch.
 */
class WhmcsAutoPushTest extends TestCase
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

    private function invoice(): Invoice
    {
        return Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΤΙΜ'.uniqid(), 'code' => random_int(1, 99999),
            'invoice_type_id' => $this->type->id, 'customer_id' => $this->customer->id,
            'issued_at' => '2026-05-20 10:00:00', 'net_total' => 100.0, 'gross_total' => 124.0,
            'local_status' => 'active', 'payment_method_id' => $this->creditTerm->id,
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

    private function pay(Invoice $invoice, float $amount = 124.0, ?string $txn = null): void
    {
        Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id, 'invoice_id' => $invoice->id,
            'kind' => 'payment', 'amount' => $amount, 'pay_date' => '2026-05-25', 'transaction_id' => $txn,
        ]);
    }

    public function test_settling_payment_dispatches_the_push_job(): void
    {
        Bus::fake();
        $invoice = $this->invoice();
        $this->filedRow($invoice, 6101);

        $this->pay($invoice);

        Bus::assertDispatched(PushWhmcsPaymentJob::class, fn (PushWhmcsPaymentJob $j) => $j->invoiceId === $invoice->id);
    }

    public function test_no_dispatch_when_opt_out(): void
    {
        Bus::fake();
        $this->tenant->update(['whmcs_push_payments' => false]);
        $invoice = $this->invoice();
        $this->filedRow($invoice, 6102);

        $this->pay($invoice);

        Bus::assertNotDispatched(PushWhmcsPaymentJob::class);
    }

    public function test_no_dispatch_for_inbound_origin_payment(): void
    {
        Bus::fake();
        $invoice = $this->invoice();
        $this->filedRow($invoice, 6103);

        $this->pay($invoice, 124.0, 'whmcs-paid:6103');   // came FROM WHMCS

        Bus::assertNotDispatched(PushWhmcsPaymentJob::class);
    }

    public function test_no_dispatch_without_a_whmcs_link(): void
    {
        Bus::fake();
        $invoice = $this->invoice();   // no filed row

        $this->pay($invoice);

        Bus::assertNotDispatched(PushWhmcsPaymentJob::class);
    }

    public function test_no_dispatch_for_a_partial_payment(): void
    {
        Bus::fake();
        $invoice = $this->invoice();
        $this->filedRow($invoice, 6104);

        $this->pay($invoice, 50.0);   // still open

        Bus::assertNotDispatched(PushWhmcsPaymentJob::class);
    }

    public function test_job_marks_the_whmcs_invoice_paid(): void
    {
        // End-to-end with the queue running sync: the job resolves the tenant's
        // fetch/push closures and marks WHMCS paid.
        $invoice = $this->invoice();
        $row = $this->filedRow($invoice, 6105);

        $pushed = [];
        $this->app->instance(WhmcsInvoiceFetcher::class, new class extends WhmcsInvoiceFetcher
        {
            public function __construct() {}

            public function for(Company $tenant): ?callable
            {
                return fn (int $id): array => ['status' => 'Unpaid', 'balance' => 124.0];
            }
        });
        $this->app->instance(WhmcsPaymentPusherFactory::class, new class($pushed) extends WhmcsPaymentPusherFactory
        {
            public function __construct(private array &$sink) {}

            public function for(Company $tenant): ?callable
            {
                return function (int $id, float $amount, string $tx): void {
                    $this->sink[] = ['id' => $id, 'amt' => $amount, 'tx' => $tx];
                };
            }
        });

        $this->pay($invoice);   // observer → afterCommit → dispatch → (sync) handle

        $this->assertCount(1, $pushed);
        $this->assertSame(6105, $pushed[0]['id']);
        $this->assertNotNull($row->fresh()->whmcs_payment_pushed_at);
    }
}
