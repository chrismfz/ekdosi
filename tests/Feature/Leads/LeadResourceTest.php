<?php

namespace Tests\Feature\Leads;

use App\Enums\LeadActivityType;
use App\Enums\LeadStatus;
use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Leads\RelationManagers\TimelineRelationManager;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Lead Co', 'slug' => 'lr-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.l', 'password' => bcrypt('x')]);
        $this->tenant->users()->attach($this->user);

        Gate::before(fn () => true);
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
            ->callAction('changeStatus', data: ['status' => LeadStatus::DoNotContact->value, 'reason' => ''])
            ->assertHasActionErrors(['reason']);

        $this->assertSame(LeadStatus::New, $lead->fresh()->status);
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
