<?php

namespace Tests\Feature\Assistant;

use App\Actions\ConvertLeadToCustomer;
use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use App\Services\Assistant\ToolRegistry;
use App\Services\Assistant\Tools\LeadsPulseTool;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * `leads_pulse` — «ασχολήθηκε κανείς με τα leads;» από έξω (MCP / Βοηθός):
 * σύνολα, τι έκανε κάθε χειριστής, ποιος άνοιξε τα τελευταία leads και πότε.
 * Τα νούμερα βγαίνουν από το ΙΔΙΟ SalesActivityReport με τη σελίδα.
 */
class LeadsPulseToolTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private User $anna;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);

        $this->tenant = Company::create([
            'name' => 'Pulse OE', 'slug' => 'pl-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->anna = User::create(['name' => 'Άννα', 'email' => 'a-'.uniqid().'@t.l', 'password' => bcrypt('x')]);
        $this->tenant->users()->attach($this->anna);
        $this->actingAs($this->anna);
        Filament::setTenant($this->tenant);
    }

    private function pulse(array $input = []): array
    {
        return (new LeadsPulseTool)->run($this->tenant, $input);
    }

    public function test_a_quiet_week_reads_as_quiet(): void
    {
        Lead::create(['company_id' => $this->tenant->id, 'name' => 'Δοκιμαστικό']);

        $out = $this->pulse();

        $this->assertSame(1, $out['totals']['open']);
        $this->assertSame(1, $out['totals']['new_in_period']);
        $this->assertSame(0, $out['totals']['overdue']);
        $this->assertSame(1, $out['totals']['without_next_step'], 'the lead the calendar cannot show');
        $this->assertSame([], $out['recent_activity'], 'nobody touched it');
        $this->assertSame(0, collect($out['per_operator'])->sum('calls'));
    }

    public function test_it_answers_who_opened_the_lead_and_when(): void
    {
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Καφενείο Ο Νίκος']);

        $newest = $this->pulse()['newest_leads'];

        $this->assertCount(1, $newest);
        $this->assertSame($lead->id, $newest[0]['id']);
        $this->assertSame('Καφενείο Ο Νίκος', $newest[0]['name']);
        $this->assertSame('Άννα', $newest[0]['created_by'], 'from the activity log — leads has no created_by column');
        $this->assertNotNull($newest[0]['created_at']);
        $this->assertSame('Νέο', $newest[0]['status']);
    }

    public function test_it_shows_what_each_operator_did_and_agrees_with_the_report(): void
    {
        $nikos = User::create(['name' => 'Νίκος', 'email' => 'n-'.uniqid().'@t.l', 'password' => bcrypt('x')]);
        $this->tenant->users()->attach($nikos);

        $worked = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Δουλεμένο', 'assigned_user_id' => $this->anna->id]);
        foreach ([['call', 'answered'], ['call', 'no_answer'], ['email', 'replied']] as [$type, $outcome]) {
            LeadActivity::create([
                'company_id' => $this->tenant->id, 'lead_id' => $worked->id, 'user_id' => $this->anna->id,
                'type' => $type, 'outcome' => $outcome, 'happened_at' => now()->subHour(), 'body' => 'x',
            ]);
        }
        app(ConvertLeadToCustomer::class)($worked);

        $out = $this->pulse(['days' => 7]);

        $anna = collect($out['per_operator'])->firstWhere('operator', 'Άννα');
        $this->assertSame(2, $anna['calls']);
        $this->assertSame(1, $anna['calls_answered']);
        $this->assertSame(1, $anna['emails']);
        $this->assertSame(1, $anna['conversions']);

        // The idle operator is listed at zero — that is the whole question.
        $this->assertSame(0, collect($out['per_operator'])->firstWhere('operator', 'Νίκος')['calls']);

        $this->assertSame(1, $out['totals']['converted_in_period']);
        $this->assertNotEmpty($out['recent_activity']);
        $this->assertSame('Άννα', $out['recent_activity'][0]['by']);
        $this->assertSame('Δουλεμένο', $out['recent_activity'][0]['lead']);
    }

    public function test_the_window_and_the_tenant_bound_it(): void
    {
        $old = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Παλιό']);
        LeadActivity::create([
            'company_id' => $this->tenant->id, 'lead_id' => $old->id, 'user_id' => $this->anna->id,
            'type' => 'call', 'outcome' => 'answered', 'happened_at' => now()->subDays(30), 'body' => 'x',
        ]);

        $this->assertSame(0, collect($this->pulse(['days' => 7])['per_operator'])->sum('calls'));
        $this->assertSame(1, collect($this->pulse(['days' => 60])['per_operator'])->sum('calls'));

        // Another tenant's leads never leak in.
        $other = Company::create(['name' => 'Other', 'slug' => 'ot-'.uniqid(), 'country_code' => 'GR']);
        Lead::create(['company_id' => $other->id, 'name' => 'Ξένο']);
        $out = $this->pulse();
        $this->assertSame(['Παλιό'], collect($out['newest_leads'])->pluck('name')->all());

        // A silly window is clamped, never a huge scan.
        $this->assertSame(365, $this->pulse(['days' => 99999])['period']['days']);
        $this->assertSame(1, $this->pulse(['days' => 0])['period']['days']);
    }

    public function test_it_is_registered_and_gated_on_the_leads_permission(): void
    {
        $this->assertSame('ViewAny:Lead', (new LeadsPulseTool)->permission());

        $names = array_column(app(ToolRegistry::class)->definitionsFor($this->anna), 'name');
        $this->assertContains('leads_pulse', $names, 'offered to the Βοηθός and over MCP');

        // …and it runs through the registry (the path the MCP tool takes).
        $out = app(ToolRegistry::class)->run($this->tenant, $this->anna, 'leads_pulse', ['days' => 7]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertArrayHasKey('per_operator', $out);

        $flags = collect($this->pulse()['totals'])->keys();
        $this->assertContains('stale', $flags);
        $this->assertContains('due_today_or_earlier', $flags);
    }
}
