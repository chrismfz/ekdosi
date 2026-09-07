<?php

namespace Tests\Feature\Portal;

use App\Actions\Support\OpenTicket;
use App\Enums\TicketStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerUser;
use App\Models\CustomerUserAccess;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feedback-on-close (Πυλώνας E, Phase 4). A customer may rate a ticket ONLY when
 * it is closed AND its department invites feedback — fail-closed on everything
 * else (open ticket, feedback-off department, someone else's ticket).
 */
class PortalTicketRatingTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create([
            'name' => 'T', 'slug' => 'rate-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'support_enabled' => true,
        ]);
    }

    private function login(): CustomerUser
    {
        return CustomerUser::factory()->create([
            'password' => Hash::make('secret-pass-123'), 'status' => CustomerUser::STATUS_ACTIVE,
        ]);
    }

    private function grant(CustomerUser $login, Customer $customer): void
    {
        CustomerUserAccess::create([
            'customer_user_id' => $login->id, 'company_id' => $customer->company_id,
            'customer_id' => $customer->id, 'role' => CustomerUserAccess::ROLE_OWNER, 'granted_at' => now(),
        ]);
    }

    private function department(Company $company, bool $feedback): TicketDepartment
    {
        return TicketDepartment::create([
            'company_id' => $company->id, 'name' => 'Γενική', 'is_active' => true,
            'is_hidden' => false, 'feedback_on_close' => $feedback,
        ]);
    }

    private function ticket(Company $company, Customer $customer, TicketDepartment $dept, string $status = 'closed'): Ticket
    {
        $ticket = app(OpenTicket::class)->handle([
            'company_id' => $company->id, 'customer_id' => $customer->id, 'ticket_department_id' => $dept->id,
            'subject' => 'Θέμα', 'body' => 'σώμα', 'author_role' => TicketMessage::ROLE_CUSTOMER,
            'via' => TicketMessage::VIA_PORTAL, 'opened_via' => 'portal',
        ]);
        $ticket->update(['status' => $status, 'closed_at' => $status === 'closed' ? now() : null]);

        return $ticket->fresh();
    }

    public function test_customer_rates_a_closed_ticket(): void
    {
        $company = $this->company();
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'p@e.gr']);
        $login = $this->login();
        $this->grant($login, $customer);
        $ticket = $this->ticket($company, $customer, $this->department($company, feedback: true));

        $this->actingAs($login, 'portal')
            ->post("/user/tickets/{$ticket->id}/rate", ['rating' => 4, 'rating_comment' => 'μια χαρά'])
            ->assertRedirect(route('portal.tickets.show', $ticket->id));

        $ticket->refresh();
        $this->assertSame(4, $ticket->rating);
        $this->assertSame('μια χαρά', $ticket->rating_comment);
        $this->assertNotNull($ticket->rated_at);
    }

    public function test_cannot_rate_an_open_ticket(): void
    {
        $company = $this->company();
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'p@e.gr']);
        $login = $this->login();
        $this->grant($login, $customer);
        $ticket = $this->ticket($company, $customer, $this->department($company, feedback: true), status: 'open');

        $this->actingAs($login, 'portal')
            ->post("/user/tickets/{$ticket->id}/rate", ['rating' => 5])
            ->assertStatus(404);
        $this->assertNull($ticket->fresh()->rating);
    }

    public function test_cannot_rate_when_department_feedback_disabled(): void
    {
        $company = $this->company();
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'p@e.gr']);
        $login = $this->login();
        $this->grant($login, $customer);
        $ticket = $this->ticket($company, $customer, $this->department($company, feedback: false));

        $this->actingAs($login, 'portal')
            ->post("/user/tickets/{$ticket->id}/rate", ['rating' => 5])
            ->assertStatus(404);
        $this->assertNull($ticket->fresh()->rating);
    }

    public function test_cannot_rate_another_customers_ticket(): void
    {
        $company = $this->company();
        $mine = Customer::create(['company_id' => $company->id, 'name' => 'Δικός', 'email' => 'm@e.gr']);
        $other = Customer::create(['company_id' => $company->id, 'name' => 'Άλλος', 'email' => 'o@e.gr']);
        $login = $this->login();
        $this->grant($login, $mine); // NOT $other
        $foreign = $this->ticket($company, $other, $this->department($company, feedback: true));

        $this->actingAs($login, 'portal')
            ->post("/user/tickets/{$foreign->id}/rate", ['rating' => 1])
            ->assertStatus(404);
        $this->assertNull($foreign->fresh()->rating);
    }

    public function test_re_rating_updates_while_closed(): void
    {
        $company = $this->company();
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'p@e.gr']);
        $login = $this->login();
        $this->grant($login, $customer);
        $ticket = $this->ticket($company, $customer, $this->department($company, feedback: true));

        $this->actingAs($login, 'portal')->post("/user/tickets/{$ticket->id}/rate", ['rating' => 2]);
        $this->actingAs($login, 'portal')->post("/user/tickets/{$ticket->id}/rate", ['rating' => 5]);

        $this->assertSame(5, $ticket->fresh()->rating);
    }

    public function test_reopening_the_ticket_clears_a_stale_rating(): void
    {
        $company = $this->company();
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'p@e.gr']);
        $login = $this->login();
        $this->grant($login, $customer);
        $ticket = $this->ticket($company, $customer, $this->department($company, feedback: true));

        $this->actingAs($login, 'portal')->post("/user/tickets/{$ticket->id}/rate", ['rating' => 5]);
        $this->assertSame(5, $ticket->fresh()->rating);

        // A customer reply reopens the ticket → the rating (tied to the old closure) is dropped.
        $this->actingAs($login, 'portal')->post("/user/tickets/{$ticket->id}/reply", ['body' => 'ακόμη πρόβλημα']);

        $reopened = $ticket->fresh();
        $this->assertNotSame(TicketStatus::Closed, $reopened->status);
        $this->assertNull($reopened->rating);
        $this->assertNull($reopened->rated_at);
    }

    public function test_rating_out_of_range_is_rejected(): void
    {
        $company = $this->company();
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'p@e.gr']);
        $login = $this->login();
        $this->grant($login, $customer);
        $ticket = $this->ticket($company, $customer, $this->department($company, feedback: true));

        $this->actingAs($login, 'portal')
            ->post("/user/tickets/{$ticket->id}/rate", ['rating' => 6])
            ->assertSessionHasErrors('rating');
        $this->assertNull($ticket->fresh()->rating);
    }

    public function test_the_rating_form_shows_only_when_ratable(): void
    {
        $company = $this->company();
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'p@e.gr']);
        $login = $this->login();
        $this->grant($login, $customer);
        $dept = $this->department($company, feedback: true);

        $closed = $this->ticket($company, $customer, $dept, status: 'closed');
        $this->actingAs($login, 'portal')->get("/user/tickets/{$closed->id}")
            ->assertOk()->assertSee('Πώς σας φάνηκε');

        $open = $this->ticket($company, $customer, $dept, status: 'open');
        $this->actingAs($login, 'portal')->get("/user/tickets/{$open->id}")
            ->assertOk()->assertDontSee('Πώς σας φάνηκε');
    }
}
