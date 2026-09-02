<?php

namespace Tests\Feature\Leads;

use App\Actions\ConvertLeadToCustomer;
use App\Enums\LeadActivityType;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Quote;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Leads L1: converting a lead creates (or links) a customer, keeps the link in
 * both directions, carries tags / contact / quotes, writes Won exactly once,
 * and refuses to run twice or across tenants.
 */
class ConvertLeadToCustomerTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $slug = 'cv'): Company
    {
        return Company::create([
            'name' => 'T '.$slug, 'slug' => $slug.'-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    public function test_new_customer_is_built_from_the_lead_and_linked_both_ways(): void
    {
        $t = $this->tenant();
        $user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.l', 'password' => bcrypt('x')]);
        $this->actingAs($user);

        $referrer = Customer::create(['company_id' => $t->id, 'name' => 'Ο Συστήσας']);
        $tag = Tag::create(['company_id' => $t->id, 'name' => 'hosting']);

        $lead = Lead::create([
            'company_id' => $t->id, 'name' => 'Καφενείο Ο Νίκος', 'contact_person' => 'Νίκος Π.',
            'phone' => '2310123456', 'mobile' => '6971234567', 'email' => 'nikos@kafeneio.gr',
            'afm' => '123456789', 'address1' => 'Εγνατία 1', 'city' => 'Θεσσαλονίκη', 'postcode' => '54624',
            'occupation' => 'Εστίαση', 'source' => LeadSource::Referral, 'referred_by_customer_id' => $referrer->id,
            'status' => LeadStatus::Interested, 'next_action_at' => now()->addDay(),
        ]);
        $lead->tags()->attach($tag);
        $quote = Quote::create(['company_id' => $t->id, 'lead_id' => $lead->id, 'code' => 'ΠΡ-1', 'status' => 'sent']);

        $customer = app(ConvertLeadToCustomer::class)($lead);

        // Customer fields copied 1:1.
        $this->assertSame($t->id, $customer->company_id);
        $this->assertSame('Καφενείο Ο Νίκος', $customer->name);
        $this->assertSame('123456789', $customer->afm);
        $this->assertSame('Εγνατία 1', $customer->address1);
        $this->assertSame('Θεσσαλονίκη', $customer->city);
        $this->assertSame('GR', $customer->country);
        $this->assertSame('2310123456', $customer->phone1);
        $this->assertSame('6971234567', $customer->phone2);
        $this->assertSame('nikos@kafeneio.gr', $customer->email);
        $this->assertSame($referrer->id, $customer->referred_by_customer_id);
        $this->assertTrue($customer->is_active);

        // Tags + primary contact travelled.
        $this->assertSame(['hosting'], $customer->tags()->pluck('name')->all());
        $contact = $customer->contacts()->first();
        $this->assertSame('Νίκος Π.', $contact->name);
        $this->assertTrue($contact->is_primary);
        $this->assertSame('6971234567', $contact->phone);

        // Link both ways, Won stamped, next action cleared.
        $lead->refresh();
        $this->assertSame(LeadStatus::Won, $lead->status);
        $this->assertSame($customer->id, $lead->converted_customer_id);
        $this->assertNotNull($lead->converted_at);
        $this->assertNull($lead->next_action_at);
        $this->assertTrue($lead->isConverted());
        $this->assertSame($lead->id, $customer->originLead->id);

        // Quotes re-pointed.
        $this->assertSame($customer->id, $quote->fresh()->customer_id);

        // Timeline: the status hop + the converted row naming the customer.
        $types = $lead->timeline()->pluck('type')->map(fn ($t) => $t->value)->all();
        $this->assertContains(LeadActivityType::Converted->value, $types);
        $this->assertContains(LeadActivityType::StatusChange->value, $types);
        $converted = $lead->timeline()->where('type', LeadActivityType::Converted->value)->first();
        $this->assertSame($customer->id, $converted->meta['customer_id']);
        $this->assertFalse($converted->meta['linked_existing']);
        $this->assertSame($user->id, $converted->user_id);
    }

    public function test_link_to_existing_customer_creates_nothing(): void
    {
        $t = $this->tenant();
        $existing = Customer::create(['company_id' => $t->id, 'name' => 'Ήδη Πελάτης', 'afm' => '123456789']);
        $lead = Lead::create(['company_id' => $t->id, 'name' => 'Ήδη Πελάτης (lead)', 'afm' => '123456789']);

        $customer = app(ConvertLeadToCustomer::class)($lead, $existing);

        $this->assertSame($existing->id, $customer->id);
        $this->assertSame(1, Customer::where('company_id', $t->id)->count(), 'No duplicate customer.');
        $this->assertSame(0, $existing->contacts()->count());
        $this->assertSame($existing->id, $lead->fresh()->converted_customer_id);
        $this->assertTrue($lead->timeline()->where('type', LeadActivityType::Converted->value)->first()->meta['linked_existing']);
    }

    public function test_writes_through_the_locked_row_not_a_stale_instance(): void
    {
        $t = $this->tenant();
        $stale = Lead::create(['company_id' => $t->id, 'name' => 'Α', 'status' => LeadStatus::Contacted]);

        // Another operator moved it on meanwhile.
        Lead::query()->whereKey($stale->id)->update(['status' => LeadStatus::Quoted->value]);

        app(ConvertLeadToCustomer::class)($stale);

        $row = LeadActivity::query()->where('lead_id', $stale->id)->where('type', LeadActivityType::StatusChange->value)->first();
        $this->assertSame(['from' => 'quoted', 'to' => 'won'], $row->meta, 'The transition is logged from the DB truth, not the stale instance.');
    }

    public function test_refuses_a_new_customer_when_a_live_one_owns_the_afm(): void
    {
        $t = $this->tenant();
        $owner = Customer::create(['company_id' => $t->id, 'name' => 'Κάτοχος', 'afm' => 'EL 123456789']);
        $lead = Lead::create(['company_id' => $t->id, 'name' => 'Διπλός', 'afm' => '123456789']);

        try {
            app(ConvertLeadToCustomer::class)($lead);
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Σύνδεση', $e->getMessage());
        }

        $this->assertSame(1, Customer::withTrashed()->where('company_id', $t->id)->count(), 'No second legal party.');
        $this->assertNull($lead->fresh()->converted_customer_id);

        // Linking to the owner is the way through.
        $this->assertSame($owner->id, app(ConvertLeadToCustomer::class)($lead->fresh(), $owner)->id);

        // A TRASHED owner blocks too (it could be restored → two live parties);
        // the message says restore + link.
        $t2 = $this->tenant('t2');
        Customer::create(['company_id' => $t2->id, 'name' => 'Σβησμένος', 'afm' => '123456789'])->delete();
        $lead2 = Lead::create(['company_id' => $t2->id, 'name' => 'Νέος', 'afm' => '123456789']);
        try {
            app(ConvertLeadToCustomer::class)($lead2);
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ΔΙΑΓΡΑΜΜΕΝΟΣ', $e->getMessage());
        }
        $this->assertSame(0, Customer::where('company_id', $t2->id)->count());
    }

    public function test_refuses_a_do_not_contact_or_trashed_lead(): void
    {
        $t = $this->tenant();

        $dnc = Lead::create(['company_id' => $t->id, 'name' => 'DNC', 'email' => 'no@thanks.gr', 'status' => LeadStatus::DoNotContact, 'lost_reason' => 'x']);
        try {
            app(ConvertLeadToCustomer::class)($dnc);
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Μην ξαναενοχλήσετε', $e->getMessage());
        }
        $this->assertSame(LeadStatus::DoNotContact, $dnc->fresh()->status, 'The DNC memory survives.');
        $this->assertSame(0, Customer::where('company_id', $t->id)->count());

        $trashed = Lead::create(['company_id' => $t->id, 'name' => 'Σβησμένο']);
        $trashed->delete();
        $this->expectException(RuntimeException::class);
        app(ConvertLeadToCustomer::class)($trashed);
    }

    public function test_refuses_a_second_conversion(): void
    {
        $t = $this->tenant();
        $lead = Lead::create(['company_id' => $t->id, 'name' => 'Α']);
        app(ConvertLeadToCustomer::class)($lead);

        $this->expectException(RuntimeException::class);
        app(ConvertLeadToCustomer::class)($lead->fresh());
    }

    public function test_refuses_a_customer_already_owned_by_another_lead(): void
    {
        $t = $this->tenant();
        $first = Lead::create(['company_id' => $t->id, 'name' => 'Πρώτο']);
        $customer = app(ConvertLeadToCustomer::class)($first);
        $second = Lead::create(['company_id' => $t->id, 'name' => 'Δεύτερο']);

        try {
            app(ConvertLeadToCustomer::class)($second, $customer);
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException) {
        }

        $this->assertNull($second->fresh()->converted_customer_id);
        $this->assertSame(LeadStatus::New, $second->fresh()->status, 'Nothing was written for the refused lead.');
    }

    public function test_refuses_a_customer_of_another_tenant(): void
    {
        $t = $this->tenant('a');
        $other = $this->tenant('b');
        $foreign = Customer::create(['company_id' => $other->id, 'name' => 'Ξένος']);
        $lead = Lead::create(['company_id' => $t->id, 'name' => 'Α']);

        $this->expectException(RuntimeException::class);
        app(ConvertLeadToCustomer::class)($lead, $foreign);
    }
}
