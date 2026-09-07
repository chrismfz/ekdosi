<?php

namespace Tests\Feature\Support;

use App\Actions\Support\OpenTicket;
use App\Jobs\SendTicketFeedbackInvite;
use App\Mail\TicketFeedbackMail;
use App\Models\Company;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Services\TenantMailerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Πυλώνας E, Phase 4 follow-up — feedback-on-close email invite + public SIGNED
 * rating page (no login). The signed link is the authorization; rating is gated on
 * canBeRated; an unsigned link is rejected.
 */
class TicketFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function company(bool $support = true): Company
    {
        return Company::create([
            'name' => 'T', 'slug' => 'fb-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'support_enabled' => $support,
            'mail_from_address' => 'noreply@myip.gr', 'mail_from_name' => 'MyIP',
        ]);
    }

    private function department(Company $company, bool $feedback): TicketDepartment
    {
        return TicketDepartment::create([
            'company_id' => $company->id, 'name' => 'Τμήμα '.uniqid(), 'email' => 'support-'.uniqid().'@myip.gr',
            'is_active' => true, 'feedback_on_close' => $feedback,
        ]);
    }

    private function ticket(Company $company, TicketDepartment $dept, string $status = 'closed', string $email = 'c@e.gr'): Ticket
    {
        $ticket = app(OpenTicket::class)->handle([
            'company_id' => $company->id, 'ticket_department_id' => $dept->id, 'requester_email' => $email,
            'subject' => 'Θέμα', 'body' => 'x', 'author_role' => TicketMessage::ROLE_CUSTOMER, 'via' => TicketMessage::VIA_EMAIL,
        ]);
        $ticket->update(['status' => $status, 'closed_at' => $status === 'closed' ? now() : null]);

        return $ticket->fresh();
    }

    private function deliver(int $ticketId): void
    {
        (new SendTicketFeedbackInvite($ticketId))->handle(app(TenantMailerFactory::class));
    }

    // ── the public signed page ───────────────────────────────────────────────

    public function test_a_signed_link_shows_the_rating_form(): void
    {
        $company = $this->company();
        $ticket = $this->ticket($company, $this->department($company, feedback: true));

        $this->get(URL::signedRoute('support.feedback.show', ['ticket' => $ticket->id]))
            ->assertOk()->assertSee('Πώς σας φάνηκε');
    }

    public function test_an_unsigned_link_is_rejected(): void
    {
        $company = $this->company();
        $ticket = $this->ticket($company, $this->department($company, feedback: true));

        $this->get(route('support.feedback.show', ['ticket' => $ticket->id]))->assertForbidden();
    }

    public function test_a_signed_post_records_the_rating(): void
    {
        $company = $this->company();
        $ticket = $this->ticket($company, $this->department($company, feedback: true));

        $this->post(URL::signedRoute('support.feedback.store', ['ticket' => $ticket->id]), ['rating' => 5, 'rating_comment' => 'τέλεια'])
            ->assertRedirect();

        $ticket->refresh();
        $this->assertSame(5, $ticket->rating);
        $this->assertSame('τέλεια', $ticket->rating_comment);
    }

    public function test_a_signed_post_on_a_non_ratable_ticket_is_404(): void
    {
        $company = $this->company();
        $open = $this->ticket($company, $this->department($company, feedback: true), status: 'open');

        $this->post(URL::signedRoute('support.feedback.store', ['ticket' => $open->id]), ['rating' => 5])
            ->assertNotFound();
        $this->assertNull($open->fresh()->rating);
    }

    // ── the invite job ───────────────────────────────────────────────────────

    public function test_the_invite_is_emailed_for_a_ratable_ticket(): void
    {
        Mail::fake();
        $company = $this->company();
        $ticket = $this->ticket($company, $this->department($company, feedback: true));

        $this->deliver($ticket->id);

        Mail::assertSent(TicketFeedbackMail::class, fn (TicketFeedbackMail $m) => $m->hasTo('c@e.gr')
            && str_contains($m->url, '/support/feedback/'.$ticket->id));
    }

    public function test_no_invite_when_feedback_is_off_or_ticket_open_or_already_rated(): void
    {
        Mail::fake();
        $company = $this->company();

        // feedback off
        $noFeedback = $this->ticket($company, $this->department($company, feedback: false));
        $this->deliver($noFeedback->id);

        // still open
        $open = $this->ticket($company, $this->department($company, feedback: true), status: 'open');
        $this->deliver($open->id);

        // already rated
        $rated = $this->ticket($company, $this->department($company, feedback: true));
        $rated->recordRating(4);
        $this->deliver($rated->id);

        Mail::assertNothingSent();
    }
}
