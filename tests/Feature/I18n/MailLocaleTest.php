<?php

namespace Tests\Feature\I18n;

use App\Actions\Support\OpenTicket;
use App\Mail\CustomerStatementMail;
use App\Mail\InvoiceIssuedMail;
use App\Mail\QuoteOfferMail;
use App\Mail\TicketFeedbackMail;
use App\Mail\TicketReplyMail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Services\QuoteNumberer;
use App\Services\QuoteTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * i18n Email slice: every customer-facing Mailable renders in the recipient's
 * language when the caller applies ->locale('en'), and stays Greek by default
 * (el is the app locale). Locks the lang/{el,en}/mail.php keys, the locale-aware
 * default invoice body + MARK section (MailTemplateRenderer), and the translated
 * subjects against a silent regression back to hardcoded Greek.
 */
class MailLocaleTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'T', 'slug' => 'mail-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'mail_from_address' => 'noreply@myip.gr', 'mail_from_name' => 'MyIP',
        ]);
    }

    private function closedTicket(Company $tenant): Ticket
    {
        $dept = TicketDepartment::create([
            'company_id' => $tenant->id, 'name' => 'Dept '.uniqid(), 'email' => 'support-'.uniqid().'@myip.gr',
            'is_active' => true, 'feedback_on_close' => true,
        ]);
        $ticket = app(OpenTicket::class)->handle([
            'company_id' => $tenant->id, 'ticket_department_id' => $dept->id, 'requester_email' => 'c@e.gr',
            'subject' => 'Subject', 'body' => 'x', 'author_role' => TicketMessage::ROLE_CUSTOMER, 'via' => TicketMessage::VIA_EMAIL,
        ]);
        $ticket->update(['status' => 'closed', 'closed_at' => now()]);

        return $ticket->fresh();
    }

    public function test_invoice_email_default_body_and_mark_section_localize(): void
    {
        $tenant = $this->tenant();
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'Acme', 'email' => 'c@x.com']);
        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'Invoice',
            'invcount' => 1, 'mydata_type' => '1.1',
        ]);
        $invoice = Invoice::create([
            'company_id' => $tenant->id, 'invcode' => 'TPY6665', 'code' => 6665,
            'invoice_type_id' => $type->id, 'customer_id' => $customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0, 'company_name' => 'Acme',
        ]);
        // mydata_* are cache columns (written via forceFill, not fillable).
        $invoice->forceFill(['mydata_mark' => '400001234567890', 'mydata_url' => 'https://example.gr/verify'])->save();
        $invoice->lines()->create([
            'company_id' => $tenant->id, 'product_descr' => 'Service', 'qty' => 1, 'price_per_item' => 100, 'vat_percent' => 24,
        ]);

        $en = (new InvoiceIssuedMail($invoice->fresh('lines'), 'PDF'))->locale('en')->render();
        $this->assertStringContainsString('Please find attached the document', $en);
        $this->assertStringContainsString('certified with the unique MARK number: 400001234567890', $en);
        $this->assertStringContainsString('Verify:', $en);
        $this->assertStringNotContainsString('Σας αποστέλλουμε συνημμένα το παραστατικό', $en);

        // Default (el) stays byte-verbatim Greek.
        $el = (new InvoiceIssuedMail($invoice->fresh('lines'), 'PDF'))->render();
        $this->assertStringContainsString('Σας αποστέλλουμε συνημμένα το παραστατικό', $el);
        $this->assertStringContainsString('πιστοποιήθηκε με τον μοναδικό αριθμό MARK', $el);
    }

    public function test_custom_tenant_template_stays_greek_even_for_english_recipient(): void
    {
        // A tenant with its OWN (Greek) body template containing {mark_section} must
        // NOT get an English MARK sentence spliced in when the recipient locale is en —
        // the custom template is emitted as-is in the tenant's language.
        $tenant = $this->tenant();
        $tenant->forceFill(['mail_body_template' => "Καλημέρα,\n\n{mark_section}\n\nΣύνολο: {total}"])->save();
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'Acme', 'email' => 'c@x.com']);
        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'Τιμολόγιο',
            'invcount' => 1, 'mydata_type' => '1.1',
        ]);
        $invoice = Invoice::create([
            'company_id' => $tenant->id, 'invcode' => 'TPY7001', 'code' => 7001,
            'invoice_type_id' => $type->id, 'customer_id' => $customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0, 'company_name' => 'Acme',
        ]);
        $invoice->forceFill(['mydata_mark' => '400009', 'mydata_url' => 'https://example.gr/v'])->save();
        $invoice->lines()->create([
            'company_id' => $tenant->id, 'product_descr' => 'Service', 'qty' => 1, 'price_per_item' => 100, 'vat_percent' => 24,
        ]);

        $html = (new InvoiceIssuedMail($invoice->fresh('lines'), 'PDF'))->locale('en')->render();
        // Custom template body + its composed MARK section stay Greek.
        $this->assertStringContainsString('πιστοποιήθηκε με τον μοναδικό αριθμό MARK', $html);
        $this->assertStringNotContainsString('certified with the unique MARK number', $html);
    }

    public function test_english_default_body_uses_english_currency_format(): void
    {
        $tenant = $this->tenant();
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'Acme', 'email' => 'c@x.com']);
        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'Invoice',
            'invcount' => 1, 'mydata_type' => '1.1',
        ]);
        $invoice = Invoice::create([
            'company_id' => $tenant->id, 'invcode' => 'TPY7002', 'code' => 7002,
            'invoice_type_id' => $type->id, 'customer_id' => $customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0, 'company_name' => 'Acme',
        ]);
        // {total} = payableTotal() = gross_total (a cache column). English format uses a
        // dot decimal / comma thousands: "1,240.00 €"; Greek is the mirror "1.240,00 €".
        $invoice->forceFill(['gross_total' => 1240])->save();

        $en = (new InvoiceIssuedMail($invoice->fresh(), 'PDF'))->locale('en')->render();
        $this->assertStringContainsString('1,240.00 €', $en);

        $el = (new InvoiceIssuedMail($invoice->fresh(), 'PDF'))->render();
        $this->assertStringContainsString('1.240,00 €', $el);
    }

    public function test_quote_email_localizes_body_and_subject(): void
    {
        $tenant = $this->tenant();
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'Acme', 'email' => 'c@x.com']);
        $quote = DB::transaction(fn () => Quote::create([
            'company_id' => $tenant->id, 'customer_id' => $customer->id,
            'code' => app(QuoteNumberer::class)->allocate($tenant),
            'company_name' => 'Acme', 'issued_at' => now(), 'valid_until' => now()->addDays(30),
        ]));
        QuoteLine::create(['quote_id' => $quote->id, 'product_descr' => 'Service', 'qty' => 1, 'price_per_item' => 100, 'vat_percent' => 24]);
        app(QuoteTotals::class)($quote);

        $html = (new QuoteOfferMail($quote->fresh(), 'PDF'))->locale('en')->render();
        $this->assertStringContainsString('Please find our quotation attached', $html);
        $this->assertStringContainsString('Total:', $html);
        $this->assertStringContainsString('Kind regards,', $html);

        App::setLocale('en');
        $this->assertStringContainsString('Quotation', (new QuoteOfferMail($quote->fresh(), 'PDF'))->envelope()->subject);
        App::setLocale('el');
        $this->assertStringContainsString('Προσφορά', (new QuoteOfferMail($quote->fresh(), 'PDF'))->envelope()->subject);
    }

    public function test_statement_email_localizes_body_and_subject(): void
    {
        $tenant = $this->tenant();
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'Acme', 'email' => 'c@x.com']);

        $html = (new CustomerStatementMail(customer: $customer, pdfBytes: 'PDF'))->locale('en')->render();
        $this->assertStringContainsString('Your account statement is attached', $html);
        $this->assertStringContainsString('Dear Acme', $html);

        App::setLocale('en');
        $this->assertStringContainsString('Account statement', (new CustomerStatementMail(customer: $customer, pdfBytes: 'PDF'))->envelope()->subject);
        App::setLocale('el');
        $this->assertStringContainsString('Καρτέλα πελάτη', (new CustomerStatementMail(customer: $customer, pdfBytes: 'PDF'))->envelope()->subject);
    }

    public function test_ticket_reply_email_localizes_footer(): void
    {
        $tenant = $this->tenant();
        $ticket = $this->closedTicket($tenant);

        $mail = new TicketReplyMail(
            ticket: $ticket, body: 'Hello', fromAddress: 'support@myip.gr', fromName: 'MyIP',
            messageId: 'x@y', inReplyTo: null, references: [], ccAddresses: [], bccAddresses: [], attachmentFiles: [],
        );
        $html = $mail->locale('en')->render();
        $this->assertStringContainsString('Reply to support request', $html);
        $this->assertStringNotContainsString('αίτημα υποστήριξης', $html);
    }

    public function test_ticket_feedback_email_localizes_body_and_subject(): void
    {
        $tenant = $this->tenant();
        $ticket = $this->closedTicket($tenant);

        $html = (new TicketFeedbackMail(ticket: $ticket, url: 'https://example.gr/support/feedback/'.$ticket->id, fromAddress: 'support@myip.gr', fromName: 'MyIP'))
            ->locale('en')->render();
        $this->assertStringContainsString('We would appreciate a short rating of our service', $html);
        $this->assertStringContainsString('Rate the service', $html);

        App::setLocale('en');
        $this->assertStringContainsString('How was our service?', (new TicketFeedbackMail(ticket: $ticket, url: 'https://x', fromAddress: 'a@b.gr', fromName: 'N'))->envelope()->subject);
        App::setLocale('el');
        $this->assertStringContainsString('Πώς σας φάνηκε', (new TicketFeedbackMail(ticket: $ticket, url: 'https://x', fromAddress: 'a@b.gr', fromName: 'N'))->envelope()->subject);
    }
}
