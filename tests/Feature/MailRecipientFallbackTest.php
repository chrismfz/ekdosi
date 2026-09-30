<?php

namespace Tests\Feature;

use App\Jobs\SendInvoiceEmail;
use App\Jobs\SendQuoteEmail;
use App\Mail\InvoiceIssuedMail;
use App\Mail\InvoiceReminderMail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceMailLog;
use App\Models\InvoiceType;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\QuoteMailLog;
use App\Services\InvoicePdfRenderer;
use App\Services\Mail\PrimaryRecipientRejected;
use App\Services\Mail\RecipientFallbackSender;
use App\Services\MailTemplateRenderer;
use App\Services\QuoteNumberer;
use App\Services\QuotePdfRenderer;
use App\Services\QuoteTotals;
use App\Services\TenantMailerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Tests\Support\RejectingSmtpTransport;
use Tests\TestCase;

/**
 * Incident 2026-09-29: a dead customer.secondary_email (550 «User unknown») sank the
 * whole invoice email — SMTP rejects per RCPT and aborts the message, so the PRIMARY
 * address never got it either, and the job retried the same permanent error 3×.
 * These run through a real Laravel mailer over a transport that rejects like SMTP.
 */
class MailRecipientFallbackTest extends TestCase
{
    use RefreshDatabase;

    private RejectingSmtpTransport $transport;

    private Company $tenant;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = new RejectingSmtpTransport;
        Mail::extend('rejecting', fn () => $this->transport);
        config([
            'mail.mailers.rejecting' => ['transport' => 'rejecting'],
            'mail.default' => 'rejecting',
        ]);

        $this->tenant = Company::create([
            'name' => 'Acme Greek Ltd',
            'slug' => 'fb-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
            'mail_from_address' => 'noreply@acme.gr',
            'invoice_audit_bcc' => 'audit@acme.gr',
        ]);

