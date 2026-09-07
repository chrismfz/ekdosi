<?php

namespace Tests\Feature\Support;

use App\Actions\Support\OpenTicket;
use App\Actions\Support\PostTicketMessage;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerUser;
use App\Models\CustomerUserAccess;
use App\Models\Scopes\CompanyScope;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\TicketAttachments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Πυλώνας E, Phase 4 follow-up — ticket attachments (PR A: portal + operator).
 * SECURITY-critical: downloads are grant/tenant scoped, an internal-note file is
 * never reachable from the portal, and only allowlisted types upload.
 */
class TicketAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function company(): Company
    {
        return Company::create([
            'name' => 'T', 'slug' => 'att-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'support_enabled' => true,
        ]);
    }

    private function department(Company $company): TicketDepartment
    {
        return TicketDepartment::create(['company_id' => $company->id, 'name' => 'Γ '.uniqid(), 'is_active' => true]);
    }

    private function login(): CustomerUser
    {
        return CustomerUser::factory()->create(['password' => Hash::make('x'), 'status' => CustomerUser::STATUS_ACTIVE]);
    }

    private function grant(CustomerUser $login, Customer $customer): void
    {
        CustomerUserAccess::create([
            'customer_user_id' => $login->id, 'company_id' => $customer->company_id,
            'customer_id' => $customer->id, 'role' => CustomerUserAccess::ROLE_OWNER, 'granted_at' => now(),
        ]);
    }

    private function ticket(Company $company, Customer $customer): Ticket
    {
        return app(OpenTicket::class)->handle([
            'company_id' => $company->id, 'customer_id' => $customer->id,
            'ticket_department_id' => $this->department($company)->id,
            'subject' => 'Θέμα', 'body' => 'x', 'author_role' => TicketMessage::ROLE_CUSTOMER, 'via' => TicketMessage::VIA_PORTAL,
        ]);
    }

    public function test_customer_opens_a_ticket_with_an_attachment(): void
    {
        $company = $this->company();
        $dept = $this->department($company);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'p@e.gr']);
        $login = $this->login();
        $this->grant($login, $customer);

        $this->actingAs($login, 'portal')->post('/user/tickets', [
            'target' => $customer->id.'_'.$dept->id, 'subject' => 'Με αρχείο', 'priority' => 'normal', 'body' => 'δες το',
            'attachments' => [UploadedFile::fake()->create('doc.pdf', 40, 'application/pdf')],
        ])->assertRedirect();

        $ticket = Ticket::withoutGlobalScope(CompanyScope::class)->latest('id')->first();
        $att = $ticket->messages()->first()->attachments()->first();
        $this->assertNotNull($att);
        $this->assertSame('doc.pdf', $att->original_name);
        Storage::disk('local')->assertExists($att->path);
    }

    public function test_a_disallowed_type_is_rejected(): void
    {
        $company = $this->company();
        $dept = $this->department($company);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'p@e.gr']);
        $login = $this->login();
        $this->grant($login, $customer);

        $this->actingAs($login, 'portal')->post('/user/tickets', [
            'target' => $customer->id.'_'.$dept->id, 'subject' => 'Κακό', 'priority' => 'normal', 'body' => 'x',
            'attachments' => [UploadedFile::fake()->create('evil.php', 10, 'application/x-php')],
        ])->assertSessionHasErrors('attachments.0');

        $this->assertSame(0, Attachment::query()->count(), 'no attachment stored for a rejected type');
    }

    public function test_owner_downloads_their_attachment_but_a_stranger_gets_404(): void
    {
        $company = $this->company();
        $mine = Customer::create(['company_id' => $company->id, 'name' => 'Δικός', 'email' => 'm@e.gr']);
        $stranger = Customer::create(['company_id' => $company->id, 'name' => 'Ξένος', 'email' => 'x@e.gr']);
        $login = $this->login();
        $this->grant($login, $mine); // only $mine

        $ticket = $this->ticket($company, $mine);
        $att = TicketAttachments::storeUploaded($ticket->messages()->first(), [UploadedFile::fake()->create('f.pdf', 10, 'application/pdf')])[0];

        $this->actingAs($login, 'portal')
            ->get(route('portal.tickets.attachment', ['ticket' => $ticket->id, 'attachment' => $att->id]))
            ->assertOk()->assertDownload('f.pdf');

        // A login without a grant to this ticket → fail-closed 404. Flush the
        // session first: the previous request seeded the portal password-hash, and
        // reusing it for a different login would trip the password-change logout
        // (a same-session artifact — real requests never share a session).
        $other = $this->login();
        $this->grant($other, $stranger);
        $this->flushSession();
        $this->actingAs($other, 'portal')
            ->get(route('portal.tickets.attachment', ['ticket' => $ticket->id, 'attachment' => $att->id]))
            ->assertNotFound();
    }

    public function test_an_internal_note_attachment_is_not_downloadable_from_the_portal(): void
    {
        $company = $this->company();
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'p@e.gr']);
        $login = $this->login();
        $this->grant($login, $customer);
        $ticket = $this->ticket($company, $customer);

        // an operator internal note with an attachment
        $note = app(PostTicketMessage::class)->handle($ticket, [
            'author_role' => TicketMessage::ROLE_OPERATOR, 'is_internal_note' => true, 'body' => 'κρυφό',
        ]);
        $att = TicketAttachments::storeUploaded($note, [UploadedFile::fake()->create('secret.pdf', 10, 'application/pdf')])[0];

        $this->actingAs($login, 'portal')
            ->get(route('portal.tickets.attachment', ['ticket' => $ticket->id, 'attachment' => $att->id]))
            ->assertNotFound();
    }

    public function test_operator_downloads_only_within_their_tenant(): void
    {
        $company = $this->company();
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'p@e.gr']);
        $ticket = $this->ticket($company, $customer);
        $att = TicketAttachments::storeUploaded($ticket->messages()->first(), [UploadedFile::fake()->create('op.pdf', 10, 'application/pdf')])[0];

        $member = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $company->users()->attach($member->id);
        $outsider = User::create(['name' => 'Out', 'email' => 'out-'.uniqid().'@t.local', 'password' => bcrypt('x')]);

        $url = route('support.tickets.attachment', ['ticket' => $ticket->id, 'attachment' => $att->id]);
        $this->actingAs($member)->get($url)->assertOk()->assertDownload('op.pdf');
        $this->actingAs($outsider)->get($url)->assertForbidden();
    }
}
