<?php

namespace Tests\Feature;

use App\Jobs\SendInvoiceEmail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceMailLog;
use App\Models\InvoiceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The batch mail sweep: re-queue invoice emails whose LATEST send attempt
 * failed, and skip ones that later succeeded / never failed / failed long ago.
 */
class ResendFailedInvoiceEmailsTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $type;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->tenant = Company::create([
            'name' => 'Acme', 'slug' => 'sweep-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'Τ', 'invcount' => 1, 'mydata_type' => '1.1',
        ]);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'C', 'email' => 'c@example.com']);
    }

    private function invoice(string $invcode): Invoice
    {
        return Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => $invcode, 'code' => (int) filter_var($invcode, FILTER_SANITIZE_NUMBER_INT) ?: 1,
            'invoice_type_id' => $this->type->id, 'customer_id' => $this->customer->id,
            // DOC-6: the sweep only re-queues ISSUED (active) invoices — a failed
            // send implies the invoice was issued. Realistic fixture state.
            'local_status' => 'active',
            'issued_at' => now(), 'company_name' => 'C', 'net_total' => 100, 'gross_total' => 124,
        ]);
    }

    private function log(Invoice $invoice, string $status, $createdAt): void
    {
        $log = InvoiceMailLog::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $invoice->id,
            'recipient' => 'c@example.com', 'status' => $status, 'trigger' => 'auto',
        ]);
        // created_at isn't fillable; set it explicitly so the "latest log" order
        // (orderByDesc created_at) is deterministic.
        $log->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();
    }

    public function test_only_currently_failed_invoices_are_requeued(): void
    {
        // (a) latest = failed → RE-QUEUE
        $failed = $this->invoice('TPY1');
        $this->log($failed, 'failed', now()->subHour());

        // (b) failed then later sent → SKIP (superseded)
        $recovered = $this->invoice('TPY2');
        $this->log($recovered, 'failed', now()->subHours(3));
        $this->log($recovered, 'sent', now()->subHour());

        // (c) only ever sent → SKIP
        $sent = $this->invoice('TPY3');
        $this->log($sent, 'sent', now()->subHour());

        // (d) failed but OUTSIDE the --since window → SKIP
        $old = $this->invoice('TPY4');
        $this->log($old, 'failed', now()->subDays(30));

        $this->artisan('invoices:resend-failed-emails', ['--tenant' => $this->tenant->slug, '--since' => 7])
            ->assertExitCode(0);

        Queue::assertPushed(SendInvoiceEmail::class, 1);
        Queue::assertPushed(fn (SendInvoiceEmail $job) => $job->invoice->is($failed) && $job->trigger === 'batch');
    }

    public function test_cancelled_invoices_are_never_requeued(): void
    {
        // DOC-6: a failed send whose invoice was later cancelled must NOT be
        // re-queued — else the sweep churns it forever and would email a
        // «…που εκδόθηκε…» body for a voided document.
        $locallyCancelled = $this->invoice('TPY1');
        $locallyCancelled->forceFill(['local_status' => 'cancelled'])->save();
        $this->log($locallyCancelled, 'failed', now()->subHour());

        $aadeCancelled = $this->invoice('TPY2');
        $aadeCancelled->forceFill(['mydata_state' => 'CANCELLED'])->save();
        $this->log($aadeCancelled, 'failed', now()->subHour());

        // A still-active failed one IS re-queued (control).
        $active = $this->invoice('TPY3');
        $this->log($active, 'failed', now()->subHour());

        $this->artisan('invoices:resend-failed-emails', ['--tenant' => $this->tenant->slug, '--since' => 7])
            ->assertExitCode(0);

        Queue::assertPushed(SendInvoiceEmail::class, 1);
        Queue::assertPushed(fn (SendInvoiceEmail $job) => $job->invoice->is($active));
    }

    public function test_dry_run_dispatches_nothing(): void
    {
        $failed = $this->invoice('TPY1');
        $this->log($failed, 'failed', now()->subHour());

        $this->artisan('invoices:resend-failed-emails', ['--tenant' => $this->tenant->slug, '--dry-run' => true])
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }
}
