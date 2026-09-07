<?php

namespace Tests\Feature\Support;

use App\Actions\Support\OpenTicket;
use App\Actions\Support\PostTicketMessage;
use App\Jobs\SendTicketReplyEmail;
use App\Mail\TicketReplyMail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Services\TenantMailerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Πυλώνας E, Phase 3b-ii — outbound threading. An operator's public reply is
 * emailed to the customer FROM the department mailbox, threaded (our Message-ID
 * stored on the message; In-Reply-To → the customer message answered). Only
 * operator public messages are mailed.
 */
class SendTicketReplyEmailTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create([
            'name' => 'MyIP', 'slug' => 'out-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'support_enabled' => true,
            'mail_from_address' => 'noreply@myip.gr', 'mail_from_name' => 'MyIP',
        ]);
    }

    private function department(Company $company): TicketDepartment
    {
        return TicketDepartment::create([
            'company_id' => $company->id, 'name' => 'Γενική', 'email' => 'support@myip.gr', 'is_active' => true,
        ]);
    }

    private function deliver(TicketMessage $message): void
    {
        (new SendTicketReplyEmail($message->id))->handle(app(TenantMailerFactory::class));
    }

    public function test_operator_reply_is_emailed_to_the_customer_threaded(): void
    {
        Mail::fake();
        $company = $this->company();
        $dept = $this->department($company);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'c@e.gr']);

        $ticket = app(OpenTicket::class)->handle([
            'company_id' => $company->id, 'customer_id' => $customer->id, 'ticket_department_id' => $dept->id,
            'subject' => 'Πρόβλημα', 'body' => 'αρχικό', 'author_role' => TicketMessage::ROLE_CUSTOMER,
            'via' => TicketMessage::VIA_EMAIL,
        ]);
        // the opening customer message arrived by email (has a Message-ID)
        $ticket->messages()->first()->update(['email_message_id' => 'inbound-1@e.gr']);

        $reply = app(PostTicketMessage::class)->handle($ticket, [
            'author_role' => TicketMessage::ROLE_OPERATOR, 'via' => TicketMessage::VIA_OPERATOR, 'body' => 'η απάντησή μας',
        ]);
        $this->deliver($reply);

        $this->assertNotNull($reply->fresh()->email_message_id, 'our outbound Message-ID stored for future threading');

        Mail::assertSent(TicketReplyMail::class, function (TicketReplyMail $mail) use ($ticket) {
            return $mail->hasTo('c@e.gr')
                && $mail->fromAddress === 'support@myip.gr'
                && $mail->inReplyTo === 'inbound-1@e.gr'
                && str_contains($mail->envelope()->subject, $ticket->reference);
        });
    }

    public function test_reply_goes_to_the_requester_address_over_the_customer_primary(): void
    {
        Mail::fake();
        $company = $this->company();
        $dept = $this->department($company);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'primary@e.gr']);

        // The customer wrote in from a DIFFERENT address than their primary.
        $ticket = app(OpenTicket::class)->handle([
            'company_id' => $company->id, 'customer_id' => $customer->id, 'ticket_department_id' => $dept->id,
            'requester_email' => 'wrote-from@e.gr', 'subject' => 'X', 'body' => 'y',
            'author_role' => TicketMessage::ROLE_CUSTOMER, 'via' => TicketMessage::VIA_EMAIL,
        ]);
        $reply = app(PostTicketMessage::class)->handle($ticket, [
            'author_role' => TicketMessage::ROLE_OPERATOR, 'body' => 'απάντηση',
        ]);
        $this->deliver($reply);

        Mail::assertSent(TicketReplyMail::class, fn (TicketReplyMail $mail) => $mail->hasTo('wrote-from@e.gr'));
    }

    public function test_external_watcher_is_cc_d_on_the_reply(): void
    {
        Mail::fake();
        $company = $this->company();
        $dept = $this->department($company);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'c@e.gr']);

        $ticket = app(OpenTicket::class)->handle([
            'company_id' => $company->id, 'customer_id' => $customer->id, 'ticket_department_id' => $dept->id,
            'subject' => 'X', 'body' => 'y', 'author_role' => TicketMessage::ROLE_CUSTOMER, 'via' => TicketMessage::VIA_EMAIL,
        ]);
        $ticket->addEmailWatcher('boss@e.gr');

        $reply = app(PostTicketMessage::class)->handle($ticket, [
            'author_role' => TicketMessage::ROLE_OPERATOR, 'body' => 'απάντηση',
        ]);
        $this->deliver($reply);

        Mail::assertSent(TicketReplyMail::class, function (TicketReplyMail $mail): bool {
            $cc = collect($mail->envelope()->cc)->map(fn ($a) => $a->address)->all();

            return $mail->hasTo('c@e.gr') && in_array('boss@e.gr', $cc, true);
        });
    }

    public function test_no_recipient_sends_nothing(): void
    {
        Mail::fake();
        $company = $this->company();
        $dept = $this->department($company);
        // GUEST with no requester email → nowhere to send.
        $ticket = app(OpenTicket::class)->handle([
            'company_id' => $company->id, 'ticket_department_id' => $dept->id,
            'subject' => 'X', 'body' => 'y', 'author_role' => TicketMessage::ROLE_CUSTOMER,
        ]);
        $reply = app(PostTicketMessage::class)->handle($ticket, [
            'author_role' => TicketMessage::ROLE_OPERATOR, 'body' => 'απάντηση',
        ]);
        $this->deliver($reply);

        Mail::assertNothingSent();
    }

    public function test_a_customer_message_is_not_emailed(): void
    {
        Mail::fake();
        $company = $this->company();
        $dept = $this->department($company);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'c@e.gr']);
        $ticket = app(OpenTicket::class)->handle([
            'company_id' => $company->id, 'customer_id' => $customer->id, 'ticket_department_id' => $dept->id,
            'subject' => 'X', 'body' => 'y', 'author_role' => TicketMessage::ROLE_CUSTOMER,
        ]);

        // Dispatch for the CUSTOMER opening message — must not email.
        $this->deliver($ticket->messages()->first());
        Mail::assertNothingSent();
    }
}
