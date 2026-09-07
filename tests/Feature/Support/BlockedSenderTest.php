<?php

namespace Tests\Feature\Support;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Models\Ticket;
use App\Models\TicketBlockedSender;
use App\Models\TicketDepartment;
use App\Services\Support\Inbound\InboundTicketRouter;
use App\Services\Support\Inbound\ParsedInboundEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Πυλώνας E, Phase 4 — spam/block-sender. A blocked address (or its whole domain)
 * is dropped by the inbound router BEFORE any customer match or ticket creation.
 */
class BlockedSenderTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create([
            'name' => 'T', 'slug' => 'blk-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'support_enabled' => true,
        ]);
    }

    private function department(Company $company): TicketDepartment
    {
        return TicketDepartment::create([
            'company_id' => $company->id, 'name' => 'Γενική', 'is_active' => true, 'is_hidden' => false,
        ]);
    }

    private function email(string $from): ParsedInboundEmail
    {
        return new ParsedInboundEmail(
            fromEmail: $from, fromName: 'X', subject: 'Θέμα', body: 'σώμα', messageId: '<m-'.uniqid().'@x>',
        );
    }

    public function test_a_blocked_email_is_dropped(): void
    {
        $company = $this->company();
        $dept = $this->department($company);
        TicketBlockedSender::create(['company_id' => $company->id, 'pattern' => 'Spammer@Bad.GR']);

        $ticket = app(InboundTicketRouter::class)->route($dept, $this->email('spammer@bad.gr'));

        $this->assertNull($ticket, 'the blocked sender opens no ticket');
        $this->assertSame(0, Ticket::withoutGlobalScope(CompanyScope::class)->count());
    }

    public function test_a_blocked_domain_drops_every_sender_on_it(): void
    {
        $company = $this->company();
        $dept = $this->department($company);
        TicketBlockedSender::create(['company_id' => $company->id, 'pattern' => '@bad.gr']); // stored bare «bad.gr»

        $this->assertNull(app(InboundTicketRouter::class)->route($dept, $this->email('anyone@bad.gr')));
        $this->assertNull(app(InboundTicketRouter::class)->route($dept, $this->email('other@bad.gr')));
        $this->assertSame(0, Ticket::withoutGlobalScope(CompanyScope::class)->count());
    }

    public function test_an_unblocked_sender_still_opens_a_ticket(): void
    {
        $company = $this->company();
        $dept = $this->department($company);
        TicketBlockedSender::create(['company_id' => $company->id, 'pattern' => 'bad.gr']);

        $ticket = app(InboundTicketRouter::class)->route($dept, $this->email('good@example.gr'));

        $this->assertNotNull($ticket);
        $this->assertSame('good@example.gr', $ticket->requester_email);
    }

    public function test_a_block_is_scoped_to_its_tenant(): void
    {
        $companyA = $this->company();
        $companyB = $this->company();
        TicketBlockedSender::create(['company_id' => $companyA->id, 'pattern' => 'bad.gr']);

        // Same sender/domain, but company B did NOT block it → not blocked there.
        $this->assertTrue(TicketBlockedSender::isBlocked($companyA->id, 'x@bad.gr'));
        $this->assertFalse(TicketBlockedSender::isBlocked($companyB->id, 'x@bad.gr'));
    }

    public function test_normalize_pattern_lowercases_and_strips_leading_at(): void
    {
        $this->assertSame('bad.gr', TicketBlockedSender::normalizePattern(' @Bad.GR '));
        $this->assertSame('spammer@bad.gr', TicketBlockedSender::normalizePattern('Spammer@Bad.GR'));
        $this->assertSame('', TicketBlockedSender::normalizePattern('   '));
    }
}
