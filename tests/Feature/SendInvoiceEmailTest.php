<?php

namespace Tests\Feature;

use App\Jobs\SendInvoiceEmail;
use App\Mail\InvoiceIssuedMail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceMailLog;
use App\Models\InvoiceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            app(\App\Services\InvoicePdfRenderer::class),
            app(\App\Services\TenantMailerFactory::class),
            app(\App\Services\MailTemplateRenderer::class),
        );

        // Mail dispatched to primary recipient
        Mail::assertQueued(InvoiceIssuedMail::class, function ($mail) {
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
            app(\App\Services\InvoicePdfRenderer::class),
            app(\App\Services\TenantMailerFactory::class),
            app(\App\Services\MailTemplateRenderer::class),
        );

        Mail::assertNothingQueued();
        Mail::assertNothingSent();

        // Log row records the skip with a clear reason
        $log = InvoiceMailLog::where('invoice_id', $invoice->id)->first();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('no email', strtolower((string) $log->error_message));
        $this->assertNotNull($log->failed_at);
    }

    public function test_manual_trigger_records_operator_user_id(): void
    {
        $user = \App\Models\User::factory()->create();
        $invoice = $this->makeFiledInvoice();

        (new SendInvoiceEmail(
            $invoice,
            trigger: 'manual',
            triggeredByUserId: $user->id,
        ))->handle(
            app(\App\Services\InvoicePdfRenderer::class),
            app(\App\Services\TenantMailerFactory::class),
            app(\App\Services\MailTemplateRenderer::class),
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
            app(\App\Services\InvoicePdfRenderer::class),
            app(\App\Services\TenantMailerFactory::class),
            app(\App\Services\MailTemplateRenderer::class),
        );

        Mail::assertQueued(InvoiceIssuedMail::class, function ($mail) {
            // No BCC recipients
            return ! $mail->hasBcc('audit@acme.gr');
        });

        $log = InvoiceMailLog::where('invoice_id', $invoice->id)->first();
        $this->assertNull($log->bcc_list);
    }

    public function test_malformed_bcc_entries_are_silently_dropped(): void
    {
        // Operator typed "audit@acme.gr, not-an-email, ops@acme.gr"
        $this->tenant->update(['invoice_audit_bcc' => 'audit@acme.gr, not-an-email, ops@acme.gr']);
        $invoice = $this->makeFiledInvoice();

        (new SendInvoiceEmail($invoice))->handle(
            app(\App\Services\InvoicePdfRenderer::class),
            app(\App\Services\TenantMailerFactory::class),
            app(\App\Services\MailTemplateRenderer::class),
        );

        // Valid addresses BCC'd; invalid one not present (auditBccList
        // filters with FILTER_VALIDATE_EMAIL)
        Mail::assertQueued(InvoiceIssuedMail::class, function ($mail) {
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
