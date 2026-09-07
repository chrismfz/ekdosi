<?php

namespace Tests\Feature\Filament;

use App\Actions\Support\MergeTickets;
use App\Actions\Support\OpenTicket;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Πυλώνας E, Phase 4 — a merged ticket is terminal on the operator side too: no
 * mutation actions (a reply would reopen it into limbo, invisible to the customer),
 * and it is kept out of the operator list.
 */
class MergedTicketGuardTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private function boot(): void
    {
        Gate::before(fn () => true);
        $this->company = Company::create([
            'name' => 'A', 'slug' => 'mg-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'support_enabled' => true,
        ]);
        $op = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $this->company->users()->attach($op->id);
        $this->actingAs($op);
        Filament::setTenant($this->company);
    }

    /** @return array{0: Ticket, 1: Ticket} [source(merged), target] */
    private function mergedPair(): array
    {
        $dept = TicketDepartment::create(['company_id' => $this->company->id, 'name' => 'Γ', 'is_active' => true]);
        $customer = Customer::create(['company_id' => $this->company->id, 'name' => 'Πελ', 'email' => 'c@e.gr']);

        $make = fn (string $s): Ticket => app(OpenTicket::class)->handle([
            'company_id' => $this->company->id, 'customer_id' => $customer->id, 'ticket_department_id' => $dept->id,
            'requester_email' => 'c@e.gr', 'subject' => $s, 'body' => 'x',
            'author_role' => TicketMessage::ROLE_CUSTOMER, 'via' => TicketMessage::VIA_EMAIL,
        ]);

        $target = $make('Κύριο');
        $source = $make('Διπλό');
        app(MergeTickets::class)->handle($source, $target);

        return [$source->fresh(), $target->fresh()];
    }

    public function test_a_merged_source_hides_every_mutation_action(): void
    {
        $this->boot();
        [$source] = $this->mergedPair();

        Livewire::test(ViewTicket::class, ['record' => $source->id, 'tenant' => $this->company->slug])
            ->assertActionHidden('reply')
            ->assertActionHidden('note')
            ->assertActionHidden('assign')
            ->assertActionHidden('addWatcher')
            ->assertActionHidden('merge')
            ->assertActionHidden('reopen');
    }

    public function test_the_survivor_keeps_its_actions(): void
    {
        $this->boot();
        [, $target] = $this->mergedPair();

        Livewire::test(ViewTicket::class, ['record' => $target->id, 'tenant' => $this->company->slug])
            ->assertActionVisible('reply');
    }

    public function test_the_operator_list_excludes_merged_sources(): void
    {
        $this->boot();
        [$source, $target] = $this->mergedPair();

        Livewire::test(ListTickets::class, ['tenant' => $this->company->slug])
            ->assertCanSeeTableRecords([$target])
            ->assertCanNotSeeTableRecords([$source]);
    }
}
