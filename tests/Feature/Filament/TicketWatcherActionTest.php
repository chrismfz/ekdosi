<?php

namespace Tests\Feature\Filament;

use App\Actions\Support\OpenTicket;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Models\TicketWatcher;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Πυλώνας E, Phase 4 — the «Προσθήκη watcher» action must resolve an operator
 * WITHIN the ticket's tenant. A tampered submit carrying a foreign user_id must
 * NOT attach that user (which would leak the ticket's subject via the bell).
 */
class TicketWatcherActionTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $slug): Company
    {
        return Company::create([
            'name' => $slug, 'slug' => $slug.'-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'support_enabled' => true,
        ]);
    }

    private function user(Company $company, string $name): User
    {
        $u = User::create(['name' => $name, 'email' => strtolower($name).'-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $company->users()->attach($u->id);

        return $u;
    }

    private function ticket(Company $company): Ticket
    {
        $dept = TicketDepartment::create(['company_id' => $company->id, 'name' => 'Γ '.uniqid(), 'is_active' => true]);

        return app(OpenTicket::class)->handle([
            'company_id' => $company->id, 'ticket_department_id' => $dept->id,
            'requester_email' => 'c@e.gr', 'subject' => 'X', 'body' => 'y',
            'author_role' => TicketMessage::ROLE_CUSTOMER,
        ]);
    }

    public function test_a_foreign_operator_is_not_attached_as_a_watcher(): void
    {
        Gate::before(fn () => true);
        $companyA = $this->company('a');
        $companyB = $this->company('b');
        $operatorA = $this->user($companyA, 'OpA');
        $foreign = $this->user($companyB, 'OpB'); // belongs to another tenant
        $ticket = $this->ticket($companyA);

        $this->actingAs($operatorA);
        Filament::setTenant($companyA);

        Livewire::test(ViewTicket::class, ['record' => $ticket->id, 'tenant' => $companyA->slug])
            ->callAction('addWatcher', ['user_id' => $foreign->id]);

        $this->assertSame(0, TicketWatcher::withoutGlobalScope(CompanyScope::class)
            ->where('ticket_id', $ticket->id)->where('user_id', $foreign->id)->count(),
            'a user from another tenant must never be attached as a watcher');
    }

    public function test_a_same_tenant_operator_is_attached(): void
    {
        Gate::before(fn () => true);
        $company = $this->company('a');
        $operator = $this->user($company, 'OpA');
        $other = $this->user($company, 'OpB');
        $ticket = $this->ticket($company);

        $this->actingAs($operator);
        Filament::setTenant($company);

        Livewire::test(ViewTicket::class, ['record' => $ticket->id, 'tenant' => $company->slug])
            ->callAction('addWatcher', ['user_id' => $other->id]);

        $this->assertTrue($ticket->fresh()->isWatchedBy($other));
    }

    public function test_an_operator_can_remove_a_cc_watcher(): void
    {
        Gate::before(fn () => true);
        $company = $this->company('a');
        $operator = $this->user($company, 'OpA');
        $ticket = $this->ticket($company);
        // A third party the customer once CC'd — captured as a CC watcher.
        $watcher = $ticket->addEmailWatcher('third-party@agency.tld', TicketWatcher::SOURCE_CC);

        $this->actingAs($operator);
        Filament::setTenant($company);

        Livewire::test(ViewTicket::class, ['record' => $ticket->id, 'tenant' => $company->slug])
            ->callAction('removeWatcher', ['watcher_id' => $watcher->id]);

        $this->assertSame(0, TicketWatcher::withoutGlobalScope(CompanyScope::class)
            ->where('ticket_id', $ticket->id)->count(), 'the CC watcher is removed → no more disclosure on replies');
    }

    public function test_remove_watcher_is_scoped_to_this_ticket(): void
    {
        Gate::before(fn () => true);
        $company = $this->company('a');
        $operator = $this->user($company, 'OpA');
        $ticket = $this->ticket($company);
        $ticket->addEmailWatcher('mine@agency.tld', TicketWatcher::SOURCE_CC); // so the action is visible
        $other = $this->ticket($company);
        // A watcher on ANOTHER ticket must not be deletable via this ticket's action.
        $foreignWatcher = $other->addEmailWatcher('keep-me@agency.tld', TicketWatcher::SOURCE_CC);

        $this->actingAs($operator);
        Filament::setTenant($company);

        Livewire::test(ViewTicket::class, ['record' => $ticket->id, 'tenant' => $company->slug])
            ->callAction('removeWatcher', ['watcher_id' => $foreignWatcher->id]);

        $this->assertSame(1, TicketWatcher::withoutGlobalScope(CompanyScope::class)
            ->where('ticket_id', $other->id)->count(), "another ticket's watcher is untouched");
    }
}
