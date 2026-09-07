<?php

namespace Tests\Feature\Support;

use App\Actions\Support\OpenTicket;
use App\Actions\Support\PostTicketMessage;
use App\Jobs\SendTicketReplyEmail;
use App\Mail\TicketReplyMail;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Services\Support\Inbound\InboundEmailAttachment;
use App\Services\Support\Inbound\InboundTicketRouter;
use App\Services\Support\Inbound\ParsedInboundEmail;
use App\Services\TenantMailerFactory;
use App\Support\TicketAttachments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Πυλώνας E, Phase 4 follow-up · PR B — email attachments (inbound MIME ingestion +
 * outbound attach). SECURITY-critical: the inbound sender is fully untrusted, so
 * every stored attachment must clear the extension allowlist + size/count/total
 * caps, and a disallowed/oversized part is never written.
 */
class TicketEmailAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function company(): Company
    {
        return Company::create([
            'name' => 'T', 'slug' => 'eatt-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'support_enabled' => true,
            'mail_from_address' => 'noreply@myip.gr', 'mail_from_name' => 'MyIP',
        ]);
    }

    private function department(Company $company): TicketDepartment
    {
        return TicketDepartment::create([
            'company_id' => $company->id, 'name' => 'Γ '.uniqid(), 'email' => 'support-'.uniqid().'@myip.gr', 'is_active' => true,
        ]);
    }

    private function router(): InboundTicketRouter
    {
        return app(InboundTicketRouter::class);
    }

    private function att(string $filename, string $content, ?string $mime = 'application/pdf'): InboundEmailAttachment
    {
        return new InboundEmailAttachment($filename, $mime, $content);
    }

    // ---- storeInbound (the untrusted-sender gate) ---------------------------

    public function test_store_inbound_keeps_allowlisted_files_and_records_metadata(): void
    {
        $company = $this->company();
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'p@e.gr']);
        $ticket = app(OpenTicket::class)->handle([
            'company_id' => $company->id, 'customer_id' => $customer->id,
            'ticket_department_id' => $this->department($company)->id,
            'subject' => 'Θ', 'body' => 'x', 'author_role' => TicketMessage::ROLE_CUSTOMER, 'via' => TicketMessage::VIA_EMAIL,
        ]);
        $message = $ticket->messages()->first();

        $made = TicketAttachments::storeInbound($message, [$this->att('../secret/report.pdf', 'PDFBYTES')]);

        $this->assertCount(1, $made);
        $this->assertSame('report.pdf', $made[0]->original_name, 'path components stripped');
        $this->assertSame(8, (int) $made[0]->size);
        $this->assertNull($made[0]->uploaded_by_user_id, 'inbound = from the sender, no operator');
        Storage::disk('local')->assertExists($made[0]->path);
        $this->assertStringStartsWith(TicketAttachments::DIRECTORY.'/', $made[0]->path);
    }

    public function test_store_inbound_rejects_disallowed_extensions(): void
    {
        $company = $this->company();
        $ticket = $this->emailTicket($company);
        $message = $ticket->messages()->first();

        $made = TicketAttachments::storeInbound($message, [
            $this->att('evil.php', '<?php echo 1;', 'text/plain'),
            $this->att('page.html', '<script>alert(1)</script>', 'text/html'),
            $this->att('logo.svg', '<svg onload=alert(1)>', 'image/svg+xml'),
            $this->att('run.exe', 'MZ...', 'application/octet-stream'),
        ]);

        $this->assertSame([], $made);
        $this->assertSame(0, Attachment::query()->count());
    }

    public function test_store_inbound_enforces_per_file_and_total_caps(): void
    {
        $company = $this->company();
        $ticket = $this->emailTicket($company);
        $message = $ticket->messages()->first();

        // One file over the per-file cap → dropped.
        $tooBig = str_repeat('a', TicketAttachments::MAX_SIZE_KB * 1024 + 1);
        $this->assertSame([], TicketAttachments::storeInbound($message, [$this->att('big.zip', $tooBig, 'application/zip')]));

        // Total-per-email budget: two files each within the per-file cap but together
        // over the email budget → the second is dropped.
        $half = str_repeat('b', (int) (TicketAttachments::MAX_EMAIL_TOTAL_KB * 1024 * 0.75));
        $made = TicketAttachments::storeInbound($message, [
            $this->att('a.pdf', $half),
            $this->att('b.pdf', $half),
        ]);
        $this->assertCount(1, $made, 'only the first fits the per-email budget');
    }

    public function test_store_inbound_caps_the_count(): void
    {
        $company = $this->company();
        $ticket = $this->emailTicket($company);
        $message = $ticket->messages()->first();

        $files = [];
        for ($i = 0; $i < TicketAttachments::MAX_COUNT + 3; $i++) {
            $files[] = $this->att("f{$i}.pdf", 'x');
        }
        $made = TicketAttachments::storeInbound($message, $files);

        $this->assertCount(TicketAttachments::MAX_COUNT, $made);
    }

    // ---- router wiring ------------------------------------------------------

    public function test_inbound_email_with_an_attachment_lands_on_the_opening_message(): void
    {
        $company = $this->company();
        $dept = $this->department($company);
        Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'sender@e.gr']);

        $ticket = $this->router()->route($dept, new ParsedInboundEmail(
            fromEmail: 'sender@e.gr', fromName: 'Πελ', subject: 'Με αρχείο', body: 'δες το',
            messageId: '<in-1@e.gr>',
            attachments: [$this->att('invoice.pdf', 'PDF')],
        ));

        $this->assertNotNull($ticket);
        $att = $ticket->messages()->first()->attachments()->first();
        $this->assertNotNull($att);
        $this->assertSame('invoice.pdf', $att->original_name);
    }

    public function test_inbound_reply_attaches_to_the_threaded_message_not_the_first(): void
    {
        $company = $this->company();
        $dept = $this->department($company);
        Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'sender@e.gr']);

        $ticket = $this->router()->route($dept, new ParsedInboundEmail(
            fromEmail: 'sender@e.gr', fromName: 'Πελ', subject: 'Αρχικό', body: 'πρώτο', messageId: '<in-1@e.gr>',
        ));
        // A reply from the same owner, threaded via References, carrying an attachment.
        $this->router()->route($dept, new ParsedInboundEmail(
            fromEmail: 'sender@e.gr', fromName: 'Πελ', subject: 'Re: Αρχικό', body: 'με αρχείο',
            messageId: '<in-2@e.gr>', references: ['<in-1@e.gr>'],
            attachments: [$this->att('second.pdf', 'PDF')],
        ));

        $ticket->refresh();
        $this->assertSame(2, $ticket->messages()->count(), 'threaded, not a new ticket');
        // The attachment is on the SECOND (reply) message, not the opening one.
        $ordered = $ticket->messages()->get();
        $this->assertSame(0, $ordered->first()->attachments()->count());
        $this->assertSame(1, $ordered->last()->attachments()->count());
        $this->assertSame('second.pdf', $ordered->last()->attachments()->first()->original_name);
    }

    // ---- outbound (operator reply attaches its files) -----------------------

    public function test_operator_reply_email_carries_the_message_attachments(): void
    {
        Mail::fake();
        $company = $this->company();
        $dept = $this->department($company);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'c@e.gr']);

        $ticket = app(OpenTicket::class)->handle([
            'company_id' => $company->id, 'customer_id' => $customer->id, 'ticket_department_id' => $dept->id,
            'subject' => 'X', 'body' => 'y', 'author_role' => TicketMessage::ROLE_CUSTOMER, 'via' => TicketMessage::VIA_EMAIL,
        ]);
        $reply = app(PostTicketMessage::class)->handle($ticket, [
            'author_role' => TicketMessage::ROLE_OPERATOR, 'via' => TicketMessage::VIA_OPERATOR, 'body' => 'ορίστε',
        ]);
        // Operator attached a file (stored on the private disk as PR A does).
        Storage::disk('local')->put(TicketAttachments::DIRECTORY.'/xyz.pdf', 'PDF');
        $reply->attachments()->create([
            'company_id' => $company->id, 'disk' => 'local', 'path' => TicketAttachments::DIRECTORY.'/xyz.pdf',
            'original_name' => 'answer.pdf', 'mime_type' => 'application/pdf', 'size' => 3,
        ]);

        (new SendTicketReplyEmail($reply->id))->handle(app(TenantMailerFactory::class));

        Mail::assertSent(TicketReplyMail::class, function (TicketReplyMail $mail): bool {
            return count($mail->attachmentFiles) === 1
                && $mail->attachmentFiles[0]['name'] === 'answer.pdf'
                && $mail->attachmentFiles[0]['path'] === TicketAttachments::DIRECTORY.'/xyz.pdf';
        });
    }

    public function test_outbound_payload_is_all_or_nothing_over_the_email_budget(): void
    {
        $company = $this->company();
        $ticket = $this->emailTicket($company);
        $message = $ticket->messages()->first();

        // Two rows whose sizes together exceed the per-email budget → payload is [].
        foreach (['a', 'b'] as $n) {
            $message->attachments()->create([
                'company_id' => $company->id, 'disk' => 'local', 'path' => TicketAttachments::DIRECTORY.'/'.$n.'.pdf',
                'original_name' => $n.'.pdf', 'mime_type' => 'application/pdf',
                'size' => (int) (TicketAttachments::MAX_EMAIL_TOTAL_KB * 1024 * 0.75),
            ]);
        }

        $this->assertSame([], TicketAttachments::outboundPayload($message->attachments()->get()));
    }

    private function emailTicket(Company $company): Ticket
    {
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'p-'.uniqid().'@e.gr']);

        return app(OpenTicket::class)->handle([
            'company_id' => $company->id, 'customer_id' => $customer->id,
            'ticket_department_id' => $this->department($company)->id,
            'subject' => 'Θ', 'body' => 'x', 'author_role' => TicketMessage::ROLE_CUSTOMER, 'via' => TicketMessage::VIA_EMAIL,
        ]);
    }
}
