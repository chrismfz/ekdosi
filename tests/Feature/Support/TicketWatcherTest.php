<?php

namespace Tests\Feature\Support;

use App\Actions\Support\OpenTicket;
use App\Actions\Support\PostTicketMessage;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Models\TicketWatcher;
use App\Models\User;
use App\Services\Support\TicketNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Πυλώνας E, Phase 4 — operator bell + watchers/CC. A customer's public message
 * rings the handling operators' bell; an operator who replies auto-watches; the
 * recipient set is dept-agents (or all tenant users) ∪ assignee ∪ watchers.
 */
class TicketWatcherTest extends TestCase
{
    use RefreshDatabase;

    private function company(bool $support = true): Company
    {
        return Company::create([
            'name' => 'MyIP', 'slug' => 'w-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'support_enabled' => $support,
        ]);
    }

    private function operator(Company $company, string $name = 'Op'): User
    {
        $user = User::create(['name' => $name, 'email' => strtolower($name).'-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $company->users()->attach($user->id);

        return $user;
    }

    private function department(Company $company): TicketDepartment
    {
        return TicketDepartment::create([
            'company_id' => $company->id, 'name' => 'Γενική', 'email' => 'support@myip.gr', 'is_active' => true,
        ]);
    }

    private function openCustomerTicket(Company $company, TicketDepartment $dept, ?Customer $customer = null): Ticket
    {
        return app(OpenTicket::class)->handle([
            'company_id' => $company->id,
            'customer_id' => $customer?->id,
            'ticket_department_id' => $dept->id,
            'requester_email' => 'c@e.gr',
            'subject' => 'Πρόβλημα',
            'body' => 'αρχικό',
            'author_role' => TicketMessage::ROLE_CUSTOMER,
            'via' => TicketMessage::VIA_EMAIL,
        ]);
    }

    public function test_a_customer_message_rings_the_operator_bell(): void
    {
        $company = $this->company();
        $op = $this->operator($company);
        $dept = $this->department($company);

        $this->openCustomerTicket($company, $dept);

        $this->assertSame(1, $op->fresh()->notifications()->count(), 'the opening customer message pings the operator');
    }

    public function test_an_operator_reply_does_not_ring_a_bell_but_auto_watches(): void
    {
        $company = $this->company();
        $op = $this->operator($company);
        $dept = $this->department($company);
        $ticket = $this->openCustomerTicket($company, $dept);
        $op->fresh()->notifications()->delete(); // clear the opening bell

        app(PostTicketMessage::class)->handle($ticket, [
            'author_role' => TicketMessage::ROLE_OPERATOR, 'author_id' => $op->id, 'body' => 'απάντηση',
        ]);

        $this->assertSame(0, $op->fresh()->notifications()->count(), 'an operator reply must not ring the operators');
        $this->assertTrue($ticket->fresh()->isWatchedBy($op), 'the replying operator auto-watches');
        $this->assertSame(TicketWatcher::SOURCE_PARTICIPANT, $ticket->watchers()->where('user_id', $op->id)->value('source'));
    }

    public function test_recipients_are_agents_assignee_and_watchers_not_the_whole_tenant(): void
    {
        $company = $this->company();
        $agent = $this->operator($company, 'Agent');
        $assignee = $this->operator($company, 'Assignee');
        $watcher = $this->operator($company, 'Watcher');
        $bystander = $this->operator($company, 'Bystander'); // tenant user, NOT on the department
        $dept = $this->department($company);
        $dept->agents()->attach($agent->id);

        $ticket = $this->openCustomerTicket($company, $dept);
        $ticket->update(['assigned_to' => $assignee->id]);
        $ticket->watch($watcher);

        $ids = app(TicketNotifier::class)->operatorRecipients($ticket->fresh())->pluck('id')->all();

        sort($ids);
        $expected = [$agent->id, $assignee->id, $watcher->id];
        sort($expected);
        $this->assertSame($expected, $ids, 'a department WITH agents routes to agents ∪ assignee ∪ watchers only');
        $this->assertNotContains($bystander->id, $ids, 'a non-agent tenant user is not spammed');
    }

    public function test_no_agents_falls_back_to_all_tenant_users(): void
    {
        $company = $this->company();
        $a = $this->operator($company, 'A');
        $b = $this->operator($company, 'B');
        $dept = $this->department($company); // no agents attached

        $ticket = $this->openCustomerTicket($company, $dept);

        $ids = app(TicketNotifier::class)->operatorRecipients($ticket->fresh())->pluck('id')->all();
        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);
    }

    public function test_support_off_rings_no_bell(): void
    {
        $company = $this->company(support: false);
        $op = $this->operator($company);
        $dept = $this->department($company);

        $this->openCustomerTicket($company, $dept);

        $this->assertSame(0, $op->fresh()->notifications()->count(), 'pillar off → no bell');
    }

    public function test_watch_is_idempotent_and_unwatch_removes(): void
    {
        $company = $this->company();
        $op = $this->operator($company);
        $dept = $this->department($company);
        $ticket = $this->openCustomerTicket($company, $dept);

        $ticket->watch($op);
        $ticket->watch($op); // second call must not duplicate nor change the source
        $this->assertSame(1, $ticket->watchers()->where('user_id', $op->id)->count());

        $ticket->unwatch($op);
        $this->assertFalse($ticket->fresh()->isWatchedBy($op));
    }

    public function test_email_watcher_helpers_normalise_and_dedupe(): void
    {
        $company = $this->company();
        $op = $this->operator($company);
        $dept = $this->department($company);
        $ticket = $this->openCustomerTicket($company, $dept);

        $ticket->addEmailWatcher('  Boss@Example.GR ');
        $ticket->addEmailWatcher('boss@example.gr'); // same, different case/space → no duplicate
        $this->assertNull($ticket->addEmailWatcher('   '), 'a blank email is ignored');

        $this->assertSame(['boss@example.gr'], $ticket->watcherEmailAddresses());
    }
}
