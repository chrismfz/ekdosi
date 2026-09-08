<?php

namespace Tests\Feature\Leads;

use App\Enums\LeadActivityType;
use App\Enums\LeadStatus;
use App\Filament\Pages\LeadsBoard;
use App\Models\Company;
use App\Models\Lead;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Leads L3 — the kanban «Πίνακας leads»: columns = the open statuses, a drop
 * moves a lead through the SAME status hook as the modal (timeline row),
 * «Όχι τώρα» demands a date, Won/Lost/DNC are never drop targets, other
 * tenants' leads and closed leads are refused, and Update:Lead gates moving.
 */
class LeadsBoardTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private User $user;

    /** @var list<string> */
    private array $deny = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create(['name' => 'Board Co', 'slug' => 'kb-'.uniqid(), 'country_code' => 'GR']);
        $this->user = User::create(['name' => 'Άννα', 'email' => 'a-'.uniqid().'@t.l', 'password' => bcrypt('x')]);
        $this->tenant->users()->attach($this->user);

        Gate::before(fn ($user, string $ability): bool => ! in_array($ability, $this->deny, true));
        $this->actingAs($this->user);
        Filament::setTenant($this->tenant);
    }

    public function test_operators_hold_the_board_and_calendar_pages(): void
    {
        $this->assertSame(['View'], TenantRoleProvisioner::OPERATOR_PERMISSION_MAP['LeadsBoard']);
        $this->assertSame(['View'], TenantRoleProvisioner::OPERATOR_PERMISSION_MAP['LeadsCalendar']);
    }

    public function test_board_renders_open_leads_in_their_columns_only(): void
    {
        Lead::create(['company_id' => $this->tenant->id, 'name' => 'Νέο lead']);
        Lead::create(['company_id' => $this->tenant->id, 'name' => 'Ζεστό lead', 'status' => LeadStatus::Interested, 'assigned_user_id' => $this->user->id]);
        Lead::create(['company_id' => $this->tenant->id, 'name' => 'Χαμένο lead', 'status' => LeadStatus::Lost, 'lost_reason' => 'x']);
        $other = Company::create(['name' => 'Other', 'slug' => 'ot-'.uniqid(), 'country_code' => 'GR']);
        Lead::create(['company_id' => $other->id, 'name' => 'Ξένο lead']);

        $page = Livewire::test(LeadsBoard::class)
            ->assertOk()
            ->assertSee('Νέο lead')
            ->assertSee('Ζεστό lead')
            ->assertDontSee('Χαμένο lead')
            ->assertDontSee('Ξένο lead');

        $cards = $page->instance()->getCards();
        $this->assertSame(['new', 'contacted', 'interested', 'quoted', 'not_now'], array_keys($cards));
        $this->assertSame(['Νέο lead'], $cards['new']->pluck('name')->all());
        $this->assertSame(['Ζεστό lead'], $cards['interested']->pluck('name')->all());

        // «Τα δικά μου» keeps only the leads assigned to me.
        $page->set('operator', 'me')->assertSee('Ζεστό lead')->assertDontSee('Νέο lead');
    }

    public function test_a_drop_moves_the_lead_and_logs_the_status_change(): void
    {
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Κινούμενο']);

        Livewire::test(LeadsBoard::class)
            ->call('moveLead', $lead->id, 'contacted')
            ->assertNotified('Κινούμενο → Επικοινωνήσαμε');

        $this->assertSame(LeadStatus::Contacted, $lead->fresh()->status);
        $row = $lead->timeline()->where('type', LeadActivityType::StatusChange->value)->first();
        $this->assertSame(['from' => 'new', 'to' => 'contacted'], $row->meta, 'same hook as the modal');
        $this->assertSame($this->user->id, $row->user_id);
    }

    public function test_not_now_needs_a_date_and_goes_through_the_modal(): void
    {
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Αργότερα', 'status' => LeadStatus::Contacted]);

        // The plain drop is refused for «Όχι τώρα»…
        Livewire::test(LeadsBoard::class)
            ->call('moveLead', $lead->id, 'not_now')
            ->assertNotified('Μη έγκυρη στήλη.');
        $this->assertSame(LeadStatus::Contacted, $lead->fresh()->status);

        // …the modal without a date fails…
        Livewire::test(LeadsBoard::class)
            ->callAction('notNow', ['next_action_at' => null], ['lead' => $lead->id])
            ->assertHasActionErrors(['next_action_at']);

        // …with a date it moves and stamps the step.
        $when = now()->addWeek()->setTime(10, 0)->format('Y-m-d H:i:s');
        Livewire::test(LeadsBoard::class)
            ->callAction('notNow', ['next_action_at' => $when], ['lead' => $lead->id])
            ->assertHasNoActionErrors();

        $fresh = $lead->fresh();
        $this->assertSame(LeadStatus::NotNow, $fresh->status);
        $this->assertSame($when, $fresh->next_action_at->format('Y-m-d H:i:s'));

        // A slip-drop inside «Όχι τώρα»: the modal pre-fills the lead's OWN date
        // (never a fresh +1 week), so Save keeps the deliberate choice.
        Livewire::test(LeadsBoard::class)
            ->mountAction('notNow', ['lead' => $lead->id])
            ->assertActionDataSet(['next_action_at' => substr($when, 0, 16)]); // the picker (seconds(false)) formats Y-m-d H:i
    }

    public function test_columns_cap_cards_but_badge_shows_the_true_count(): void
    {
        $cap = LeadsBoard::MAX_PER_COLUMN;
        $rows = [];
        for ($i = 0; $i < $cap + 3; $i++) {
            $rows[] = ['company_id' => $this->tenant->id, 'name' => 'Lead '.$i, 'status' => 'new', 'created_at' => now(), 'updated_at' => now()];
        }
        Lead::query()->insert($rows);
        Lead::create(['company_id' => $this->tenant->id, 'name' => 'Μόνο του', 'status' => LeadStatus::Quoted]);

        $page = Livewire::test(LeadsBoard::class)->assertSee('το πλήθος στην κεφαλίδα είναι το πραγματικό');
        $board = $page->instance();
        $this->assertCount($cap, $board->getCards()['new'], 'cards capped per column');
        $this->assertSame($cap + 3, $board->columnCount('new'), 'the badge is the true count');
        $this->assertSame(1, $board->columnCount('quoted'), 'another column is not starved by the cap');
        $this->assertTrue($board->isCapped());
    }

    public function test_change_status_is_one_definition_with_its_rules(): void
    {
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Κανόνες']);

        try {
            $lead->changeStatus(LeadStatus::Won);
            $this->fail('Won is only written by the conversion.');
        } catch (\InvalidArgumentException) {
        }
        try {
            $lead->changeStatus(LeadStatus::Lost, '  ');
            $this->fail('Lost needs a reason.');
        } catch (\InvalidArgumentException) {
        }
        try {
            $lead->changeStatus(LeadStatus::NotNow);
            $this->fail('NotNow needs a date.');
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame(LeadStatus::New, $lead->fresh()->status, 'nothing written by a refused transition');

        $lead->changeStatus(LeadStatus::Lost, 'πολύ ακριβό');
        $this->assertSame('πολύ ακριβό', $lead->fresh()->lost_reason);
        $lead->changeStatus(LeadStatus::Contacted, 'σχόλιο που ΔΕΝ είναι λόγος');
        $this->assertNull($lead->fresh()->lost_reason, 'the reason is cleared on the way out');
        $this->assertSame(['new', 'lost', 'contacted'], collect([['to' => 'new']])->merge($lead->timeline()->reorder('id')->get()->map(fn ($r) => ['to' => $r->meta['to']]))->pluck('to')->all());
    }

    public function test_refuses_closed_statuses_foreign_and_closed_leads(): void
    {
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Α']);
        foreach (['won', 'lost', 'do_not_contact', 'bogus'] as $status) {
            Livewire::test(LeadsBoard::class)->call('moveLead', $lead->id, $status)->assertNotified('Μη έγκυρη στήλη.');
        }
        $this->assertSame(LeadStatus::New, $lead->fresh()->status);

        $other = Company::create(['name' => 'Other', 'slug' => 'ot-'.uniqid(), 'country_code' => 'GR']);
        $foreign = Lead::create(['company_id' => $other->id, 'name' => 'Ξένο']);
        Livewire::test(LeadsBoard::class)->call('moveLead', $foreign->id, 'contacted');
        $this->assertSame(LeadStatus::New, $foreign->fresh()->status, 'another tenant\'s lead never moves');

        $lost = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Χαμένο', 'status' => LeadStatus::Lost, 'lost_reason' => 'x']);
        Livewire::test(LeadsBoard::class)->call('moveLead', $lost->id, 'contacted');
        $this->assertSame(LeadStatus::Lost, $lost->fresh()->status, 'a closed lead is not reopened by a drop');

        // Same status → silent no-op, no second timeline row.
        Livewire::test(LeadsBoard::class)->call('moveLead', $lead->id, 'new');
        $this->assertSame(0, $lead->timeline()->count());
    }

    public function test_moving_needs_update_lead_and_the_page_needs_its_view_permission(): void
    {
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Α']);

        $this->deny = ['Update:Lead'];
        $page = Livewire::test(LeadsBoard::class)->assertOk()->assertSee('Μόνο ανάγνωση');
        $this->assertFalse($page->instance()->canMove());
        $page->call('moveLead', $lead->id, 'contacted')->assertNotified('Δεν έχεις δικαίωμα να αλλάζεις leads.');
        $this->assertSame(LeadStatus::New, $lead->fresh()->status);

        $this->assertTrue(LeadsBoard::canAccess());
        $this->deny = ['View:LeadsBoard'];
        $this->assertFalse(LeadsBoard::canAccess());
    }
}
