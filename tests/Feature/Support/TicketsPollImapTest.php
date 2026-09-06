<?php

namespace Tests\Feature\Support;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Scopes\CompanyScope;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketPollRun;
use App\Services\Support\Inbound\ImapMailbox;
use App\Services\Support\Inbound\ParsedInboundEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeImapMailbox;
use Tests\TestCase;

/**
 * Πυλώνας E, Phase 3b — the `tickets:poll-imap` orchestration, driven by a fake
 * mailbox (no live IMAP): routes inbound email into tickets, records a health run,
 * and respects the pillar gate + per-department mail config.
 */
class TicketsPollImapTest extends TestCase
{
    use RefreshDatabase;

    private function company(bool $support = true): Company
    {
        return Company::create([
            'name' => 'T', 'slug' => 'poll-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'support_enabled' => $support,
        ]);
    }

    private function mailDepartment(Company $company): TicketDepartment
    {
        return TicketDepartment::create([
            'company_id' => $company->id, 'name' => 'Γενική', 'is_active' => true,
            'imap_host' => 'mail.example', 'imap_username' => 'u', 'imap_password' => 'p',
        ]);
    }

    public function test_poll_routes_inbound_email_and_records_a_run(): void
    {
        $company = $this->company();
        $dept = $this->mailDepartment($company);
        Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'c@e.gr']);

        $fake = new FakeImapMailbox;
        $fake->queue($dept, new ParsedInboundEmail(
            fromEmail: 'c@e.gr', fromName: 'Πελ', subject: 'Βοήθεια', body: 'πρόβλημα', messageId: '<m1@x>',
        ));
        $this->app->instance(ImapMailbox::class, $fake);

        $this->artisan('tickets:poll-imap')->assertExitCode(0);

        $tickets = Ticket::withoutGlobalScope(CompanyScope::class)->get();
        $this->assertCount(1, $tickets);
        $this->assertSame('email', $tickets->first()->opened_via);
        $this->assertCount(1, $fake->marked, 'the routed message was marked seen');

        $run = TicketPollRun::withoutGlobalScope(CompanyScope::class)->first();
        $this->assertNotNull($run);
        $this->assertTrue($run->connected);
        $this->assertSame(1, $run->fetched);
        $this->assertSame(1, $run->processed);
        $this->assertSame($dept->id, $run->ticket_department_id);
    }

    public function test_departments_without_mail_config_are_skipped(): void
    {
        $company = $this->company();
        TicketDepartment::create(['company_id' => $company->id, 'name' => 'Χωρίς mail', 'is_active' => true]);

        $fake = new FakeImapMailbox;
        $this->app->instance(ImapMailbox::class, $fake);

        $this->artisan('tickets:poll-imap')->assertExitCode(0);
        $this->assertSame(0, TicketPollRun::withoutGlobalScope(CompanyScope::class)->count(), 'no mail config → not polled');
    }

    public function test_support_disabled_tenant_is_skipped(): void
    {
        $company = $this->company(support: false);
        $dept = $this->mailDepartment($company);

        $fake = new FakeImapMailbox;
        $fake->queue($dept, new ParsedInboundEmail(fromEmail: 'x@e.gr', fromName: null, subject: 'X', body: 'y'));
        $this->app->instance(ImapMailbox::class, $fake);

        $this->artisan('tickets:poll-imap')->assertExitCode(0);
        $this->assertSame(0, Ticket::withoutGlobalScope(CompanyScope::class)->count(), 'support-disabled tenant not polled');
    }
}
