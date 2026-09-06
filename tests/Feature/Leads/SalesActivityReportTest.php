<?php

namespace Tests\Feature\Leads;

use App\Actions\ConvertLeadToCustomer;
use App\Enums\LeadActivityType;
use App\Filament\Pages\SalesActivityReport as ReportPage;
use App\Filament\Widgets\LeadsStats;
use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use App\Services\Leads\SalesActivityReport;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Leads L2 — the «Απολογισμός πωλήσεων»: per-operator counts come from the
 * timeline rows (by who wrote them) + lead assignment, the page renders and
 * filters, the CSV streams, the permission gates hold, and the dashboard
 * widget counts + gates.
 */
class SalesActivityReportTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private User $anna;

    private User $nikos;

    /** Abilities to deny in the allow-all gate (e.g. 'View:SalesActivityReport'). */
    private array $deny = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Sales Co', 'slug' => 'sa-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->anna = User::create(['name' => 'Άννα', 'email' => 'anna-'.uniqid().'@t.l', 'password' => bcrypt('x')]);
        $this->nikos = User::create(['name' => 'Νίκος', 'email' => 'nikos-'.uniqid().'@t.l', 'password' => bcrypt('x')]);
        $this->tenant->users()->attach([$this->anna->id, $this->nikos->id]);

        Gate::before(fn ($user, string $ability): bool => ! in_array($ability, $this->deny, true));
        $this->actingAs($this->anna);
        Filament::setTenant($this->tenant);
    }

    private function activity(Lead $lead, ?User $by, LeadActivityType $type, ?string $outcome = null, ?\DateTimeInterface $at = null, array $meta = []): LeadActivity
    {
        return LeadActivity::create([
            'company_id' => $lead->company_id, 'lead_id' => $lead->id, 'user_id' => $by?->id,
            'type' => $type->value, 'outcome' => $outcome, 'happened_at' => $at ?? now(),
            'body' => 'x', 'meta' => $meta,
        ]);
    }

    private function seedWeek(): array
    {
        $a = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Lead A', 'assigned_user_id' => $this->anna->id]);
        $b = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Lead B', 'assigned_user_id' => $this->nikos->id]);
        $c = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Lead C']); // unassigned

        // Άννα: 2 calls (1 answered), 1 email replied, 1 meeting held, 1 quote, 1 lost.
        $this->activity($a, $this->anna, LeadActivityType::Call, 'answered');
        $this->activity($a, $this->anna, LeadActivityType::Call, 'no_answer');
        $this->activity($a, $this->anna, LeadActivityType::Email, 'replied');
        $this->activity($a, $this->anna, LeadActivityType::Meeting, 'held');
        $this->activity($a, $this->anna, LeadActivityType::Quote);
        $this->activity($c, $this->anna, LeadActivityType::StatusChange, meta: ['from' => 'new', 'to' => 'lost']);
        // Νίκος: 1 call no answer, a note (not a contact), a status change that is NOT lost.
        $this->activity($b, $this->nikos, LeadActivityType::Call, 'no_answer');
        $this->activity($b, $this->nikos, LeadActivityType::Note);
        $this->activity($b, $this->nikos, LeadActivityType::StatusChange, meta: ['from' => 'new', 'to' => 'contacted']);
        // Outside the period — never counted.
        $this->activity($b, $this->nikos, LeadActivityType::Call, 'answered', now()->subDays(30));
        // System row (no user).
        $this->activity($c, null, LeadActivityType::StatusChange, meta: ['from' => 'new', 'to' => 'lost']);

        return [$a, $b, $c];
    }

    public function test_counts_per_operator_from_timeline_rows_and_assignment(): void
    {
        $this->seedWeek();

        $result = app(SalesActivityReport::class)->build($this->tenant, now()->startOfWeek(), now()->endOfWeek());

        $byName = collect($result->operators)->keyBy('name');
        $anna = $byName['Άννα'];
        $this->assertSame(1, $anna->get('new_leads'));
        $this->assertSame(2, $anna->get('calls'));
        $this->assertSame(1, $anna->get('calls_answered'));
        $this->assertSame(1, $anna->get('emails'));
        $this->assertSame(1, $anna->get('emails_replied'));
        $this->assertSame(1, $anna->get('meetings'));
        $this->assertSame(1, $anna->get('meetings_held'));
        $this->assertSame(1, $anna->get('quotes'));
        $this->assertSame(1, $anna->get('lost'));
        $this->assertSame(1, $anna->get('open'));

        $nikos = $byName['Νίκος'];
        $this->assertSame(1, $nikos->get('calls'), 'the call 30 days ago is outside the period');
        $this->assertSame(0, $nikos->get('calls_answered'));
        $this->assertSame(0, $nikos->get('lost'), 'a status change to «contacted» is not a loss');

        $system = $byName['— χωρίς χειριστή —'];
        $this->assertSame(1, $system->get('lost'));
        $this->assertSame(1, $system->get('new_leads'), 'the unassigned lead');
        $this->assertNull($system->userId);
        $this->assertSame('— χωρίς χειριστή —', $result->operators[array_key_last($result->operators)]->name, 'the no-operator row sorts last');

        $totals = $result->totals();
        $this->assertSame(3, $totals['calls']);
        $this->assertSame(3, $totals['new_leads']);
        $this->assertSame(3, $totals['open']);

        $this->assertSame(3, $result->funnel['new']);
        $this->assertSame(0, $result->funnel['won']);
        $this->assertCount(10, $result->log, 'the day log carries every row of the period (the note too), not the old one');
        $this->assertFalse($result->logTruncated());
    }

    public function test_every_tenant_user_gets_a_row_even_with_zero_activity(): void
    {
        $idle = User::create(['name' => 'Αδρανής', 'email' => 'idle-'.uniqid().'@t.l', 'password' => bcrypt('x')]);
        $this->tenant->users()->attach($idle);
        $stranger = User::create(['name' => 'Ξένος', 'email' => 'x-'.uniqid().'@t.l', 'password' => bcrypt('x')]);
        $this->seedWeek();

        $result = app(SalesActivityReport::class)->build($this->tenant, now()->startOfWeek(), now()->endOfWeek());
        $names = array_map(fn ($r) => $r->name, $result->operators);
        $this->assertContains('Αδρανής', $names, 'the operator who did nothing is the whole point of the report');
        $this->assertNotContains('Ξένος', $names, 'not a member of the tenant');
        $this->assertSame(0, collect($result->operators)->firstWhere('name', 'Αδρανής')->get('calls'));
        $this->assertSame(['Άννα', 'Αδρανής', 'Νίκος', '— χωρίς χειριστή —'], $names, 'alphabetical, no-operator last');

        // Filtered to one operator → only that one row (even at zero).
        $only = app(SalesActivityReport::class)->build($this->tenant, now()->startOfWeek(), now()->endOfWeek(), $idle->id);
        $this->assertCount(1, $only->operators);
        $this->assertSame('Αδρανής', $only->operators[0]->name);
    }

    public function test_csv_is_complete_while_the_page_shows_the_first_300(): void
    {
        // Pin the clock mid-week: the rows below span ~5h back (now()->subMinutes up to
        // LOG_LIMIT+5), and the report window is the current week — run near the week
        // boundary (e.g. just after Monday 00:00) the older rows fall out of range and the
        // count flakes. A fixed Wednesday noon keeps every row inside the same week.
        $this->travelTo(Carbon::parse('2026-06-17 12:00:00'));

        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Πολυάσχολο']);
        $limit = SalesActivityReport::LOG_LIMIT;
        $rows = [];
        for ($i = 0; $i < $limit + 5; $i++) {
            $rows[] = [
                'company_id' => $this->tenant->id, 'lead_id' => $lead->id, 'user_id' => $this->anna->id,
                'type' => 'note', 'happened_at' => now()->subMinutes($i), 'body' => 'row-'.$i,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        LeadActivity::query()->insert($rows);

        $result = app(SalesActivityReport::class)->build($this->tenant, now()->startOfWeek(), now()->endOfWeek());
        $this->assertCount($limit + 5, $result->log, 'the service keeps everything');
        $this->assertCount($limit, $result->logPreview());
        $this->assertTrue($result->logTruncated());

        // The page renders the newest LOG_LIMIT and says so; the oldest row is not on screen…
        Livewire::test(ReportPage::class)
            ->assertSee('το CSV τις έχει όλες')
            ->assertSee('row-0')
            ->assertDontSee('row-'.($limit + 4));

        // …but IS in the CSV.
        $page = app(ReportPage::class);
        $page->mount();
        $method = new \ReflectionMethod($page, 'exportCsv');
        ob_start();
        $method->invoke($page)->sendContent();
        $csv = ob_get_clean();
        $this->assertStringContainsString('row-'.($limit + 4), $csv, 'the CSV is never cut where the page is');
        $this->assertSame($limit + 5, substr_count($csv, ';row-'), 'every row of the period, once');
    }

    public function test_operator_filter_and_conversions_and_tenant_isolation(): void
    {
        [$a] = $this->seedWeek();
        app(ConvertLeadToCustomer::class)($a);

        // Another tenant's activity is invisible.
        $other = Company::create(['name' => 'Other', 'slug' => 'ot-'.uniqid(), 'country_code' => 'GR']);
        $foreign = Lead::create(['company_id' => $other->id, 'name' => 'Ξένο', 'assigned_user_id' => $this->anna->id]);
        $this->activity($foreign, $this->anna, LeadActivityType::Call, 'answered');

        $result = app(SalesActivityReport::class)->build($this->tenant, now()->startOfWeek(), now()->endOfWeek(), $this->anna->id);

        $this->assertCount(1, $result->operators);
        $anna = $result->operators[0];
        $this->assertSame($this->anna->id, $anna->userId);
        $this->assertSame(1, $anna->get('conversions'));
        $this->assertSame(2, $anna->get('calls'), 'the foreign tenant call is not counted');
        $this->assertSame(0, $anna->get('open'), 'converted → no longer open');
        $this->assertSame(1, $result->funnel['won']);
        $this->assertSame(0, $result->funnel['new'], 'funnel is per the filtered operator (Άννα has only the won one)');
    }

    public function test_page_renders_filters_and_exports_csv(): void
    {
        $this->seedWeek();

        $page = Livewire::test(ReportPage::class)
            ->assertOk()
            ->assertSet('period', 'week')
            ->assertSee('Άννα')
            ->assertSee('Νίκος')
            ->assertSee('Lead A');

        // Presets move the window; a manual edit flips to custom.
        $page->set('period', 'last_month')
            ->assertSet('from', now()->subMonthsNoOverflow(1)->startOfMonth()->toDateString())
            ->assertDontSee('Lead A')
            ->set('from', now()->startOfWeek()->toDateString())
            ->assertSet('period', 'custom');

        // Operator filter narrows the report to that operator only (the picker
        // itself still lists everyone, so assert on the log rows).
        $page->set('period', 'week')
            ->set('operator', (string) $this->nikos->id)
            ->assertSee('Lead B')
            ->assertDontSee('Lead A');

        // A user id outside the tenant is ignored (everyone shown again).
        $stranger = User::create(['name' => 'Ξένος', 'email' => 'x-'.uniqid().'@t.l', 'password' => bcrypt('x')]);
        $page->set('operator', (string) $stranger->id)->assertSee('Lead A');

        $page->set('operator', '')
            ->callAction('export_csv')
            ->assertFileDownloaded();
    }

    public function test_page_and_widget_are_permission_gated(): void
    {
        $this->assertTrue(ReportPage::canAccess());
        $this->assertTrue(LeadsStats::canView());

        $this->deny = ['View:SalesActivityReport', 'ViewAny:Lead'];
        $this->assertFalse(ReportPage::canAccess());
        $this->assertFalse(LeadsStats::canView());
    }

    public function test_widget_counts_open_overdue_stale_and_monthly_conversions(): void
    {
        Lead::create(['company_id' => $this->tenant->id, 'name' => 'Ανοιχτό']);
        Lead::create(['company_id' => $this->tenant->id, 'name' => 'Ληξιπρόθεσμο', 'next_action_at' => now()->subDay()]);
        $stale = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Αδρανές']);
        Lead::query()->whereKey($stale->id)->update(['created_at' => now()->subDays(30)]);
        $won = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Κερδισμένο']);
        app(ConvertLeadToCustomer::class)($won);
        // An old conversion is not this month's.
        $old = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Παλιό']);
        $this->activity($old, $this->anna, LeadActivityType::Converted, at: now()->subMonthsNoOverflow(2));

        Livewire::test(LeadsStats::class)
            ->assertOk()
            ->assertSee('Ανοιχτά leads')
            ->assertSeeInOrder(['Ανοιχτά leads', '3'])
            ->assertSeeInOrder(['Ληξιπρόθεσμα βήματα', '1'])
            ->assertSee('1 αδρανή')
            ->assertSeeInOrder(['Μετατροπές μήνα', '1']);
    }
}
