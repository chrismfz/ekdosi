<?php

namespace Tests\Feature\Filament;

use App\Actions\Support\OpenTicket;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Models\TicketBlockedSender;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Πυλώνας E, Phase 4 — «Αποκλεισμός αποστολέα» from a ticket adds the requester's
 * address to the tenant's blocklist (normalised, company-scoped, idempotent).
 */
class TicketBlockSenderActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_blocking_the_sender_adds_a_tenant_scoped_row(): void
    {
        Gate::before(fn () => true);
        $company = Company::create([
            'name' => 'A', 'slug' => 'a-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'support_enabled' => true,
        ]);
        $operator = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $company->users()->attach($operator->id);
        $dept = TicketDepartment::create(['company_id' => $company->id, 'name' => 'Γ', 'is_active' => true]);

        $ticket = app(OpenTicket::class)->handle([
            'company_id' => $company->id, 'ticket_department_id' => $dept->id,
            'requester_email' => 'Spammer@Bad.GR', 'subject' => 'X', 'body' => 'y',
            'author_role' => TicketMessage::ROLE_CUSTOMER, 'via' => TicketMessage::VIA_EMAIL,
        ]);

        $this->actingAs($operator);
        Filament::setTenant($company);

        Livewire::test(ViewTicket::class, ['record' => $ticket->id, 'tenant' => $company->slug])
            ->callAction('blockSender');

        $row = TicketBlockedSender::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('spammer@bad.gr', $row->pattern, 'stored normalised');
        $this->assertSame($operator->id, $row->created_by);
    }
}
