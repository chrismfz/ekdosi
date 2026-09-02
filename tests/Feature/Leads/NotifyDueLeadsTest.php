<?php

namespace Tests\Feature\Leads;

use App\Enums\LeadStatus;
use App\Models\Company;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Leads L2 — `leads:notify-due`: one bell per user for the open leads whose
 * next step is due today or overdue; assigned → its operator, unassigned (or
 * assigned to someone who left the tenant) → everyone; closed / future / other
 * tenant never; dry-run sends nothing; the scheduler flag defaults OFF.
 */
class NotifyDueLeadsTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private User $anna;

    private User $nikos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create(['name' => 'Due Co', 'slug' => 'nd-'.uniqid(), 'country_code' => 'GR']);
        $this->anna = User::create(['name' => 'Άννα', 'email' => 'anna-'.uniqid().'@t.l', 'password' => bcrypt('x')]);
        $this->nikos = User::create(['name' => 'Νίκος', 'email' => 'nikos-'.uniqid().'@t.l', 'password' => bcrypt('x')]);
        $this->tenant->users()->attach([$this->anna->id, $this->nikos->id]);

        // Allow-all gate (roles are provisioned in production); a test may deny.
        Gate::before(fn ($user, string $ability): bool => ! in_array($ability, $this->deny, true) && ! in_array($user->id, $this->denyUsers, true));
    }

    /** @var list<string> */
    private array $deny = [];

    /** @var list<int> user ids who may see nothing */
    private array $denyUsers = [];

    private function lead(string $name, ?\DateTimeInterface $next, ?User $assignee = null, LeadStatus $status = LeadStatus::New): Lead
    {
        return Lead::create([
            'company_id' => $this->tenant->id, 'name' => $name, 'next_action_at' => $next,
            'assigned_user_id' => $assignee?->id, 'status' => $status,
            'lost_reason' => $status->requiresReason() ? 'x' : null,
        ]);
    }

    public function test_assigned_leads_ping_their_operator_and_unassigned_ping_everyone(): void
    {
        $this->lead('Ληξιπρόθεσμο της Άννας', now()->subDay(), $this->anna);
        $this->lead('Σήμερα της Άννας', now()->endOfDay()->subMinute(), $this->anna);
        $this->lead('Χωρίς χειριστή', now()->subDays(3));
        // Never: future step, no step, closed statuses, other tenant.
        $this->lead('Αύριο', now()->addDay(), $this->nikos);
        $this->lead('Χωρίς βήμα', null, $this->nikos);
        $this->lead('Χαμένο', now()->subDay(), $this->nikos, LeadStatus::Lost);
        $other = Company::create(['name' => 'Other', 'slug' => 'ot-'.uniqid(), 'country_code' => 'GR']);
        Lead::create(['company_id' => $other->id, 'name' => 'Ξένο', 'next_action_at' => now()->subDay(), 'assigned_user_id' => $this->nikos->id]);

        $this->artisan('leads:notify-due', ['--tenant' => $this->tenant->slug])->assertExitCode(0);

        $this->assertSame(1, $this->anna->notifications()->count(), 'ONE digest for Άννα (her two + the unassigned one)');
        $this->assertSame(1, $this->nikos->notifications()->count(), 'Νίκος hears only about the unassigned one');

        $annaData = $this->anna->notifications()->first()->data;
        $this->assertSame('Επόμενο βήμα σε 3 leads', $annaData['title']);
        $this->assertStringContainsString('Ληξιπρόθεσμο της Άννας', $annaData['body']);
        $this->assertStringContainsString('2 ληξιπρόθεσμα', $annaData['body']);
        $this->assertStringContainsString('tab=overdue', $annaData['actions'][0]['url']);
        $this->assertStringNotContainsString('activeTab', $annaData['actions'][0]['url'], 'ListRecords reads ?tab=, not ?activeTab=');

        $nikosData = $this->nikos->notifications()->first()->data;
        $this->assertSame('Επόμενο βήμα σε lead', $nikosData['title']);
        $this->assertStringContainsString('Χωρίς χειριστή', $nikosData['body']);
        $this->assertStringNotContainsString('Αύριο', $nikosData['body']);
    }

    public function test_an_assignee_who_left_the_tenant_falls_back_to_everyone(): void
    {
        $gone = User::create(['name' => 'Έφυγε', 'email' => 'gone-'.uniqid().'@t.l', 'password' => bcrypt('x')]);
        $this->lead('Ορφανό', now()->subDay(), $gone);

        $this->artisan('leads:notify-due')->assertExitCode(0);

        $this->assertSame(0, $gone->notifications()->count());
        $this->assertSame(1, $this->anna->notifications()->count());
        $this->assertSame(1, $this->nikos->notifications()->count());
    }

    public function test_users_without_lead_permission_never_get_the_bell(): void
    {
        $this->lead('Της Άννας', now()->subDay(), $this->anna);
        $this->lead('Χωρίς χειριστή', now()->subDay());
        $this->denyUsers = [$this->nikos->id]; // e.g. an accounting-only role

        $this->artisan('leads:notify-due')->assertExitCode(0);

        $this->assertSame(1, $this->anna->notifications()->count());
        $this->assertSame(0, $this->nikos->notifications()->count(), 'no bell whose «Προβολή» would 403');

        // An assignee who may not see leads: the lead falls back to everyone who may.
        $this->denyUsers = [$this->anna->id];
        $this->artisan('leads:notify-due')->assertExitCode(0);
        $this->assertSame(1, $this->anna->notifications()->count(), 'nothing new for Άννα');
        $this->assertSame(1, $this->nikos->notifications()->count(), 'Νίκος (allowed now) hears about both');

        // Nobody may see leads → quiet, nothing sent.
        $this->denyUsers = [$this->anna->id, $this->nikos->id];
        $this->artisan('leads:notify-due')->expectsOutputToContain('κανένας χρήστης με δικαίωμα')->assertExitCode(0);
    }

    public function test_dry_run_sends_nothing_and_a_missing_tenant_fails(): void
    {
        $this->lead('Ληξιπρόθεσμο', now()->subDay(), $this->anna);

        $this->artisan('leads:notify-due', ['--dry-run' => true])
            ->expectsOutputToContain('1 lead(s)')
            ->assertExitCode(0);
        $this->assertSame(0, $this->anna->notifications()->count());

        $this->artisan('leads:notify-due', ['--tenant' => 'nope'])->assertExitCode(1);
    }

    public function test_nothing_due_is_a_quiet_success(): void
    {
        $this->lead('Αύριο', now()->addDay(), $this->anna);

        $this->artisan('leads:notify-due')
            ->expectsOutputToContain('κανένα lead')
            ->assertExitCode(0);
        $this->assertSame(0, $this->anna->notifications()->count());
    }

    public function test_schedule_flag_defaults_off_and_is_registered(): void
    {
        $this->assertFalse((bool) config('ekdosi.schedule.leads_notify_due_enabled'));
        $this->assertSame('08:00', config('ekdosi.schedule.leads_notify_due_time'));

        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e): bool => str_contains((string) $e->command, 'leads:notify-due'));
        $this->assertCount(1, $events, 'wired in routes/console.php');
    }
}
