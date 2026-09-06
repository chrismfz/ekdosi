<?php

namespace Tests\Feature\Support;

use App\Actions\Support\OpenTicket;
use App\Actions\Support\PostTicketMessage;
use App\Enums\TicketStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use App\Support\TicketReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Πυλώνας E — Phase 1a domain: reference format, the who-posted state machine,
 * tenant scoping, and the per-tenant kill-switch. No UI, no mail.
 */
class TicketDomainTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create([
            'name' => 'Sup OE', 'slug' => 'sup-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        app(CompanyContext::class)->clear();
    }

    protected function tearDown(): void
    {
        app(CompanyContext::class)->clear();
        parent::tearDown();
    }

    /** @param array<string, mixed> $overrides */
    private function open(array $overrides = []): Ticket
    {
        return app(OpenTicket::class)->handle(array_merge([
            'company_id' => $this->company->id,
            'subject' => 'Δεν μπορώ να συνδεθώ',
            'body' => 'Βοήθεια παρακαλώ',
            'author_role' => TicketMessage::ROLE_CUSTOMER,
            'via' => TicketMessage::VIA_PORTAL,
            'opened_via' => 'portal',
        ], $overrides));
    }

    public function test_reference_is_date_prefixed_with_an_unguessable_tail(): void
    {
        $ticket = $this->open();
        // TK-YYYY-MM-DD-xxxxxx, tail from the unambiguous 31-char alphabet (no 0/1/I/O/L).
        $this->assertMatchesRegularExpression('/^TK-\d{4}-\d{2}-\d{2}-[2-9A-HJKMNP-Z]{6}$/', $ticket->reference);
    }

    public function test_reference_is_unique_per_company(): void
    {
        $refs = collect(range(1, 30))->map(fn () => TicketReference::generate($this->company->id));
        $this->assertSame(30, $refs->unique()->count(), 'refs must not collide');
    }

    public function test_open_creates_ticket_at_open_with_its_first_message(): void
    {
        $ticket = $this->open();

        $this->assertSame(TicketStatus::Open, $ticket->status);
        $this->assertSame(1, $ticket->messages()->count());
        $this->assertNotNull($ticket->last_reply_at);
        $this->assertSame('customer', $ticket->last_reply_role);
    }

    public function test_operator_public_reply_marks_answered(): void
    {
        $ticket = $this->open();
        $op = $this->operator();

        app(PostTicketMessage::class)->handle($ticket, [
            'author_role' => TicketMessage::ROLE_OPERATOR,
            'author_id' => $op->id,
            'body' => 'Δοκίμασε reset',
        ]);
        $ticket->refresh();

        $this->assertSame(TicketStatus::Answered, $ticket->status);
        $this->assertSame('operator', $ticket->last_reply_role);
        $this->assertFalse($ticket->status->needsOperator());
    }

    public function test_customer_public_reply_returns_to_the_operator_queue(): void
    {
        $ticket = $this->open();
        app(PostTicketMessage::class)->handle($ticket, ['author_role' => TicketMessage::ROLE_OPERATOR, 'body' => 'κοίτα εδώ']);
        app(PostTicketMessage::class)->handle($ticket->refresh(), ['author_role' => TicketMessage::ROLE_CUSTOMER, 'body' => 'δεν δούλεψε']);
        $ticket->refresh();

        $this->assertSame(TicketStatus::CustomerReply, $ticket->status);
        $this->assertTrue($ticket->status->needsOperator());
    }

    public function test_internal_note_changes_neither_status_nor_last_reply(): void
    {
        $ticket = $this->open();
        $before = $ticket->last_reply_at;

        app(PostTicketMessage::class)->handle($ticket, [
            'author_role' => TicketMessage::ROLE_OPERATOR,
            'body' => 'εσωτερική σημείωση',
            'is_internal_note' => true,
        ]);
        $ticket->refresh();

        $this->assertSame(TicketStatus::Open, $ticket->status);
        $this->assertEquals($before, $ticket->last_reply_at);
        $this->assertSame(2, $ticket->messages()->count());
        $this->assertSame(1, $ticket->publicMessages()->count(), 'the note is not a public message');
    }

    public function test_a_public_reply_reopens_a_closed_ticket(): void
    {
        $ticket = $this->open();
        $ticket->update(['status' => TicketStatus::Closed, 'closed_at' => now()]);

        app(PostTicketMessage::class)->handle($ticket->refresh(), [
            'author_role' => TicketMessage::ROLE_CUSTOMER,
            'body' => 'ξανά πρόβλημα',
        ]);
        $ticket->refresh();

        $this->assertSame(TicketStatus::CustomerReply, $ticket->status);
        $this->assertNull($ticket->closed_at, 'reopening clears closed_at');
    }

    public function test_tickets_are_tenant_scoped(): void
    {
        $other = Company::create([
            'name' => 'Other', 'slug' => 'oth-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->open();
        app(OpenTicket::class)->handle([
            'company_id' => $other->id, 'subject' => 'x', 'body' => 'y',
            'author_role' => TicketMessage::ROLE_CUSTOMER,
        ]);

        app(CompanyContext::class)->set($this->company);
        $this->assertSame(1, Ticket::count());

        app(CompanyContext::class)->set($other);
        $this->assertSame(1, Ticket::count());
    }

    public function test_support_pillar_defaults_off(): void
    {
        $this->assertFalse($this->company->fresh()->hasSupport());
        $this->company->update(['support_enabled' => true]);
        $this->assertTrue($this->company->fresh()->hasSupport());
    }

    public function test_guest_vs_linked_customer(): void
    {
        $guest = $this->open(['requester_email' => 'stranger@example.com', 'requester_name' => 'Ξένος']);
        $this->assertTrue($guest->isGuest());
        $this->assertSame('Ξένος', $guest->requesterLabel());

        $cust = Customer::create(['company_id' => $this->company->id, 'name' => 'Πελ Α', 'email' => 'pel@a.gr']);
        $linked = $this->open(['customer_id' => $cust->id]);
        $this->assertFalse($linked->isGuest());
        $this->assertSame('Πελ Α', $linked->requesterLabel());
    }

    private function operator(): User
    {
        return User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x'),
        ]);
    }
}
