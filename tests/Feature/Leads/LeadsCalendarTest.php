<?php

namespace Tests\Feature\Leads;

use App\Enums\LeadStatus;
use App\Filament\Pages\LeadsCalendar;
use App\Models\Company;
use App\Models\Lead;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Leads L3 — the «Ημερολόγιο leads»: a Monday-first month grid of the open
 * leads' next steps, month navigation, the off-screen-overdue hint, and
 * drag-to-reschedule that keeps the time of day and is gated on Update:Lead.
 */
class LeadsCalendarTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private User $user;

    /** @var list<string> */
    private array $deny = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-16 09:00:00'); // a Wednesday
        $this->tenant = Company::create(['name' => 'Cal Co', 'slug' => 'lc-'.uniqid(), 'country_code' => 'GR']);
        $this->user = User::create(['name' => 'Άννα', 'email' => 'a-'.uniqid().'@t.l', 'password' => bcrypt('x')]);
        $this->tenant->users()->attach($this->user);

        Gate::before(fn ($user, string $ability): bool => ! in_array($ability, $this->deny, true));
        $this->actingAs($this->user);
        Filament::setTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lead(string $name, ?string $next, LeadStatus $status = LeadStatus::New): Lead
    {
        return Lead::create([
            'company_id' => $this->tenant->id, 'name' => $name, 'next_action_at' => $next, 'status' => $status,
            'lost_reason' => $status->requiresReason() ? 'x' : null, 'assigned_user_id' => $this->user->id,
        ]);
    }

    public function test_grid_is_monday_first_and_covers_the_month(): void
    {
        $page = Livewire::test(LeadsCalendar::class)->assertOk()->assertSet('month', '2026-09');
        $weeks = $page->instance()->weeks();

        $this->assertSame('2026-08-31', $weeks[0][0]->toDateString(), 'September 2026 starts on a Tuesday → the grid opens the Monday before');
        $this->assertSame('2026-10-04', end($weeks)[6]->toDateString(), 'ends on the Sunday after the 30th');
        $this->assertCount(5, $weeks);
        $this->assertStringContainsString('2026', $page->instance()->monthLabel());
    }

    public function test_items_land_on_their_day_and_overdue_is_flagged(): void
    {
        $this->lead('Σήμερα 15:00', '2026-09-16 15:00:00');
        $this->lead('Πέρασε', '2026-09-10 11:00:00');
        $this->lead('Οκτώβριος lead', '2026-10-20 09:00:00');
        $this->lead('Χωρίς βήμα', null);
        $this->lead('Χαμένο', '2026-09-18 09:00:00', LeadStatus::Lost);
        $this->lead('Πολύ παλιό', '2026-07-01 09:00:00');

        $page = Livewire::test(LeadsCalendar::class)
            ->assertSee('Σήμερα 15:00')
            ->assertSee('Πέρασε')
            ->assertDontSee('Οκτώβριος lead')
            ->assertDontSee('Χωρίς βήμα')
            ->assertDontSee('Χαμένο')
            ->assertSee('1 ληξιπρόθεσμα βήματα πριν από αυτόν τον μήνα');

        $items = $page->instance()->getItems();
        $this->assertSame(['Σήμερα 15:00'], $items['2026-09-16']->pluck('name')->all());
        $this->assertSame(['Πέρασε'], $items['2026-09-10']->pluck('name')->all());
        $this->assertTrue($items['2026-09-10'][0]->isOverdue());
        $this->assertFalse($items['2026-09-16'][0]->isOverdue(), 'later today is due, not overdue');

        $page->call('nextMonth')->assertSet('month', '2026-10')->assertSee('Οκτώβριος lead')->assertDontSee('Σήμερα 15:00');
        $page->call('previousMonth')->call('previousMonth')->assertSet('month', '2026-08');
        $page->call('thisMonth')->assertSet('month', '2026-09');
        $page->set('month', 'garbage')->assertSet('month', '2026-09');
    }

    public function test_it_says_how_many_open_leads_have_no_next_step_at_all(): void
    {
        // A calendar is an agenda of next steps: a lead without a date cannot
        // appear on it. The page must SAY so rather than look empty/broken.
        $this->lead('Χωρίς βήμα Α', null);
        $this->lead('Χωρίς βήμα Β', null);
        $this->lead('Με βήμα', '2026-09-16 15:00:00');
        $this->lead('Κλειστό χωρίς βήμα', null, LeadStatus::Lost);

        $page = Livewire::test(LeadsCalendar::class)
            ->assertSee('μόνο τα leads που έχουν «επόμενο βήμα»')
            ->assertSee('2 ανοιχτά leads');

        $this->assertSame(2, $page->instance()->withoutNextStep());

        // …and it follows the operator filter like everything else.
        $other = User::create(['name' => 'Άλλος', 'email' => 'o-'.uniqid().'@t.l', 'password' => bcrypt('x')]);
        $this->tenant->users()->attach($other);
        $this->assertSame(0, $page->set('operator', (string) $other->id)->instance()->withoutNextStep());
    }

    public function test_drag_to_another_day_keeps_the_time_and_is_gated(): void
    {
        $lead = $this->lead('Μεταθέσιμο', '2026-09-16 15:30:00');

        Livewire::test(LeadsCalendar::class)
            ->call('reschedule', $lead->id, '2026-09-23')
            ->assertNotified('Μεταθέσιμο → 23/09/2026 15:30');
        $this->assertSame('2026-09-23 15:30:00', $lead->fresh()->next_action_at->format('Y-m-d H:i:s'));

        // Same day → no-op; bad date → refused; a lead without a step → refused.
        Livewire::test(LeadsCalendar::class)->call('reschedule', $lead->id, '2026-09-23');
        Livewire::test(LeadsCalendar::class)->call('reschedule', $lead->id, '23/09/2026')->assertNotified('Μη έγκυρη ημερομηνία.');
        Livewire::test(LeadsCalendar::class)->call('reschedule', $lead->id, '2026-02-31')->assertNotified('Μη έγκυρη ημερομηνία.');
        $this->assertSame('2026-09-23', $lead->fresh()->next_action_at->toDateString(), 'an impossible date never rolls over to March');
        $none = $this->lead('Χωρίς', null);
        Livewire::test(LeadsCalendar::class)->call('reschedule', $none->id, '2026-09-23')->assertNotified('Το lead δεν έχει επόμενο βήμα.');
        $this->assertNull($none->fresh()->next_action_at);

        // Another tenant's lead never moves.
        $other = Company::create(['name' => 'Other', 'slug' => 'ot-'.uniqid(), 'country_code' => 'GR']);
        $foreign = Lead::create(['company_id' => $other->id, 'name' => 'Ξένο', 'next_action_at' => '2026-09-16 10:00:00']);
        Livewire::test(LeadsCalendar::class)->call('reschedule', $foreign->id, '2026-09-23');
        $this->assertSame('2026-09-16', $foreign->fresh()->next_action_at->toDateString());

        // No Update:Lead → read-only.
        $this->deny = ['Update:Lead'];
        Livewire::test(LeadsCalendar::class)->assertSee('Μόνο ανάγνωση')
            ->call('reschedule', $lead->id, '2026-09-30')->assertNotified('Δεν έχεις δικαίωμα να αλλάζεις leads.');
        $this->assertSame('2026-09-23', $lead->fresh()->next_action_at->toDateString());

        $this->deny = ['View:LeadsCalendar'];
        $this->assertFalse(LeadsCalendar::canAccess());
    }
}
