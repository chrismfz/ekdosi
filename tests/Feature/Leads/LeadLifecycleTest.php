<?php

namespace Tests\Feature\Leads;

use App\Enums\LeadActivityType;
use App\Enums\LeadStatus;
use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Model-level rules: every status change becomes a timeline row, the
 * `last_activity_at` cache follows the timeline, Won is never operator-
 * selectable, and the work scopes (open / overdue / stale) slice correctly.
 */
class LeadLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'T', 'slug' => 'll-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    public function test_status_change_appends_a_timeline_row_with_from_to_and_reason(): void
    {
        $t = $this->tenant();
        $user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.l', 'password' => bcrypt('x')]);
        $this->actingAs($user);

        $lead = Lead::create(['company_id' => $t->id, 'name' => 'Α']);
        $this->assertCount(0, $lead->timeline, 'Creation is not a status change.');

        $lead->update(['status' => LeadStatus::Lost, 'lost_reason' => 'πήρε άλλον']);

        $row = $lead->timeline()->first();
        $this->assertNotNull($row);
        $this->assertSame(LeadActivityType::StatusChange, $row->type);
        $this->assertSame(['from' => 'new', 'to' => 'lost'], $row->meta);
        $this->assertSame('πήρε άλλον', $row->body);
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame($t->id, $row->company_id);

        // Saving without touching the status adds nothing.
        $lead->update(['city' => 'Θεσσαλονίκη']);
        $this->assertSame(1, $lead->timeline()->count());
    }

    public function test_last_activity_cache_follows_the_timeline(): void
    {
        $t = $this->tenant();
        $lead = Lead::create(['company_id' => $t->id, 'name' => 'Α']);
        $this->assertNull($lead->fresh()->last_activity_at);

        $old = LeadActivity::create([
            'company_id' => $t->id, 'lead_id' => $lead->id,
            'type' => LeadActivityType::Call, 'happened_at' => now()->subDays(3),
        ]);
        $this->assertTrue($lead->fresh()->last_activity_at->isSameDay(now()->subDays(3)));

        $recent = LeadActivity::create([
            'company_id' => $t->id, 'lead_id' => $lead->id,
            'type' => LeadActivityType::Email, 'happened_at' => now()->subDay(),
        ]);
        $this->assertTrue($lead->fresh()->last_activity_at->isSameDay(now()->subDay()));

        // Back-dated rows don't move the cache forward; deleting the newest rolls it back.
        $recent->delete();
        $this->assertTrue($lead->fresh()->last_activity_at->isSameDay(now()->subDays(3)));

        $old->delete();
        $this->assertNull($lead->fresh()->last_activity_at);
    }

    public function test_last_activity_counts_only_real_contacts(): void
    {
        $t = $this->tenant();
        $lead = Lead::create(['company_id' => $t->id, 'name' => 'Α']);

        // A note and a status change are not contacts.
        LeadActivity::create(['company_id' => $t->id, 'lead_id' => $lead->id, 'type' => LeadActivityType::Note, 'happened_at' => now()]);
        $lead->update(['status' => LeadStatus::Interested]); // → status_change row at now()
        $this->assertNull($lead->fresh()->last_activity_at, 'Notes / status rows never count as a contact.');

        // A back-dated answered call that flips the status must NOT make the
        // lead look freshly worked: last contact = the call's own date.
        LeadActivity::create(['company_id' => $t->id, 'lead_id' => $lead->id, 'type' => LeadActivityType::Call, 'outcome' => 'answered', 'happened_at' => now()->subDays(20)]);
        $lead->update(['status' => LeadStatus::Contacted]);

        $this->assertTrue($lead->fresh()->last_activity_at->isSameDay(now()->subDays(20)));
        $this->assertSame([$lead->id], Lead::query()->stale(14)->pluck('id')->all(), 'Still «Αδρανές».');
    }

    public function test_observer_never_writes_another_tenants_lead(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $leadOfB = Lead::create(['company_id' => $b->id, 'name' => 'Β']);

        // Malformed row: company A, lead of company B.
        LeadActivity::create(['company_id' => $a->id, 'lead_id' => $leadOfB->id, 'type' => LeadActivityType::Call, 'happened_at' => now()]);

        $this->assertNull($leadOfB->fresh()->last_activity_at, 'The tenant-bounded write must not touch B.');
    }

    public function test_won_is_never_operator_selectable(): void
    {
        $this->assertArrayNotHasKey('won', LeadStatus::options());
        $this->assertArrayNotHasKey('won', LeadStatus::formOptions());
        $this->assertArrayNotHasKey('do_not_contact', LeadStatus::formOptions(), 'DNC only via the confirmed action.');
        $this->assertArrayHasKey('do_not_contact', LeadStatus::options());
        $this->assertNotContains(LeadStatus::Won, LeadStatus::selectable());
        $this->assertTrue(LeadStatus::Lost->requiresReason());
        $this->assertTrue(LeadStatus::DoNotContact->requiresReason());
        $this->assertFalse(LeadStatus::NotNow->requiresReason());
        $this->assertFalse(LeadStatus::Won->isOpen());
        $this->assertTrue(LeadStatus::NotNow->isOpen());
    }

    public function test_open_overdue_and_stale_scopes(): void
    {
        $t = $this->tenant();

        $fresh = Lead::create(['company_id' => $t->id, 'name' => 'Φρέσκο']);
        $overdue = Lead::create(['company_id' => $t->id, 'name' => 'Ληξιπρόθεσμο', 'status' => LeadStatus::Contacted, 'next_action_at' => now()->subHour()]);
        $future = Lead::create(['company_id' => $t->id, 'name' => 'Μελλοντικό', 'next_action_at' => now()->addDay()]);
        $won = Lead::create(['company_id' => $t->id, 'name' => 'Κερδισμένο', 'status' => LeadStatus::Won, 'next_action_at' => now()->subDay()]);
        $lost = Lead::create(['company_id' => $t->id, 'name' => 'Χαμένο', 'status' => LeadStatus::Lost, 'lost_reason' => 'x']);

        $stale = Lead::create(['company_id' => $t->id, 'name' => 'Αδρανές']);
        Lead::query()->whereKey($stale->id)->update(['created_at' => now()->subDays(30)]);

        $staleWithOldContact = Lead::create(['company_id' => $t->id, 'name' => 'Αδρανές με παλιά επαφή']);
        LeadActivity::create(['company_id' => $t->id, 'lead_id' => $staleWithOldContact->id, 'type' => LeadActivityType::Call, 'happened_at' => now()->subDays(20)]);

        $ids = fn ($q) => $q->orderBy('id')->pluck('id')->all();

        $this->assertSame(
            [$fresh->id, $overdue->id, $future->id, $stale->id, $staleWithOldContact->id],
            $ids(Lead::query()->open()),
        );
        $this->assertSame([$overdue->id], $ids(Lead::query()->overdue()));
        $this->assertSame([$stale->id, $staleWithOldContact->id], $ids(Lead::query()->stale(14)));

        $this->assertTrue($overdue->isOverdue());
        $this->assertFalse($won->isOverdue(), 'Closed leads are never overdue.');
        $this->assertFalse($lost->isOpen());
    }
}
