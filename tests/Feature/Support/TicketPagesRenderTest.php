<?php

namespace Tests\Feature\Support;

use App\Actions\Support\OpenTicket;
use App\Models\Company;
use App\Models\Customer;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Πυλώνας E — the operator ticket pages actually render (list + view) with a real
 * ticket present. Guards the Filament table/infolist wiring, not just canAccess.
 */
class TicketPagesRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_and_view_pages_render(): void
    {
        Gate::before(fn () => true);

        $company = Company::create([
            'name' => 'Sup', 'slug' => 'sup-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'support_enabled' => true,
        ]);
        $user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($company->id);
        $this->actingAs($user);

        $cust = Customer::create(['company_id' => $company->id, 'name' => 'Πελ', 'email' => 'p@e.gr']);
        $ticket = app(OpenTicket::class)->handle([
            'company_id' => $company->id, 'customer_id' => $cust->id, 'subject' => 'Θέμα',
            'body' => 'Σώμα', 'author_role' => TicketMessage::ROLE_CUSTOMER,
        ]);

        $base = "/admin/{$company->slug}/support/tickets";
        $this->get($base)->assertOk();
        $this->get("{$base}/{$ticket->getKey()}")->assertOk();
    }
}
