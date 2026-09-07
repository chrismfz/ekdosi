<?php

namespace Tests\Feature\Support;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Scopes\CompanyScope;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Services\Support\Inbound\InboundTicketRouter;
use App\Services\Support\Inbound\ParsedInboundEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Πυλώνας E, Phase 4 follow-up — a reply from a watcher/CC threads onto the ticket
 * (they are a legitimate participant), while a stranger holding the same token does
 * NOT (the ownership guard from Phase 3a still holds).
 */
class WatcherReplyThreadingTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create([
            'name' => 'T', 'slug' => 'wr-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'support_enabled' => true,
            'mail_from_address' => 'noreply@myip.gr',
        ]);
    }

    private function department(Company $company): TicketDepartment
    {
        return TicketDepartment::create([
            'company_id' => $company->id, 'name' => 'Γενική', 'email' => 'support@myip.gr', 'is_active' => true,
        ]);
    }

    private function router(): InboundTicketRouter
    {
        return app(InboundTicketRouter::class);
    }

    /** Open a ticket from a known customer who CC'd dev@agency.tld (→ dev is a watcher). */
    private function ticketWithWatcher(Company $company, TicketDepartment $dept): Ticket
    {
        Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'client@acme.gr']);

        $ticket = $this->router()->route($dept, new ParsedInboundEmail(
            fromEmail: 'client@acme.gr', fromName: 'Πελ', subject: 'Βοήθεια', body: 'αρχικό', messageId: '<a@x>',
            to: ['support@myip.gr'], cc: ['dev@agency.tld'],
        ));

        $this->assertContains('dev@agency.tld', $ticket->watcherEmailAddresses(), 'dev is a CC watcher');

        return $ticket;
    }

    public function test_a_watcher_reply_threads_onto_the_ticket(): void
    {
        $company = $this->company();
        $dept = $this->department($company);
        $ticket = $this->ticketWithWatcher($company, $dept);

        $before = $ticket->messages()->count();

        $same = $this->router()->route($dept, new ParsedInboundEmail(
            fromEmail: 'dev@agency.tld', fromName: 'Dev', subject: 'Re: ['.$ticket->reference.'] Βοήθεια',
            body: 'από τον developer', messageId: '<d@x>',
            to: ['support@myip.gr'],
        ));

        $this->assertSame($ticket->id, $same->id, 'the watcher reply threaded onto the same ticket');
        $this->assertSame($before + 1, $ticket->messages()->count(), 'the reply was appended');
        $this->assertSame(1, Ticket::withoutGlobalScope(CompanyScope::class)->count(), 'no new ticket opened');

        // The real sender is noted in the body (no message-level sender column), so
        // the developer's words are not mis-attributed to the customer.
        $this->assertStringContainsString('από dev@agency.tld', (string) $ticket->messages()->get()->last()->body);
    }

    public function test_a_watcher_customer_reply_does_not_subscribe_third_parties_to_the_owners_ticket(): void
    {
        $company = $this->company();
        $dept = $this->department($company);
        $ticket = $this->ticketWithWatcher($company, $dept); // owner client@acme.gr, watcher dev@agency.tld

        // dev is ALSO a registered customer here, and replies CC'ing a new third party.
        Customer::create(['company_id' => $company->id, 'name' => 'Dev', 'email' => 'dev@agency.tld']);

        $this->router()->route($dept, new ParsedInboundEmail(
            fromEmail: 'dev@agency.tld', fromName: 'Dev', subject: 'Re: ['.$ticket->reference.'] Βοήθεια',
            body: 'reply', messageId: '<d2@x>',
            to: ['support@myip.gr'], cc: ['newthird@x.tld'],
        ));

        // It threaded (dev is a watcher) but must NOT add newthird to the OWNER's ticket.
        $this->assertNotContains('newthird@x.tld', $ticket->fresh()->watcherEmailAddresses(),
            'a non-owner reply must not subscribe third parties to the ticket');
    }

    public function test_a_stranger_with_the_token_does_not_thread(): void
    {
        $company = $this->company();
        $dept = $this->department($company);
        $ticket = $this->ticketWithWatcher($company, $dept);

        // A stranger who is NOT a watcher, holding the (non-secret) token → new ticket.
        $this->router()->route($dept, new ParsedInboundEmail(
            fromEmail: 'stranger@nope.tld', fromName: 'X', subject: 'Re: ['.$ticket->reference.'] Βοήθεια',
            body: 'inject', messageId: '<s@x>',
            to: ['support@myip.gr'],
        ));

        $this->assertSame(2, Ticket::withoutGlobalScope(CompanyScope::class)->count(), 'the stranger opened a separate ticket');
        $this->assertSame(1, $ticket->messages()->count(), 'nothing was injected into the original thread');
    }
}
