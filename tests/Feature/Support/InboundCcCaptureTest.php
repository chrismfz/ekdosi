<?php

namespace Tests\Feature\Support;

use App\Models\Company;
use App\Models\Customer;
use App\Models\TicketBlockedSender;
use App\Models\TicketDepartment;
use App\Models\TicketWatcher;
use App\Services\Support\Inbound\InboundTicketRouter;
use App\Services\Support\Inbound\ParsedInboundEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Πυλώνας E, Phase 4 — inbound-CC capture. The other parties a KNOWN customer
 * looped in (To + Cc) become email watchers/CC on the ticket, so our replies copy
 * them too. Only for a known sender (never an anonymous open-relay), and never the
 * sender / our mailbox / the owner / a blocked address.
 */
class InboundCcCaptureTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create([
            'name' => 'T', 'slug' => 'cc-'.uniqid(), 'country_code' => 'GR',
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

    private function customer(Company $company, string $email): Customer
    {
        return Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => $email]);
    }

    private function router(): InboundTicketRouter
    {
        return app(InboundTicketRouter::class);
    }

    public function test_cc_and_to_recipients_become_watchers_minus_sender_and_us(): void
    {
        $company = $this->company();
        $dept = $this->department($company);
        $this->customer($company, 'chris@gmail.com'); // a KNOWN sender

        $email = new ParsedInboundEmail(
            fromEmail: 'chris@gmail.com', fromName: 'Chris', subject: 'Βοήθεια', body: 'σώμα',
            messageId: '<a@x>',
            to: ['support@myip.gr', 'chris@gmail.com'],       // us + the sender itself
            cc: ['dev@agency.tld', 'Supplier@Other.GR', 'noreply@myip.gr'], // two real CCs + our From
        );

        $ticket = $this->router()->route($dept, $email);
        $this->assertNotNull($ticket);

        $watchers = $ticket->watcherEmailAddresses();
        sort($watchers);
        $this->assertSame(['dev@agency.tld', 'supplier@other.gr'], $watchers, 'only the third-party recipients, lowercased');
        $this->assertSame(TicketWatcher::SOURCE_CC, $ticket->watchers()->where('email', 'dev@agency.tld')->value('source'));
    }

    public function test_an_unknown_sender_gets_no_cc_capture(): void
    {
        $company = $this->company();
        $dept = $this->department($company); // NOT clients_only → a guest ticket opens

        $email = new ParsedInboundEmail(
            fromEmail: 'stranger@spam.tld', fromName: 'X', subject: 'Θέμα', body: 'σώμα', messageId: '<u@x>',
            to: ['support@myip.gr'], cc: ['victim@somewhere.gr'],
        );

        $ticket = $this->router()->route($dept, $email);
        $this->assertNotNull($ticket, 'a guest ticket still opens');
        $this->assertSame([], $ticket->watcherEmailAddresses(), 'an anonymous sender cannot subscribe third parties');
    }

    public function test_the_owner_customer_email_is_not_added_as_a_cc_watcher(): void
    {
        $company = $this->company();
        $dept = $this->department($company);
        $this->customer($company, 'client@acme.gr');

        $email = new ParsedInboundEmail(
            fromEmail: 'client@acme.gr', fromName: 'Πελ', subject: 'Θέμα', body: 'σώμα', messageId: '<b@x>',
            to: ['support@myip.gr'], cc: ['client@acme.gr', 'colleague@acme.gr'],
        );

        $ticket = $this->router()->route($dept, $email);
        $this->assertSame(['colleague@acme.gr'], $ticket->watcherEmailAddresses(), 'the owner is never a CC watcher');
    }

    public function test_a_blocked_cc_is_not_captured(): void
    {
        $company = $this->company();
        $dept = $this->department($company);
        $this->customer($company, 'chris@gmail.com');
        TicketBlockedSender::create(['company_id' => $company->id, 'pattern' => 'blocked@bad.gr']);

        $email = new ParsedInboundEmail(
            fromEmail: 'chris@gmail.com', fromName: 'X', subject: 'Θέμα', body: 'σώμα', messageId: '<bk@x>',
            to: ['support@myip.gr'], cc: ['blocked@bad.gr', 'ok@third.tld'],
        );

        $ticket = $this->router()->route($dept, $email);
        $this->assertSame(['ok@third.tld'], $ticket->watcherEmailAddresses(), 'a blocked address is not subscribed');
    }

    public function test_invalid_addresses_are_dropped(): void
    {
        $company = $this->company();
        $dept = $this->department($company);
        $this->customer($company, 'chris@gmail.com');

        $email = new ParsedInboundEmail(
            fromEmail: 'chris@gmail.com', fromName: 'X', subject: 'Θέμα', body: 'σώμα', messageId: '<c@x>',
            to: ['support@myip.gr'], cc: ['not-an-email', '', 'ok@third.tld'],
        );

        $ticket = $this->router()->route($dept, $email);
        $this->assertSame(['ok@third.tld'], $ticket->watcherEmailAddresses());
    }

    public function test_recapture_on_a_reply_does_not_duplicate(): void
    {
        $company = $this->company();
        $dept = $this->department($company);
        $this->customer($company, 'chris@gmail.com');

        $first = new ParsedInboundEmail(
            fromEmail: 'chris@gmail.com', fromName: 'X', subject: 'Θέμα', body: 'πρώτο', messageId: '<d1@x>',
            to: ['support@myip.gr'], cc: ['dev@agency.tld'],
        );
        $ticket = $this->router()->route($dept, $first);

        // A reply from the SAME sender, threaded via the [TK-…] subject token, CC'ing dev again.
        $reply = new ParsedInboundEmail(
            fromEmail: 'chris@gmail.com', fromName: 'X', subject: 'Re: ['.$ticket->reference.'] Θέμα',
            body: 'δεύτερο', messageId: '<d2@x>',
            to: ['support@myip.gr'], cc: ['dev@agency.tld'],
        );
        $same = $this->router()->route($dept, $reply);

        $this->assertSame($ticket->id, $same->id, 'the reply threaded onto the same ticket');
        $this->assertSame(1, $ticket->watchers()->where('email', 'dev@agency.tld')->count(), 'no duplicate watcher');
    }
}
