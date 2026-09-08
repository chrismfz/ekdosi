<?php

namespace Tests\Feature\Support;

use App\Actions\Support\OpenTicket;
use App\Enums\TicketStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Scopes\CompanyScope;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Services\Support\Inbound\InboundTicketRouter;
use App\Services\Support\Inbound\ParsedInboundEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Πυλώνας E, Phase 3a — inbound email → ticket routing. The security-sensitive
 * core: sender→customer binding, clients_only rejection, reply threading
 * (References + subject token), body cleaning. No IMAP/transport.
 */
class InboundTicketRouterTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create([
            'name' => 'T', 'slug' => 'in-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'support_enabled' => true,
        ]);
    }

    private function department(Company $company, bool $clientsOnly = false): TicketDepartment
    {
        return TicketDepartment::create([
            'company_id' => $company->id, 'name' => 'Γενική',
            'is_active' => true, 'is_hidden' => false, 'clients_only' => $clientsOnly,
        ]);
    }

    private function router(): InboundTicketRouter
    {
        return app(InboundTicketRouter::class);
    }

    public function test_new_ticket_from_a_known_customer_binds_and_cleans(): void
    {
        $company = $this->company();
        $dept = $this->department($company);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ ΑΕ', 'email' => 'Pel@Acme.GR']);

        $email = new ParsedInboundEmail(
            fromEmail: 'pel@acme.gr', fromName: 'Πελ',
            subject: 'Re: Βοήθεια με τιμολόγιο',
            body: "Δεν το έλαβα ακόμη.\n\nOn Wed someone wrote:\n> προηγούμενο μήνυμα\n> με quotes",
            messageId: '<abc@mail>',
        );

        $ticket = $this->router()->route($dept, $email);

        $this->assertNotNull($ticket);
        $this->assertSame($customer->id, $ticket->customer_id, 'case-insensitive email match');
        $this->assertSame('email', $ticket->opened_via);
        $this->assertSame(TicketStatus::Open, $ticket->status);
        $this->assertSame('Βοήθεια με τιμολόγιο', $ticket->subject, 'Re: stripped');
        $msg = $ticket->messages()->first();
        $this->assertSame('Δεν το έλαβα ακόμη.', $msg->body, 'quoted history stripped');
        $this->assertStringContainsString('προηγούμενο μήνυμα', (string) $msg->body_original, 'raw kept');
        $this->assertSame(TicketMessage::VIA_EMAIL, $msg->via);
        $this->assertSame('abc@mail', $msg->email_message_id, 'stored normalised (brackets stripped)');
    }

    public function test_unknown_sender_opens_a_guest_ticket_when_not_clients_only(): void
    {
        $company = $this->company();
        $dept = $this->department($company, clientsOnly: false);

        $ticket = $this->router()->route($dept, new ParsedInboundEmail(
            fromEmail: 'stranger@example.com', fromName: 'Ξένος', subject: 'Ερώτηση', body: 'Γεια σας',
        ));

        $this->assertNotNull($ticket);
        $this->assertNull($ticket->customer_id, 'GUEST');
        $this->assertSame('stranger@example.com', $ticket->requester_email);
    }

    public function test_clients_only_rejects_an_unknown_sender(): void
    {
        $company = $this->company();
        $dept = $this->department($company, clientsOnly: true);

        $ticket = $this->router()->route($dept, new ParsedInboundEmail(
            fromEmail: 'stranger@example.com', fromName: null, subject: 'Ερώτηση', body: 'Γεια',
        ));

        $this->assertNull($ticket, 'rejected — no ticket opened');
        $this->assertSame(0, Ticket::withoutGlobalScope(CompanyScope::class)->count());
    }

    public function test_reply_threads_by_references_to_a_prior_message(): void
    {
        $company = $this->company();
        $dept = $this->department($company);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'p@e.gr']);
        $ticket = $this->open($company, $customer);
        // Our OUTBOUND operator message carries a normalised (bracket-free) Message-ID.
        $ticket->messages()->create([
            'company_id' => $company->id, 'author_role' => TicketMessage::ROLE_OPERATOR,
            'body' => 'απάντηση', 'via' => TicketMessage::VIA_EMAIL, 'email_message_id' => 'sent-1@ekdosi',
        ]);

        // The reply's References arrive WITH angle brackets — threading must still match.
        $result = $this->router()->route($dept, new ParsedInboundEmail(
            fromEmail: 'p@e.gr', fromName: 'Πελ', subject: 'Απάντηση χωρίς token',
            body: 'δεν δούλεψε', messageId: '<reply-1@mail>', references: ['<sent-1@ekdosi>'],
        ));

        $this->assertNotNull($result);
        $this->assertSame($ticket->id, $result->id, 'threaded (bracket-agnostic) onto the existing ticket');
        $this->assertSame(TicketStatus::CustomerReply, $ticket->fresh()->status);
        $this->assertSame('reply-1@mail', $ticket->messages()->get()->last()->email_message_id, 'stored normalised');
    }

    public function test_a_cc_stranger_does_not_inject_into_someone_elses_thread(): void
    {
        $company = $this->company();
        $dept = $this->department($company);
        $owner = Customer::create(['company_id' => $company->id, 'name' => 'Ιδιοκτήτης', 'email' => 'owner@e.gr']);
        $ticket = $this->open($company, $owner);
        $ticket->messages()->create([
            'company_id' => $company->id, 'author_role' => TicketMessage::ROLE_OPERATOR,
            'body' => 'απάντηση', 'via' => TicketMessage::VIA_EMAIL, 'email_message_id' => 'sent-9@ekdosi',
        ]);
        $before = $ticket->messages()->count();

        // A stranger who was CC'd replies, References carrying our Message-ID + the token in the subject.
        $result = $this->router()->route($dept, new ParsedInboundEmail(
            fromEmail: 'stranger@evil.com', fromName: 'Ξένος',
            subject: "Re: [{$ticket->reference}]", body: 'κρυφάκουσμα',
            messageId: '<x@mail>', references: ['<sent-9@ekdosi>'],
        ));

        $this->assertNotNull($result);
        $this->assertNotSame($ticket->id, $result->id, 'a NEW ticket, not an injection into the owner\'s thread');
        $this->assertSame($before, $ticket->fresh()->messages()->count(), 'owner thread untouched');
        $this->assertNull($result->customer_id, 'stranger is a GUEST');
    }

    public function test_redelivery_of_the_same_message_is_idempotent(): void
    {
        $company = $this->company();
        $dept = $this->department($company);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'p@e.gr']);

        $email = new ParsedInboundEmail(
            fromEmail: 'p@e.gr', fromName: 'Πελ', subject: 'Θέμα', body: 'σώμα', messageId: '<dup-1@mail>',
        );

        $first = $this->router()->route($dept, $email);
        $second = $this->router()->route($dept, $email); // redelivery

        $this->assertSame($first->id, $second->id, 'same ticket, not a duplicate');
        $this->assertSame(1, Ticket::withoutGlobalScope(CompanyScope::class)->count());
        $this->assertSame(1, $first->fresh()->messages()->count(), 'no duplicate message');
    }

    public function test_message_id_idempotency_is_company_wide_across_departments(): void
    {
        $company = $this->company();
        $deptA = TicketDepartment::create(['company_id' => $company->id, 'name' => 'Τμήμα Α', 'email' => 'a@myip.gr', 'is_active' => true]);
        $deptB = TicketDepartment::create(['company_id' => $company->id, 'name' => 'Τμήμα Β', 'email' => 'b@myip.gr', 'is_active' => true]);
        Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'p@e.gr']);

        // The same Message-ID reaching two mailboxes of one company is ingested ONCE
        // (idempotency answers «seen this message-id in the company?»; placement is
        // decided by ownership/threading). Deliberately company-wide — a per-department
        // dedup would duplicate a redelivery of a message that had threaded/merged into
        // another department. «A ticket per department» is a separate design item.
        $email = new ParsedInboundEmail(
            fromEmail: 'p@e.gr', fromName: 'Πελ', subject: 'Και στα δύο', body: 'σώμα', messageId: '<multi-1@mail>',
        );
        $a = $this->router()->route($deptA, $email);
        $b = $this->router()->route($deptB, $email);

        $this->assertNotNull($a);
        $this->assertSame($a->id, $b->id, 'the second department delivery dedups to the same ticket');
        $this->assertSame(1, Ticket::withoutGlobalScope(CompanyScope::class)->count());
    }

    public function test_stacked_reply_prefixes_are_stripped(): void
    {
        $company = $this->company();
        $dept = $this->department($company);

        $ticket = $this->router()->route($dept, new ParsedInboundEmail(
            fromEmail: 'x@e.gr', fromName: 'X', subject: 'Re: Fwd: Σχετ: Πρόβλημα', body: 'σώμα',
        ));

        $this->assertSame('Πρόβλημα', $ticket?->subject);
    }

    public function test_reply_threads_by_subject_token(): void
    {
        $company = $this->company();
        $dept = $this->department($company);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'p@e.gr']);
        $ticket = $this->open($company, $customer);

        $result = $this->router()->route($dept, new ParsedInboundEmail(
            fromEmail: 'p@e.gr', fromName: 'Πελ',
            subject: "Re: πρόβλημα [{$ticket->reference}]", body: 'ακόμη', messageId: '<r2@mail>',
        ));

        $this->assertSame($ticket->id, $result?->id);
        $this->assertSame(2, $ticket->messages()->count());
    }

    public function test_matches_customer_by_secondary_email(): void
    {
        $company = $this->company();
        $dept = $this->department($company);
        $customer = Customer::create([
            'company_id' => $company->id, 'name' => 'Πελ', 'email' => 'main@e.gr', 'secondary_email' => 'alt@e.gr',
        ]);

        $ticket = $this->router()->route($dept, new ParsedInboundEmail(
            fromEmail: 'alt@e.gr', fromName: 'Πελ', subject: 'Θέμα', body: 'σώμα',
        ));

        $this->assertSame($customer->id, $ticket?->customer_id);
    }

    public function test_a_message_with_no_usable_sender_is_dropped(): void
    {
        $company = $this->company();
        $dept = $this->department($company);

        $result = $this->router()->route($dept, new ParsedInboundEmail(
            fromEmail: '  ', fromName: null, subject: 'Χωρίς αποστολέα', body: 'σώμα', messageId: '<n@x>',
        ));

        $this->assertNull($result, 'no From → dropped, not an unanswerable guest ticket');
        $this->assertSame(0, Ticket::withoutGlobalScope(CompanyScope::class)->count());
    }

    private function open(Company $company, Customer $customer): Ticket
    {
        return app(OpenTicket::class)->handle([
            'company_id' => $company->id, 'customer_id' => $customer->id,
            'ticket_department_id' => null, 'subject' => 'πρόβλημα', 'body' => 'αρχικό',
            'author_role' => TicketMessage::ROLE_CUSTOMER, 'via' => TicketMessage::VIA_PORTAL,
        ]);
    }
}
