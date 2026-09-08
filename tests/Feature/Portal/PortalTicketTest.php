<?php

namespace Tests\Feature\Portal;

use App\Actions\Support\MergeTickets;
use App\Actions\Support\OpenTicket;
use App\Actions\Support\PostTicketMessage;
use App\Enums\TicketStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerUser;
use App\Models\CustomerUserAccess;
use App\Models\Scopes\CompanyScope;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * «Τα αιτήματά μου» (Πυλώνας E, Phase 2). The load-bearing properties: a portal
 * login only ever sees/acts on tickets of a customer it holds an ACTIVE grant to
 * (fail-closed), and an internal note NEVER reaches the portal.
 */
class PortalTicketTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create([
            'name' => 'T', 'slug' => 'tk-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'support_enabled' => true,
        ]);
    }

    private function login(): CustomerUser
    {
        return CustomerUser::factory()->create([
            'password' => Hash::make('secret-pass-123'),
            'status' => CustomerUser::STATUS_ACTIVE,
        ]);
    }

    private function grant(CustomerUser $login, Customer $customer): CustomerUserAccess
    {
        return CustomerUserAccess::create([
            'customer_user_id' => $login->id,
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'role' => CustomerUserAccess::ROLE_OWNER,
            'granted_at' => now(),
        ]);
    }

    private function department(Company $company): TicketDepartment
    {
        return TicketDepartment::create([
            'company_id' => $company->id, 'name' => 'Γενική', 'is_active' => true, 'is_hidden' => false,
        ]);
    }

    public function test_customer_opens_a_ticket_through_the_portal(): void
    {
        $company = $this->company();
        $dept = $this->department($company);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'p@e.gr']);
        $login = $this->login();
        $this->grant($login, $customer);

        $this->actingAs($login, 'portal')->post('/user/tickets', [
            'target' => $customer->id.'_'.$dept->id,
            'subject' => 'Πρόβλημα σύνδεσης',
            'priority' => 'normal',
            'body' => 'Δεν μπορώ να μπω',
        ])->assertRedirect();

        $ticket = Ticket::withoutGlobalScope(CompanyScope::class)->first();
        $this->assertNotNull($ticket);
        $this->assertSame($customer->id, $ticket->customer_id);
        $this->assertSame($company->id, $ticket->company_id);
        $this->assertSame('portal', $ticket->opened_via);
        $this->assertSame(TicketStatus::Open, $ticket->status);
        $this->assertSame(TicketMessage::VIA_PORTAL, $ticket->messages()->first()->via);
    }

    public function test_list_and_show_are_grant_scoped_fail_closed(): void
    {
        $company = $this->company();
        $mine = Customer::create(['company_id' => $company->id, 'name' => 'Δικός', 'email' => 'm@e.gr']);
        $other = Customer::create(['company_id' => $company->id, 'name' => 'Άλλος', 'email' => 'o@e.gr']);
        $login = $this->login();
        $this->grant($login, $mine); // only $mine

        $myTicket = $this->openFor($company, $mine);
        $otherTicket = $this->openFor($company, $other);

        $list = $this->actingAs($login, 'portal')->get('/user/tickets');
        $list->assertOk()->assertSee($myTicket->reference)->assertDontSee($otherTicket->reference);

        // A non-granted ticket id fails closed.
        $this->actingAs($login, 'portal')->get("/user/tickets/{$otherTicket->id}")->assertStatus(404);
        $this->actingAs($login, 'portal')->get("/user/tickets/{$myTicket->id}")->assertOk();
    }

    public function test_internal_notes_never_reach_the_portal(): void
    {
        $company = $this->company();
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'p@e.gr']);
        $login = $this->login();
        $this->grant($login, $customer);
        $ticket = $this->openFor($company, $customer);

        app(PostTicketMessage::class)->handle($ticket, [
            'author_role' => TicketMessage::ROLE_OPERATOR, 'via' => TicketMessage::VIA_OPERATOR,
            'body' => 'ΔΗΜΟΣΙΑ_ΑΠΑΝΤΗΣΗ_ΟΡΑΤΗ',
        ]);
        app(PostTicketMessage::class)->handle($ticket, [
            'author_role' => TicketMessage::ROLE_OPERATOR, 'via' => TicketMessage::VIA_OPERATOR,
            'is_internal_note' => true, 'body' => 'ΚΡΥΦΗ_ΣΗΜΕΙΩΣΗ_ΔΙΑΡΡΟΗ',
        ]);

        $this->actingAs($login, 'portal')->get("/user/tickets/{$ticket->id}")
            ->assertOk()
            ->assertSee('ΔΗΜΟΣΙΑ_ΑΠΑΝΤΗΣΗ_ΟΡΑΤΗ')
            ->assertDontSee('ΚΡΥΦΗ_ΣΗΜΕΙΩΣΗ_ΔΙΑΡΡΟΗ');
    }

    public function test_customer_reply_moves_ticket_to_the_operator_queue(): void
    {
        $company = $this->company();
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'p@e.gr']);
        $login = $this->login();
        $this->grant($login, $customer);
        $ticket = $this->openFor($company, $customer);
        // operator answered → Answered
        app(PostTicketMessage::class)->handle($ticket, ['author_role' => TicketMessage::ROLE_OPERATOR, 'body' => 'x']);

        $this->actingAs($login, 'portal')->post("/user/tickets/{$ticket->id}/reply", [
            'body' => 'ακόμη δεν δουλεύει',
        ])->assertRedirect();

        $this->assertSame(TicketStatus::CustomerReply, $ticket->fresh()->status);
    }

    public function test_support_disabled_company_is_invisible_in_the_portal(): void
    {
        $company = Company::create([
            'name' => 'Off', 'slug' => 'off-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'support_enabled' => false,
        ]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'p@e.gr']);
        $login = $this->login();
        $this->grant($login, $customer);
        $ticket = $this->openFor($company, $customer);

        $this->actingAs($login, 'portal')->get('/user/tickets')->assertOk()->assertDontSee($ticket->reference);
        $this->actingAs($login, 'portal')->get("/user/tickets/{$ticket->id}")->assertStatus(404);
        $this->actingAs($login, 'portal')->post("/user/tickets/{$ticket->id}/reply", ['body' => 'x'])->assertStatus(404);
    }

    public function test_a_merged_ticket_is_hidden_and_redirects_to_its_survivor(): void
    {
        $company = $this->company();
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'p@e.gr']);
        $login = $this->login();
        $this->grant($login, $customer);

        $target = $this->openFor($company, $customer);
        $source = $this->openFor($company, $customer);
        app(MergeTickets::class)->handle($source, $target);

        // the merged duplicate is gone from the list…
        $this->actingAs($login, 'portal')->get('/user/tickets')
            ->assertOk()->assertSee($target->reference)->assertDontSee($source->reference);

        // …and opening it redirects the customer to the survivor.
        $this->actingAs($login, 'portal')->get("/user/tickets/{$source->id}")
            ->assertRedirect(route('portal.tickets.show', $target->id));
    }

    private function openFor(Company $company, Customer $customer): Ticket
    {
        return app(OpenTicket::class)->handle([
            'company_id' => $company->id, 'customer_id' => $customer->id,
            'subject' => 'Θέμα', 'body' => 'σώμα',
            'author_role' => TicketMessage::ROLE_CUSTOMER, 'via' => TicketMessage::VIA_PORTAL,
            'opened_via' => 'portal',
        ]);
    }
}
