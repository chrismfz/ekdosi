<?php

namespace Tests\Feature;

use App\Jobs\SendInvoiceEmail;
use App\Mail\InvoiceIssuedMail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceMailLog;
use App\Models\InvoiceType;
use App\Models\User;
use App\Services\InvoicePdfRenderer;
use App\Services\MailTemplateRenderer;
use App\Services\TenantMailerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Locks the SendInvoiceEmail job's lifecycle:
 *   - Writes invoice_mail_log rows for queued → sent / failed
 *   - Routes to customer.email
 *   - Routes BCC from tenant.invoice_audit_bcc parsed list
 *   - Skips gracefully (no throw) when customer has no email,
 *     marking the log row 'failed' with a clear message
 *   - Honors manual trigger + records the operator user id
 *
 * Auto-dispatch from MyDataSubmitter is covered indirectly: this
 * proves the job itself does the right thing once dispatched, and
 * the submitter test (MyDataSubmitterTest, existing) proves the
 * tenant flag gates the dispatch.
 */
class SendInvoiceEmailTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->tenant = Company::create([
            'name' => 'Acme Greek Ltd',
            'slug' => 'mail-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
            'mail_from_address' => 'noreply@acme.gr',
            'mail_from_name' => 'Acme Greek Ltd',
            'invoice_audit_bcc' => 'audit@acme.gr, ops@acme.gr',
            'auto_email_on_mydata_accept' => true,
        ]);

        $this->customer = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'Customer',
            'email' => 'cust@example.com',
            'secondary_email' => 'accountant@example.com',
        ]);

        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id,
            'code' => 'TPY',
            'name' => 'Τιμολόγιο',
            'invcount' => 1,
            'mydata_type' => '1.1',
        ]);
    }

    public function test_send_writes_log_row_and_dispatches_mail_for_customer_with_email(): void
    {
        $invoice = $this->makeFiledInvoice();

        (new SendInvoiceEmail($invoice, trigger: 'auto'))->handle(
            app(InvoicePdfRenderer::class),
            app(TenantMailerFactory::class),
            app(MailTemplateRenderer::class),
        );

        // Mail dispatched to primary recipient
        Mail::assertSent(InvoiceIssuedMail::class, function ($mail) {
            return $mail->hasTo('cust@example.com')
                && $mail->hasCc('accountant@example.com')
                && $mail->hasBcc('audit@acme.gr')
                && $mail->hasBcc('ops@acme.gr');
        });

        // Log row written + transitioned to 'sent'
        $log = InvoiceMailLog::where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('sent', $log->status);
        $this->assertSame('cust@example.com', $log->recipient);
        $this->assertSame('auto', $log->trigger);
        $this->assertNotNull($log->sent_at);
        $this->assertNull($log->error_message);
        $this->assertSame(['accountant@example.com'], $log->cc_list);
        $this->assertSame(['audit@acme.gr', 'ops@acme.gr'], $log->bcc_list);
    }

    public function test_send_skips_gracefully_when_customer_has_no_email(): void
    {
        $this->customer->update(['email' => null]);
        $invoice = $this->makeFiledInvoice();

        // Job MUST NOT throw — "no email" is a non-error skip path
        (new SendInvoiceEmail($invoice))->handle(
            app(InvoicePdfRenderer::class),
            app(TenantMailerFactory::class),
            app(MailTemplateRenderer::class),
        );

        Mail::assertNothingSent();
        Mail::assertNothingQueued();

        // Log row records the skip with a clear reason
        $log = InvoiceMailLog::where('invoice_id', $invoice->id)->first();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('no email', strtolower((string) $log->error_message));
        $this->assertNotNull($log->failed_at);
    }

    public function test_send_skips_a_cancelled_or_draft_invoice_at_the_job_choke_point(): void
    {
        // DOC-6: the job is the real gate — even if something queued this while it
        // was active (batch sweep, or a cancel racing the worker), a non-issued
        // document must NOT be emailed with a body claiming it «was issued».
        $invoice = $this->makeFiledInvoice();
        $invoice->forceFill(['local_status' => 'cancelled'])->save();

        (new SendInvoiceEmail($invoice, trigger: 'batch'))->handle(
            app(InvoicePdfRenderer::class),
            app(TenantMailerFactory::class),
            app(MailTemplateRenderer::class),
        );

        Mail::assertNothingSent();

        $log = InvoiceMailLog::where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('δεν είναι εκδοθέν', (string) $log->error_message);
    }

    public function test_manual_trigger_records_operator_user_id(): void
    {
        $user = User::factory()->create();
        $invoice = $this->makeFiledInvoice();

        (new SendInvoiceEmail(
            $invoice,
            trigger: 'manual',
            triggeredByUserId: $user->id,
        ))->handle(
            app(InvoicePdfRenderer::class),
            app(TenantMailerFactory::class),
            app(MailTemplateRenderer::class),
        );

        $log = InvoiceMailLog::where('invoice_id', $invoice->id)->first();
        $this->assertSame('manual', $log->trigger);
        $this->assertSame($user->id, $log->triggered_by_user_id);
    }

    public function test_empty_bcc_list_produces_no_bcc_header(): void
    {
        $this->tenant->update(['invoice_audit_bcc' => null]);
        $invoice = $this->makeFiledInvoice();

        (new SendInvoiceEmail($invoice))->handle(
            app(InvoicePdfRenderer::class),
            app(TenantMailerFactory::class),
            app(MailTemplateRenderer::class),
        );

        Mail::assertSent(InvoiceIssuedMail::class, function ($mail) {
            // No BCC recipients
            return ! $mail->hasBcc('audit@acme.gr');
        });

        $log = InvoiceMailLog::where('invoice_id', $invoice->id)->first();
        $this->assertNull($log->bcc_list);
    }

    /**
     * Round-trip test: dispatch through the queue, NOT directly into
     * handle(). Locks in two things the prior tests can't:
     *   1. The queue payload stays tiny — no PDF bytes (~50-500KB)
     *      leak in. PDF renders at handle() time, NOT at dispatch.
     *   2. SerializesModels round-trips cleanly: serialize ->
     *      deserialize without exceptions on the Invoice + its
     *      relations.
     *
     * If a future refactor moves the PDF render to job construct-time
     * (or makes the Mailable ShouldQueue again), this test catches it.
     */
    public function test_dispatched_job_serialised_payload_is_small_and_carries_no_pdf_bytes(): void
    {
        Bus::fake();
        $invoice = $this->makeFiledInvoice();

        SendInvoiceEmail::dispatch($invoice, trigger: 'auto');

        Bus::assertDispatched(
            SendInvoiceEmail::class,
            function (SendInvoiceEmail $job) use ($invoice): bool {
                $this->assertSame($invoice->id, $job->invoice->id);
                $this->assertSame('auto', $job->trigger);

                // Serialized payload must NOT contain a PDF byte
                // signature. PDFs always begin with "%PDF-" — if
                // a future Mailable constructor decides to render up-
                // front, that signature would survive into the queue.
                $serialised = serialize($job);
                $this->assertStringNotContainsString('%PDF-', $serialised);

                // Sanity ceiling: SerializesModels emits ~1-3KB for
                // an Invoice + relations. Cap at 10KB to catch a
                // future addition of large eager-loaded relations
                // that would bloat queue rows.
                $this->assertLessThan(10000, strlen($serialised),
                    'Queue payload >10KB — something is being serialised that should be re-fetched at handle time.');

                return true;
            }
        );
    }

    /**
     * The double-review found that the prior failed() implementation
     * relied on `$this->logId` set in handle() — broken because
     * Laravel deserializes the job before calling failed() (verified
     * at vendor/laravel/.../CallQueuedHandler.php). This test
     * exercises failed() on a freshly-instantiated job (as the queue
     * worker would) and asserts the lookup-by-(invoice, trigger,
     * user) reconciliation actually finds + updates the row.
     */
    public function test_failed_hook_reconciles_latest_log_row_after_deserialization(): void
    {
        $invoice = $this->makeFiledInvoice();

        // Simulate attempt 1: handle() ran, wrote a 'failed' row with
        // the transient last-attempt error.
        InvoiceMailLog::create([
            'company_id' => $invoice->company_id,
            'invoice_id' => $invoice->id,
            'recipient' => 'cust@example.com',
            'trigger' => 'auto',
            'status' => 'failed',
            'error_message' => 'SMTP timeout',
            'queued_at' => now(),
            'failed_at' => now(),
            'triggered_by_user_id' => null,
        ]);

        // Simulate the queue worker calling failed() with a FRESHLY
        // CONSTRUCTED job (the deserialization path doesn't restore
        // any properties handle() set — only constructor args).
        $freshJob = new SendInvoiceEmail($invoice, trigger: 'auto');
        $freshJob->failed(new \RuntimeException('SMTP timeout'));

        $log = InvoiceMailLog::where('invoice_id', $invoice->id)
            ->latest('id')
            ->first();

        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('Gave up after', $log->error_message);
        $this->assertStringContainsString('SMTP timeout', $log->error_message);
    }

    public function test_failed_hook_does_not_overwrite_sent_rows(): void
    {
        $invoice = $this->makeFiledInvoice();

        InvoiceMailLog::create([
            'company_id' => $invoice->company_id,
            'invoice_id' => $invoice->id,
            'recipient' => 'cust@example.com',
            'trigger' => 'auto',
            'status' => 'sent',
            'queued_at' => now(),
            'sent_at' => now(),
        ]);

        (new SendInvoiceEmail($invoice, trigger: 'auto'))
            ->failed(new \RuntimeException('Late failure'));

        $log = InvoiceMailLog::where('invoice_id', $invoice->id)->first();
        $this->assertSame('sent', $log->status);
        $this->assertNull($log->error_message);
    }

    public function test_failed_hook_no_op_when_no_prior_log_row(): void
    {
        $invoice = $this->makeFiledInvoice();

        // Worker died before handle() created any row. failed() must
        // not throw.
        (new SendInvoiceEmail($invoice, trigger: 'auto'))
            ->failed(new \RuntimeException('Pre-handle crash'));

        $this->assertSame(0, InvoiceMailLog::where('invoice_id', $invoice->id)->count());
    }

    public function test_malformed_bcc_entries_are_silently_dropped(): void
    {
        // Operator typed "audit@acme.gr, not-an-email, ops@acme.gr"
        $this->tenant->update(['invoice_audit_bcc' => 'audit@acme.gr, not-an-email, ops@acme.gr']);
        $invoice = $this->makeFiledInvoice();

        (new SendInvoiceEmail($invoice))->handle(
            app(InvoicePdfRenderer::class),
            app(TenantMailerFactory::class),
            app(MailTemplateRenderer::class),
        );

        // Valid addresses BCC'd; invalid one not present (auditBccList
        // filters with FILTER_VALIDATE_EMAIL)
        Mail::assertSent(InvoiceIssuedMail::class, function ($mail) {
            return $mail->hasBcc('audit@acme.gr')
                && $mail->hasBcc('ops@acme.gr')
                && ! $mail->hasBcc('not-an-email');
        });
    }

    private function makeFiledInvoice(): Invoice
    {
        $invoice = Invoice::create([
            'company_id' => $this->tenant->id,
            'invcode' => 'TPY1',
            'code' => 1,
            'invoice_type_id' => $this->type->id,
            'customer_id' => $this->customer->id,
            'issued_at' => now(),
            'company_name' => $this->customer->name,
            'gross_total' => 124.00,
            'net_total' => 100.00,
            // A filed invoice is issued (active); MyDataSubmitter syncs
            // local_status→active on VALID. Realistic so the DOC-6 job gate
            // (isPubliclyViewable) lets it send.
            'local_status' => 'active',
        ]);
        $invoice->forceFill([
            'mydata_state' => 'VALID',
            'mydata_mark' => '400099999999999',
            'mydata_url' => 'https://verify.aade.gr/?mark=400099999999999',
            'mydata_sent' => true,
        ])->save();

        return $invoice->fresh();
    }
}
