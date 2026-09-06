<?php

namespace Tests\Feature\Support;

use App\Actions\Support\OpenTicket;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\CannedReplyExpander;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Πυλώνας E — canned-reply token expansion: known tokens resolve from the ticket
 * context, an UNKNOWN token is left verbatim (a typo stays visible).
 */
class CannedReplyExpanderTest extends TestCase
{
    use RefreshDatabase;

    public function test_known_tokens_resolve_and_unknown_is_left_verbatim(): void
    {
        $company = Company::create([
            'name' => 'ACME OE', 'slug' => 'acme-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'phone' => '2101234567', 'email' => 'info@acme.gr',
        ]);
        BankAccount::create(['company_id' => $company->id, 'bank_name' => 'Τράπεζα Α', 'iban' => 'GR0000000001']);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελ ΑΕ', 'afm' => '090000000']);
        $operator = User::create(['name' => 'Οπερατόρ', 'email' => 'o-'.uniqid().'@t.local', 'password' => bcrypt('x')]);

        $ticket = app(OpenTicket::class)->handle([
            'company_id' => $company->id, 'customer_id' => $customer->id,
            'subject' => 'Δοκιμή', 'body' => 'σώμα', 'author_role' => TicketMessage::ROLE_CUSTOMER,
        ]);

        $out = CannedReplyExpander::expand(
            'Γεια {{customer.name}} (ΑΦΜ {{customer.afm}}), αίτημα {{ticket.reference}}. '
            .'IBAN:\n{{company.ibans}}. {{unknown.token}} — {{operator.name}}, {{company.name}}',
            $ticket,
            $operator,
        );

        $this->assertStringContainsString('Γεια Πελ ΑΕ (ΑΦΜ 090000000)', $out);
        $this->assertStringContainsString($ticket->reference, $out);
        $this->assertStringContainsString('Τράπεζα Α: GR0000000001', $out);
        $this->assertStringContainsString('Οπερατόρ', $out);
        $this->assertStringContainsString('ACME OE', $out);
        $this->assertStringContainsString('{{unknown.token}}', $out, 'unknown token stays verbatim');
    }

    public function test_guest_ticket_falls_back_to_requester_name(): void
    {
        $company = Company::create([
            'name' => 'B OE', 'slug' => 'b-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $ticket = app(OpenTicket::class)->handle([
            'company_id' => $company->id, 'requester_name' => 'Ξένος Πελ',
            'subject' => 'X', 'body' => 'y', 'author_role' => TicketMessage::ROLE_CUSTOMER,
        ]);

        $out = CannedReplyExpander::expand('Γεια {{customer.name}}', $ticket);
        $this->assertSame('Γεια Ξένος Πελ', $out);
    }
}