        $this->customer = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'Customer',
            'email' => 'cust@example.com',
            'secondary_email' => 'gone@example.com',
        ]);
    }

    public function test_a_rejected_cc_still_delivers_the_invoice_to_the_primary_and_says_so(): void
    {
        $this->transport->rejected = ['gone@example.com'];

        $this->sendInvoice($invoice = $this->makeFiledInvoice());

        $this->assertCount(1, $this->transport->delivered);
        $this->assertSame(['cust@example.com'], $this->transport->recipientsOf(0));

        $log = InvoiceMailLog::where('invoice_id', $invoice->id)->sole();
        $this->assertSame('sent', $log->status);
        $this->assertNull($log->cc_list, 'the dropped CC did not get it — the log must not claim it did');
        $this->assertNull($log->bcc_list);
        $this->assertStringContainsString('gone@example.com', (string) $log->error_message);
    }

    public function test_a_rejected_audit_bcc_does_not_block_the_customer_either(): void
    {
        $this->transport->rejected = ['audit@acme.gr'];

        $this->sendInvoice($invoice = $this->makeFiledInvoice());

        $this->assertSame(['cust@example.com'], $this->transport->recipientsOf(0));
        $this->assertStringContainsString('audit@acme.gr', (string) InvoiceMailLog::where('invoice_id', $invoice->id)->sole()->error_message);
    }

    public function test_a_clean_send_keeps_cc_and_bcc_and_no_warning(): void
    {
        $this->sendInvoice($invoice = $this->makeFiledInvoice());

        $this->assertSame(1, $this->transport->attempts);
        $this->assertEqualsCanonicalizing(
            ['cust@example.com', 'gone@example.com', 'audit@acme.gr'],
            $this->transport->recipientsOf(0),
        );
        $log = InvoiceMailLog::where('invoice_id', $invoice->id)->sole();
        $this->assertSame('sent', $log->status);
        $this->assertNull($log->error_message);
        $this->assertSame(['gone@example.com'], $log->cc_list);
    }

    public function test_a_rejected_primary_fails_at_once_without_throwing_for_a_retry(): void
    {
        $this->transport->rejected = ['cust@example.com'];

        // Must NOT throw: retrying a non-existent mailbox can't help.
        $this->sendInvoice($invoice = $this->makeFiledInvoice());

        $this->assertSame([], $this->transport->delivered);
        $this->assertSame(1, $this->transport->attempts, 'no pointless To-only resend when the To itself is the bad one');
        $log = InvoiceMailLog::where('invoice_id', $invoice->id)->sole();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('cust@example.com', (string) $log->error_message);
    }

    public function test_a_temporary_4xx_is_rethrown_for_the_normal_retry(): void
    {
        $this->transport->rejected = ['gone@example.com'];
        $this->transport->code = 450;

        try {
            $this->sendInvoice($invoice = $this->makeFiledInvoice());
            $this->fail('a 4xx must be rethrown so the queue retries');
        } catch (UnexpectedResponseException) {
        }

        $this->assertSame(1, $this->transport->attempts);
        $this->assertSame('failed', InvoiceMailLog::query()->sole()->status);
    }

    public function test_a_rejected_cc_on_a_quote_still_reaches_the_customer(): void
    {
        $this->transport->rejected = ['gone@example.com'];
        $quote = $this->makeQuote();

        (new SendQuoteEmail($quote, 'manual', null))->handle(
            app(QuotePdfRenderer::class),
            app(TenantMailerFactory::class),
            app(RecipientFallbackSender::class),
        );

        $this->assertSame(['cust@example.com'], $this->transport->recipientsOf(0));
        $log = QuoteMailLog::where('quote_id', $quote->id)->sole();
        $this->assertSame('sent', $log->status);
        $this->assertStringContainsString('gone@example.com', (string) $log->error_message);
    }

    public function test_helper_leaves_non_recipient_rejections_alone(): void
    {
        // A 5xx on DATA (content rejected) is not a recipient problem → rethrown as-is.
        $this->transport->rejected = ['gone@example.com'];
        $this->transport->expected = '250';
        $invoice = $this->makeFiledInvoice();

        $this->expectException(UnexpectedResponseException::class);
        app(RecipientFallbackSender::class)->send(
            Mail::mailer(),
            'cust@example.com',
            fn (bool $primaryOnly) => new InvoiceIssuedMail($invoice, '%PDF', primaryOnly: $primaryOnly),
        );
    }

    public function test_helper_reports_the_primary_as_rejected_when_the_envelope_had_no_extras(): void
    {
        $this->customer->update(['secondary_email' => null]);
        $this->tenant->update(['invoice_audit_bcc' => null]);
        $this->transport->rejected = ['cust@example.com'];
        $invoice = $this->makeFiledInvoice();

        $this->expectException(PrimaryRecipientRejected::class);
        app(RecipientFallbackSender::class)->send(
            Mail::mailer(),
            'cust@example.com',
            fn (bool $primaryOnly) => new InvoiceIssuedMail($invoice->fresh(['company', 'customer']), '%PDF', primaryOnly: $primaryOnly),
        );
    }

    public function test_the_reminder_mail_drops_cc_and_bcc_when_primary_only(): void
    {
        $invoice = $this->makeFiledInvoice()->load(['company', 'customer']);

        $full = (new InvoiceReminderMail($invoice, 'S', 'B', 'B'))->envelope();
        $this->assertCount(1, $full->cc);
        $this->assertCount(1, $full->bcc);

        $bare = (new InvoiceReminderMail($invoice, 'S', 'B', 'B', null, primaryOnly: true))->envelope();
        $this->assertSame([], $bare->cc);
        $this->assertSame([], $bare->bcc);
    }

    private function sendInvoice(Invoice $invoice): void
    {
        (new SendInvoiceEmail($invoice, trigger: 'auto'))->handle(
            app(InvoicePdfRenderer::class),
            app(TenantMailerFactory::class),
            app(MailTemplateRenderer::class),
            app(RecipientFallbackSender::class),
        );
    }

    private function makeFiledInvoice(): Invoice
    {
        $type = InvoiceType::firstOrCreate(
            ['company_id' => $this->tenant->id, 'code' => 'TPY'],
            ['name' => 'Τιμολόγιο', 'invcount' => 1, 'mydata_type' => '1.1'],
        );

        $invoice = Invoice::create([
            'company_id' => $this->tenant->id,
            'invcode' => 'TPY1',
            'code' => 1,
            'invoice_type_id' => $type->id,
            'customer_id' => $this->customer->id,
            'issued_at' => now(),
            'company_name' => $this->customer->name,
            'gross_total' => 124.00,
            'net_total' => 100.00,
            'local_status' => 'active',
        ]);
        $invoice->forceFill(['mydata_state' => 'VALID', 'mydata_mark' => '400099999999999'])->save();

        return $invoice->fresh();
    }

    private function makeQuote(): Quote
    {
        $quote = DB::transaction(fn () => Quote::create([
            'company_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'code' => app(QuoteNumberer::class)->allocate($this->tenant),
            'subject' => 'Δοκιμή',
            'company_name' => 'Customer',
            'issued_at' => now(),
            'valid_until' => now()->addDays(30),
        ]));
        QuoteLine::create([
            'quote_id' => $quote->id, 'product_descr' => 'Υπηρεσία',
            'qty' => 1, 'price_per_item' => 50, 'vat_percent' => 24,
        ]);

        return app(QuoteTotals::class)($quote);
    }
}
