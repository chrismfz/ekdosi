<?php

namespace Tests\Feature\Leads;

use App\Actions\ConvertLeadToCustomer;
use App\Enums\LeadActivityType;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\QuoteStatus;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Leads\RelationManagers\QuotesRelationManager;
use App\Filament\Resources\Leads\RelationManagers\TimelineRelationManager;
use App\Filament\Resources\Quotes\Pages\CreateQuote;
use App\Filament\Resources\Quotes\Pages\EditQuote;
use App\Filament\Resources\Quotes\Pages\ViewQuote;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\User;
use App\Models\VatCategory;
use App\Services\TenantRoleProvisioner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Filament surface: list + create + edit render, creating a lead through
 * the form stamps the tenant, the «Αλλαγή κατάστασης» action goes through the
 * status hook, and the timeline quick-add logs a typed row (+ the New →
 * Contacted convenience + next_action_at hand-off).
 */
class LeadResourceTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private User $user;

    /** Flip to simulate a role that may edit leads but NOT create customers. */
    private bool $denyCustomerCreate = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Lead Co', 'slug' => 'lr-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.l', 'password' => bcrypt('x')]);
        $this->tenant->users()->attach($this->user);

        // Allow-all, except the one ability a test may deliberately deny.
        Gate::before(fn ($user, string $ability, array $args = []): bool => ! (
            $this->denyCustomerCreate && $ability === 'create' && ($args[0] ?? null) === Customer::class
        ));
        $this->actingAs($this->user);
        Filament::setTenant($this->tenant);
    }

    public function test_operator_map_includes_leads(): void
    {
        $this->assertSame(['ViewAny', 'View', 'Create', 'Update'], TenantRoleProvisioner::OPERATOR_PERMISSION_MAP['Lead']);
    }

    public function test_list_renders_with_tabs(): void
    {
        Lead::create(['company_id' => $this->tenant->id, 'name' => 'Ανοιχτό lead']);
        Lead::create(['company_id' => $this->tenant->id, 'name' => 'Χαμένο lead', 'status' => LeadStatus::Lost, 'lost_reason' => 'x']);

        Livewire::test(ListLeads::class)
            ->assertOk()
            ->assertSee('Ανοιχτά')
            ->assertSee('Ληξιπρόθεσμα')
            ->assertSee('Ανοιχτό lead')
            ->assertDontSee('Χαμένο lead');
    }

    public function test_create_form_creates_a_lead_with_only_a_name(): void
    {
        Livewire::test(CreateLead::class)
            ->assertOk()
            ->fillForm(['name' => 'Καφενείο Ο Νίκος'])
            ->call('create')
            ->assertHasNoFormErrors();

        $lead = Lead::query()->where('name', 'Καφενείο Ο Νίκος')->first();
        $this->assertNotNull($lead);
        $this->assertSame($this->tenant->id, $lead->company_id);
        $this->assertSame(LeadStatus::New, $lead->status);
        $this->assertSame($this->user->id, $lead->assigned_user_id, 'Defaults to the current user.');
    }

    public function test_create_form_requires_a_reason_for_lost(): void
    {
        Livewire::test(CreateLead::class)
            ->fillForm(['name' => 'Χ', 'status' => LeadStatus::Lost->value, 'lost_reason' => ''])
            ->call('create')
            ->assertHasFormErrors(['lost_reason']);
    }

    public function test_edit_shows_dedupe_banner_for_an_existing_customer(): void
    {
        Customer::create(['company_id' => $this->tenant->id, 'name' => 'Ήδη Πελάτης ΑΕ', 'afm' => '123456789']);
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Νέο', 'afm' => '123456789']);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->assertOk()
            ->assertSee('Είναι ήδη πελάτης')
            ->assertSee('Ήδη Πελάτης ΑΕ');
    }

    public function test_change_status_action_records_the_transition(): void
    {
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Α', 'status' => LeadStatus::Contacted]);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->callAction('changeStatus', data: ['status' => LeadStatus::Lost->value, 'reason' => 'πολύ ακριβό'])
            ->assertHasNoActionErrors();

        $lead->refresh();
        $this->assertSame(LeadStatus::Lost, $lead->status);
        $this->assertSame('πολύ ακριβό', $lead->lost_reason);

        $row = $lead->timeline()->first();
        $this->assertSame(LeadActivityType::StatusChange, $row->type);
        $this->assertSame(['from' => 'contacted', 'to' => 'lost'], $row->meta);
        $this->assertSame('πολύ ακριβό', $row->body);
    }

    public function test_change_status_action_requires_a_reason_for_do_not_contact(): void
    {
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Α']);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->callAction('changeStatus', data: ['status' => LeadStatus::DoNotContact->value, 'reason' => '', 'confirm_dnc' => true])
            ->assertHasActionErrors(['reason']);

        $this->assertSame(LeadStatus::New, $lead->fresh()->status);
    }

    public function test_do_not_contact_needs_an_explicit_confirmation_tick(): void
    {
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Α']);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->callAction('changeStatus', data: ['status' => LeadStatus::DoNotContact->value, 'reason' => 'το ζήτησε', 'confirm_dnc' => false])
            ->assertHasActionErrors(['confirm_dnc']);
        $this->assertSame(LeadStatus::New, $lead->fresh()->status, 'Refused without the tick.');

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->callAction('changeStatus', data: ['status' => LeadStatus::DoNotContact->value, 'reason' => 'το ζήτησε', 'confirm_dnc' => true])
            ->assertHasNoActionErrors();
        $this->assertSame(LeadStatus::DoNotContact, $lead->fresh()->status);
    }

    public function test_do_not_contact_cannot_be_set_from_the_plain_form(): void
    {
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Α']);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->fillForm(['status' => LeadStatus::DoNotContact->value, 'lost_reason' => 'x'])
            ->call('save')
            ->assertHasFormErrors(['status']);

        $this->assertSame(LeadStatus::New, $lead->fresh()->status);
    }

    public function test_do_not_contact_record_cannot_be_saved_as_won_from_the_form(): void
    {
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Α', 'status' => LeadStatus::DoNotContact, 'lost_reason' => 'x']);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->assertOk()
            ->assertSee('Μην ξαναενοχλήσετε')
            ->fillForm(['status' => LeadStatus::Won->value])
            ->call('save')
            ->assertHasFormErrors(['status']);

        $this->assertSame(LeadStatus::DoNotContact, $lead->fresh()->status);
    }

    public function test_convert_requires_the_customer_create_permission(): void
    {
        // Update:Lead but NOT Create:Customer → the action is not offered.
        $this->denyCustomerCreate = true;

        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Α']);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->assertActionHidden('convert');

        $this->assertNull($lead->fresh()->converted_customer_id);
        $this->assertSame(0, Customer::where('company_id', $this->tenant->id)->count());
    }

    public function test_send_email_is_offered_for_a_leads_quote(): void
    {
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Lead', 'email' => 'lead@x.gr']);
        $quote = Quote::create(['company_id' => $this->tenant->id, 'lead_id' => $lead->id, 'code' => 'ΠΡ-3', 'issued_at' => now()]);

        Livewire::test(ViewQuote::class, ['record' => $quote->getRouteKey()])
            ->assertActionVisible('send_email');

        $noEmail = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Χωρίς email']);
        $quote2 = Quote::create(['company_id' => $this->tenant->id, 'lead_id' => $noEmail->id, 'code' => 'ΠΡ-4', 'issued_at' => now()]);
        Livewire::test(ViewQuote::class, ['record' => $quote2->getRouteKey()])
            ->assertActionHidden('send_email');
    }

    public function test_convert_is_hidden_on_a_do_not_contact_lead(): void
    {
        $dnc = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Α', 'status' => LeadStatus::DoNotContact, 'lost_reason' => 'x']);
        $lost = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Β', 'status' => LeadStatus::Lost, 'lost_reason' => 'x']);

        Livewire::test(EditLead::class, ['record' => $dnc->getRouteKey()])->assertActionHidden('convert');
        // A lost lead that comes back IS convertible.
        Livewire::test(EditLead::class, ['record' => $lost->getRouteKey()])->assertActionVisible('convert');
    }

    public function test_editing_a_quote_after_the_lead_closed_keeps_the_link(): void
    {
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Καφενείο']);
        VatCategory::create(['company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
        $quote = Quote::create(['company_id' => $this->tenant->id, 'lead_id' => $lead->id, 'code' => 'ΠΡ-9', 'subject' => 'Πριν', 'issued_at' => now()]);

        app(ConvertLeadToCustomer::class)($lead); // lead → Won (not open any more)

        Livewire::test(EditQuote::class, ['record' => $quote->getRouteKey()])
            ->fillForm(['subject' => 'Μετά'])
            ->call('save')
            ->assertHasNoFormErrors();

        $quote->refresh();
        $this->assertSame('Μετά', $quote->subject);
        $this->assertSame($lead->id, $quote->lead_id, 'The historical lead link survives an edit.');
    }

    public function test_new_quote_is_hidden_on_lost_and_do_not_contact_leads(): void
    {
        $dnc = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Α', 'status' => LeadStatus::DoNotContact, 'lost_reason' => 'x']);
        $open = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Β', 'status' => LeadStatus::Interested]);

        Livewire::test(EditLead::class, ['record' => $dnc->getRouteKey()])->assertActionHidden('newQuote');
        Livewire::test(EditLead::class, ['record' => $open->getRouteKey()])->assertActionVisible('newQuote');
    }

    public function test_convert_default_ignores_trashed_and_contact_only_hits_and_prefers_afm(): void
    {
        // Trashed AFM twin → no default (must not pre-select a deleted customer).
        $trashed = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Σβησμένος', 'afm' => '123456789']);
        $trashed->delete();
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Α', 'afm' => '123456789', 'phone' => '6970001111']);

        // «Άλφα» only shares a CONTACT phone; «Βήτα» owns the ΑΦΜ → Βήτα, not Άλφα.
        $alpha = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Άλφα']);
        CustomerContact::create(['company_id' => $this->tenant->id, 'customer_id' => $alpha->id, 'name' => 'Λογιστής', 'phone' => '697 000 1111']);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->mountAction('convert')
            ->assertActionDataSet(['mode' => 'new', 'customer_id' => null]);

        // No manual flush: the matcher memo is invalidated by the Customer write.
        // (The trashed twin must be gone first — one ΑΦΜ per tenant, deleted included.)
        $trashed->forceDelete();
        $beta = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Βήτα', 'afm' => '123456789']);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->mountAction('convert')
            ->assertActionDataSet(['mode' => 'link', 'customer_id' => $beta->id]);
    }

    public function test_auto_source_only_on_a_direct_live_customer_hit(): void
    {
        $alpha = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Άλφα']);
        CustomerContact::create(['company_id' => $this->tenant->id, 'customer_id' => $alpha->id, 'name' => 'Λογιστής', 'phone' => '6970001111']);

        Livewire::test(CreateLead::class)
            ->fillForm(['name' => 'Ίδιος λογιστής', 'phone' => '6970001111'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(Lead::where('name', 'Ίδιος λογιστής')->first()->source, 'A contact-only hit is not an upsell.');
    }

    public function test_not_now_requires_a_follow_up_date(): void
    {
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Α']);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->callAction('changeStatus', data: ['status' => LeadStatus::NotNow->value, 'next_action_at' => null])
            ->assertHasActionErrors(['next_action_at']);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->callAction('changeStatus', data: ['status' => LeadStatus::NotNow->value, 'next_action_at' => now()->addMonth()->format('Y-m-d H:i:s')])
            ->assertHasNoActionErrors();
        $lead->refresh();
        $this->assertSame(LeadStatus::NotNow, $lead->status);
        $this->assertTrue($lead->next_action_at->isSameDay(now()->addMonth()));

        // Same rule on the plain form.
        Livewire::test(CreateLead::class)
            ->fillForm(['name' => 'Β', 'status' => LeadStatus::NotNow->value, 'next_action_at' => null])
            ->call('create')
            ->assertHasFormErrors(['next_action_at']);
    }

    public function test_saving_a_duplicate_of_a_do_not_contact_lead_needs_acknowledgement(): void
    {
        Lead::create(['company_id' => $this->tenant->id, 'name' => 'Ενοχλημένος', 'email' => 'no@thanks.gr', 'status' => LeadStatus::DoNotContact, 'lost_reason' => 'το ζήτησε']);

        Livewire::test(CreateLead::class)
            ->fillForm(['name' => 'Ξανά', 'email' => 'NO@thanks.gr'])
            ->assertSee('ΜΗΝ τους ξαναενοχλήσουμε')
            ->call('create')
            ->assertHasFormErrors(['acknowledge_dnc']);
        $this->assertSame(0, Lead::where('name', 'Ξανά')->count(), 'Refused until acknowledged.');

        Livewire::test(CreateLead::class)
            ->fillForm(['name' => 'Ξανά', 'email' => 'NO@thanks.gr', 'acknowledge_dnc' => true])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame(1, Lead::where('name', 'Ξανά')->count());
    }

    public function test_editing_an_acknowledged_lead_does_not_re_ask_for_the_tick(): void
    {
        Lead::create(['company_id' => $this->tenant->id, 'name' => 'Ενοχλημένος', 'email' => 'no@thanks.gr', 'status' => LeadStatus::DoNotContact, 'lost_reason' => 'το ζήτησε']);
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Ξανά', 'email' => 'no@thanks.gr']);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->assertSee('ΜΗΝ τους ξαναενοχλήσουμε')
            ->fillForm(['phone' => '2310123456'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('2310123456', $lead->fresh()->phone);
    }

    public function test_quote_created_from_a_lead_end_to_end(): void
    {
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Καφενείο', 'status' => LeadStatus::Contacted]);
        // The line's ΦΠΑ % select is fed by the tenant's VAT categories.
        VatCategory::create(['company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);

        Livewire::withQueryParams(['lead' => $lead->id])
            ->test(CreateQuote::class)
            ->fillForm([
                'subject' => 'Hosting',
                'lines' => [
                    ['product_descr' => 'Hosting 1 έτος', 'qty' => 1, 'price_per_item' => 100, 'discount' => 0, 'vat_percent' => '24.00'],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $quote = Quote::query()->where('company_id', $this->tenant->id)->where('lead_id', $lead->id)->first();
        $this->assertNotNull($quote, 'lead_id persisted through the real create path');
        $this->assertSame('Καφενείο', $quote->company_name);

        // Creating the DRAFT is not a contact — nothing moves on the lead yet.
        $lead->refresh();
        $this->assertSame(LeadStatus::Contacted, $lead->status);
        $this->assertSame(0, $lead->timeline()->count());
        $this->assertNull($lead->last_activity_at);

        // «Σήμανση ως απεσταλμένη» is: Sent + the contact on the lead.
        Livewire::test(ViewQuote::class, ['record' => $quote->getRouteKey()])
            ->assertActionVisible('mark_sent')
            ->callAction('mark_sent')
            ->assertHasNoActionErrors();

        $lead->refresh();
        $this->assertSame(QuoteStatus::Sent, $quote->fresh()->status);
        $this->assertSame(LeadStatus::Quoted, $lead->status);
        $row = $lead->timeline()->where('type', LeadActivityType::Quote->value)->first();
        $this->assertSame($quote->id, $row->meta['quote_id']);
    }

    public function test_creating_a_lead_that_matches_a_customer_records_the_source(): void
    {
        Customer::create(['company_id' => $this->tenant->id, 'name' => 'Υπάρχων', 'afm' => '123456789']);

        Livewire::test(CreateLead::class)
            ->fillForm(['name' => 'Upsell', 'afm' => '123456789'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(LeadSource::ExistingCustomer, Lead::where('name', 'Upsell')->first()->source);

        // An explicit choice wins.
        Livewire::test(CreateLead::class)
            ->fillForm(['name' => 'Upsell 2', 'afm' => '123456789', 'source' => LeadSource::Referral->value])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame(LeadSource::Referral, Lead::where('name', 'Upsell 2')->first()->source);
    }

    public function test_timeline_quick_add_logs_a_call_and_advances_a_new_lead(): void
    {
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Α']);

        Livewire::test(TimelineRelationManager::class, [
            'ownerRecord' => $lead,
            'pageClass' => EditLead::class,
        ])
            ->assertOk()
            ->callTableAction('log_call', data: [
                'happened_at' => now()->format('Y-m-d H:i:s'),
                'direction' => 'outbound',
                'outcome' => 'answered',
                'body' => 'Μίλησα με τον κ. Νίκο, θέλει προσφορά για hosting.',
                'next_action_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
            ])
            ->assertHasNoTableActionErrors();

        $lead->refresh();

        $call = $lead->timeline()->where('type', LeadActivityType::Call->value)->first();
        $this->assertNotNull($call);
        $this->assertSame($this->tenant->id, $call->company_id);
        $this->assertSame($this->user->id, $call->user_id);
        $this->assertSame('answered', $call->outcome);
        $this->assertSame('outbound', $call->direction);

        $this->assertSame(LeadStatus::Contacted, $lead->status, 'An answered call moves a New lead to Contacted.');
        $this->assertNotNull($lead->next_action_at);
        $this->assertTrue($lead->next_action_at->isSameDay(now()->addDays(2)));
        $this->assertNotNull($lead->last_activity_at);

        // The status hop itself is on the timeline too.
        $this->assertSame(1, $lead->timeline()->where('type', LeadActivityType::StatusChange->value)->count());
    }

    public function test_missed_call_does_not_advance_the_status(): void
    {
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Α']);

        Livewire::test(TimelineRelationManager::class, [
            'ownerRecord' => $lead,
            'pageClass' => EditLead::class,
        ])
            ->callTableAction('log_call', data: [
                'happened_at' => now()->format('Y-m-d H:i:s'),
                'direction' => 'outbound',
                'outcome' => 'no_answer',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(LeadStatus::New, $lead->fresh()->status);
        $this->assertSame(1, $lead->timeline()->count());
    }

    public function test_bounced_email_does_not_advance_the_status(): void
    {
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Α']);

        Livewire::test(TimelineRelationManager::class, [
            'ownerRecord' => $lead,
            'pageClass' => EditLead::class,
        ])
            ->callTableAction('log_email', data: [
                'happened_at' => now()->format('Y-m-d H:i:s'),
                'direction' => 'outbound',
                'outcome' => 'bounced',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(LeadStatus::New, $lead->fresh()->status, 'Nobody was reached — stays Νέο.');
    }

    public function test_editing_a_call_into_a_note_clears_direction_and_outcome(): void
    {
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Α']);
        $row = $lead->timeline()->create([
            'company_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'type' => LeadActivityType::Call->value, 'direction' => 'outbound', 'outcome' => 'answered',
            'happened_at' => now(), 'body' => 'x',
        ]);

        Livewire::test(TimelineRelationManager::class, [
            'ownerRecord' => $lead,
            'pageClass' => EditLead::class,
        ])
            ->callTableAction('edit', $row, data: ['type' => LeadActivityType::Note->value, 'body' => 'τώρα σημείωση'])
            ->assertHasNoTableActionErrors();

        $row->refresh();
        $this->assertSame(LeadActivityType::Note, $row->type);
        $this->assertNull($row->direction);
        $this->assertNull($row->outcome);
    }

    public function test_won_lead_edit_page_shows_the_status(): void
    {
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Κερδισμένο', 'status' => LeadStatus::Won]);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->assertOk()
            ->assertSee('Πελάτης');
    }

    public function test_convert_action_creates_a_customer_and_redirects_to_it(): void
    {
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Νέος Πελάτης ΑΕ', 'afm' => '123456789']);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->callAction('convert', data: ['mode' => 'new'])
            ->assertHasNoActionErrors()
            ->assertRedirect();

        $customer = Customer::query()->where('company_id', $this->tenant->id)->where('afm', '123456789')->first();
        $this->assertNotNull($customer);
        $this->assertSame($customer->id, $lead->fresh()->converted_customer_id);
        $this->assertSame(LeadStatus::Won, $lead->fresh()->status);
    }

    public function test_convert_action_defaults_to_linking_the_matching_customer(): void
    {
        $existing = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Ήδη Πελάτης', 'afm' => '123456789']);
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Ήδη (lead)', 'afm' => '123456789']);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->mountAction('convert')
            ->assertActionDataSet(['mode' => 'link', 'customer_id' => $existing->id])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(1, Customer::where('company_id', $this->tenant->id)->count(), 'Linked, not duplicated.');
        $this->assertSame($existing->id, $lead->fresh()->converted_customer_id);
    }

    public function test_converted_lead_hides_convert_and_customer_shows_origin_tab(): void
    {
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Κερδισμένο']);
        $customer = app(ConvertLeadToCustomer::class)($lead);

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->assertActionHidden('convert')
            ->assertActionVisible('openCustomer');

        Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
            ->assertOk()
            ->assertSee('Προέλευση')
            ->assertSee('Ήρθε από lead');
    }

    public function test_origin_first_contact_is_the_earliest_real_contact(): void
    {
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Παλιά επαφή']);
        // A note two months ago is not a contact; the call 20 days ago is.
        $lead->timeline()->create(['company_id' => $this->tenant->id, 'type' => LeadActivityType::Note->value, 'happened_at' => now()->subMonths(2), 'body' => 'σημ.']);
        $lead->timeline()->create(['company_id' => $this->tenant->id, 'type' => LeadActivityType::Call->value, 'outcome' => 'answered', 'happened_at' => now()->subDays(20)]);
        $customer = app(ConvertLeadToCustomer::class)($lead);

        Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
            ->assertOk()
            ->assertSee('Πρώτη επαφή: '.now()->subDays(20)->format('d/m/Y'))
            ->assertSee('(20 ημέρες)');
    }

    public function test_new_quote_from_lead_is_prefilled_and_logged_on_the_lead(): void
    {
        $lead = Lead::create([
            'company_id' => $this->tenant->id, 'name' => 'Καφενείο', 'afm' => '123456789',
            'city' => 'Θεσσαλονίκη', 'status' => LeadStatus::Contacted,
        ]);

        Livewire::withQueryParams(['lead' => $lead->id])
            ->test(CreateQuote::class)
            ->assertOk()
            ->assertFormSet([
                'lead_id' => $lead->id,
                'company_name' => 'Καφενείο',
                'vat_no' => '123456789',
                'city' => 'Θεσσαλονίκη',
            ]);

        // A foreign / unknown id is ignored — and so is a lead that must not be
        // contacted any more (URL / bookmark route, not only the hidden button).
        Livewire::withQueryParams(['lead' => 999999])
            ->test(CreateQuote::class)
            ->assertOk()
            ->assertFormSet(['lead_id' => null]);

        $dnc = Lead::create(['company_id' => $this->tenant->id, 'name' => 'DNC', 'status' => LeadStatus::DoNotContact, 'lost_reason' => 'x']);
        Livewire::withQueryParams(['lead' => $dnc->id])
            ->test(CreateQuote::class)
            ->assertOk()
            ->assertFormSet(['lead_id' => null, 'company_name' => null]);
        $this->assertNull(CreateQuote::tenantLeadId($dnc->id), 'tampered lead_id of a DNC lead is dropped');

        $trashedLead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Σβησμένο']);
        $trashedLead->delete();
        $this->assertNull(CreateQuote::tenantLeadId($trashedLead->id));

        // Model-level hand-off used by CreateQuote::afterCreate.
        $quote = Quote::create(['company_id' => $this->tenant->id, 'lead_id' => $lead->id, 'code' => 'ΠΡ-7', 'subject' => 'Hosting']);
        $lead->recordQuote($quote);

        $lead->refresh();
        $this->assertSame(LeadStatus::Quoted, $lead->status);
        $row = $lead->timeline()->where('type', LeadActivityType::Quote->value)->first();
        $this->assertSame($quote->id, $row->meta['quote_id']);
        $this->assertStringContainsString('ΠΡ-7', $row->body);

        Livewire::test(QuotesRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => EditLead::class])
            ->assertOk()
            ->assertSee('ΠΡ-7');
    }

    public function test_quote_lead_id_from_another_tenant_is_dropped_on_create(): void
    {
        $other = Company::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off']);
        $foreign = Lead::create(['company_id' => $other->id, 'name' => 'Ξένο']);

        $this->assertNull(CreateQuote::tenantLeadId($foreign->id));
        $this->assertNull(CreateQuote::tenantLeadId(999999));
        $mine = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Δικό μας']);
        $this->assertSame($mine->id, CreateQuote::tenantLeadId($mine->id));
    }

    public function test_origin_survives_a_soft_deleted_lead(): void
    {
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Σβησμένο μετά']);
        $customer = app(ConvertLeadToCustomer::class)($lead);
        $lead->delete();

        $this->assertSame($lead->id, $customer->fresh()->originLead?->id);
        $this->assertSame(1, Customer::query()->whereHas('originLead')->where('company_id', $this->tenant->id)->count());
    }

    public function test_note_requires_a_body(): void
    {
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Α']);

        Livewire::test(TimelineRelationManager::class, [
            'ownerRecord' => $lead,
            'pageClass' => EditLead::class,
        ])
            ->callTableAction('log_note', data: ['happened_at' => now()->format('Y-m-d H:i:s'), 'body' => ''])
            ->assertHasTableActionErrors(['body']);
    }
}
