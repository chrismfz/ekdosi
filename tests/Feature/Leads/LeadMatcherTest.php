<?php

namespace Tests\Feature\Leads;

use App\Enums\LeadStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Lead;
use App\Services\Leads\LeadMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Να μην ξαναζαλίζουμε κόσμο»: the matcher must find an existing customer
 * or an older lead by ΑΦΜ / email / phone regardless of formatting, stay
 * inside the tenant, and surface lost / do-not-contact / soft-deleted leads.
 */
class LeadMatcherTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $slug = 'lm'): Company
    {
        return Company::create([
            'name' => 'T '.$slug, 'slug' => $slug.'-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    public function test_normalisation(): void
    {
        $this->assertSame('123456789', LeadMatcher::normalizeAfm(' EL 123-456-789 '));
        $this->assertNull(LeadMatcher::normalizeAfm(''));
        $this->assertSame('a@b.gr', LeadMatcher::normalizeEmail('  A@B.GR '));
        $this->assertNull(LeadMatcher::normalizeEmail(null));
        $this->assertSame('302310123456', LeadMatcher::normalizePhone('+30 (2310) 123-456'));
        $this->assertNull(LeadMatcher::normalizePhone('   '));
    }

    public function test_empty_identity_matches_nothing(): void
    {
        $t = $this->tenant();
        Customer::create(['company_id' => $t->id, 'name' => 'K', 'afm' => '123456789']);

        $this->assertTrue(app(LeadMatcher::class)->find($t->id, null, '', [null, ''])->isEmpty());
    }

    public function test_a_placeholder_afm_is_no_identity_and_never_matches_everyone(): void
    {
        $t = $this->tenant();
        Customer::create(['company_id' => $t->id, 'name' => 'Άσχετος 1', 'afm' => '123456789']);
        Customer::create(['company_id' => $t->id, 'name' => 'Λιανική', 'afm' => '000000000']);
        Lead::create(['company_id' => $t->id, 'name' => 'Lead λιανικής', 'afm' => '000000000', 'status' => LeadStatus::DoNotContact, 'lost_reason' => 'x']);

        $match = app(LeadMatcher::class)->find($t->id, '000000000', null);

        $this->assertTrue($match->isEmpty(), 'An empty WHERE group must not turn into «every customer».');
        $this->assertTrue($match->directCustomers->isEmpty());
        $this->assertFalse($match->hasDoNotContact(), 'A placeholder cannot carry a DNC memory either.');
        $this->assertNull(LeadMatcher::normalizeAfm('999999999'));
    }

    public function test_foreign_vat_keeps_its_letters_on_both_sides(): void
    {
        $t = $this->tenant();
        $cy = Customer::create(['company_id' => $t->id, 'name' => 'Κύπριος', 'afm' => 'CY10259033P']);
        Customer::create(['company_id' => $t->id, 'name' => 'Ψηφία μόνο', 'afm' => '10259033']);
        $lead = Lead::create(['company_id' => $t->id, 'name' => 'Lead CY', 'afm' => 'CY10259033P']);

        $match = app(LeadMatcher::class)->find($t->id, 'cy 10259033 p', null, [], $lead->id);
        $this->assertSame([$cy->id], $match->customers->pluck('id')->all(), 'Never the digits-only customer.');
        $this->assertSame([$cy->id], $match->customersOwningAfm('CY10259033P')->pluck('id')->all());
    }

    public function test_matches_customer_by_afm_email_and_formatted_phone(): void
    {
        $t = $this->tenant();
        $byAfm = Customer::create(['company_id' => $t->id, 'name' => 'ΑΦΜ', 'afm' => '123456789']);
        $byEmail = Customer::create(['company_id' => $t->id, 'name' => 'Email', 'email' => 'Sales@Acme.GR']);
        $byPhone = Customer::create(['company_id' => $t->id, 'name' => 'Phone', 'phone2' => '2310 123-456']);
        Customer::create(['company_id' => $t->id, 'name' => 'Unrelated', 'afm' => '999999999']);

        $m = app(LeadMatcher::class);

        $this->assertSame([$byAfm->id], $m->find($t->id, '123456789', null)->customers->pluck('id')->all());
        $this->assertSame([$byEmail->id], $m->find($t->id, null, 'sales@acme.gr')->customers->pluck('id')->all());
        $this->assertSame([$byPhone->id], $m->find($t->id, null, null, ['+30 2310123456'])->customers->pluck('id')->all());
    }

    public function test_matches_customer_afm_stored_with_el_prefix(): void
    {
        $t = $this->tenant();
        $c = Customer::create(['company_id' => $t->id, 'name' => 'Με πρόθεμα', 'afm' => 'EL123456789']);
        $this->assertSame([$c->id], app(LeadMatcher::class)->find($t->id, '123456789', null)->customers->pluck('id')->all());

        // Same identity, another spelling (one customer per ΑΦΜ per tenant now).
        $c->update(['afm' => 'gr 123 456 789']);
        $this->assertSame([$c->id], app(LeadMatcher::class)->find($t->id, ' 123-456-789 ', null)->customers->pluck('id')->all());
    }

    public function test_matches_other_leads_including_lost_do_not_contact_and_trashed(): void
    {
        $t = $this->tenant();
        $lost = Lead::create(['company_id' => $t->id, 'name' => 'Παλιό', 'afm' => '123456789', 'status' => LeadStatus::Lost, 'lost_reason' => 'ακριβό']);
        $dnc = Lead::create(['company_id' => $t->id, 'name' => 'Ενοχλημένος', 'email' => 'no@thanks.gr', 'status' => LeadStatus::DoNotContact, 'lost_reason' => 'το ζήτησε']);
        $trashed = Lead::create(['company_id' => $t->id, 'name' => 'Σβησμένο', 'mobile' => '6971234567']);
        $trashed->delete();

        $m = app(LeadMatcher::class);

        $this->assertSame([$lost->id], $m->find($t->id, '123456789', null)->leads->pluck('id')->all());
        $this->assertFalse($m->find($t->id, '123456789', null)->hasDoNotContact());

        $dncMatch = $m->find($t->id, null, 'NO@thanks.gr');
        $this->assertSame([$dnc->id], $dncMatch->leads->pluck('id')->all());
        $this->assertTrue($dncMatch->hasDoNotContact());

        $this->assertSame([$trashed->id], $m->find($t->id, null, null, ['697 123 4567'])->leads->pluck('id')->all());
    }

    public function test_do_not_contact_is_found_behind_more_than_ten_newer_duplicates(): void
    {
        $t = $this->tenant();
        $dnc = Lead::create(['company_id' => $t->id, 'name' => 'Παλιό DNC', 'afm' => '123456789', 'status' => LeadStatus::DoNotContact, 'lost_reason' => 'το ζήτησε']);
        Lead::query()->whereKey($dnc->id)->update(['updated_at' => now()->subYear()]);

        for ($i = 1; $i <= 12; $i++) {
            Lead::create(['company_id' => $t->id, 'name' => "Νεότερο {$i}", 'afm' => '123456789']);
        }

        $match = app(LeadMatcher::class)->find($t->id, '123456789', null);

        $this->assertCount(LeadMatcher::PREVIEW_LIMIT, $match->leads, 'The preview is capped…');
        $this->assertFalse($match->leads->contains('id', $dnc->id), '…and the old DNC row is not in it…');
        $this->assertTrue($match->hasDoNotContact(), '…but the DNC flag still fires (unbounded exists).');
    }

    public function test_matches_trashed_customers_secondary_email_and_contacts(): void
    {
        $t = $this->tenant();

        $trashed = Customer::create(['company_id' => $t->id, 'name' => 'Διαγραμμένος', 'afm' => '123456789']);
        $trashed->delete();

        $secondary = Customer::create(['company_id' => $t->id, 'name' => 'Δεύτερο email', 'secondary_email' => 'Billing@Acme.gr']);

        $viaContact = Customer::create(['company_id' => $t->id, 'name' => 'Μέσω επαφής']);
        CustomerContact::create(['company_id' => $t->id, 'customer_id' => $viaContact->id, 'name' => 'Μαρία', 'email' => 'maria@acme.gr', 'phone' => '697 000 1111']);

        $m = app(LeadMatcher::class);

        $this->assertSame([$trashed->id], $m->find($t->id, '123456789', null)->customers->pluck('id')->all());
        $this->assertSame([$secondary->id], $m->find($t->id, null, 'billing@acme.gr')->customers->pluck('id')->all());
        $this->assertSame([$viaContact->id], $m->find($t->id, null, 'MARIA@acme.gr')->customers->pluck('id')->all());
        $this->assertSame([$viaContact->id], $m->find($t->id, null, null, ['+30 6970001111'])->customers->pluck('id')->all());
    }

    public function test_memo_is_invalidated_by_writes_in_the_same_process(): void
    {
        $t = $this->tenant();
        $m = app(LeadMatcher::class);

        $this->assertTrue($m->find($t->id, null, 'late@dnc.gr')->isEmpty());

        // A batch job creating a DNC lead after the first lookup must be seen
        // by the very next lookup (scoped memo + saved/deleted listeners).
        $dnc = Lead::create(['company_id' => $t->id, 'name' => 'Αργότερα', 'email' => 'late@dnc.gr', 'status' => LeadStatus::DoNotContact, 'lost_reason' => 'x']);
        $this->assertTrue($m->find($t->id, null, 'late@dnc.gr')->hasDoNotContact());

        $dnc->forceDelete();
        $this->assertFalse($m->find($t->id, null, 'late@dnc.gr')->hasDoNotContact());

        $this->assertSame([], $m->find($t->id, null, 'c@x.gr')->customers->all());
        Customer::create(['company_id' => $t->id, 'name' => 'Νέος', 'email' => 'c@x.gr']);
        $this->assertCount(1, $m->find($t->id, null, 'c@x.gr')->customers);
    }

    public function test_direct_set_and_dnc_agree_whether_the_preview_is_capped_or_not(): void
    {
        $t = $this->tenant();
        $m = app(LeadMatcher::class);

        // Uncapped: direct derived in PHP from the banner set. The trashed one
        // matches on its OWN phone (a trashed AFM twin can't exist any more).
        $live = Customer::create(['company_id' => $t->id, 'name' => 'Ζωντανός', 'afm' => 'EL 123456789']);
        $trashed = Customer::create(['company_id' => $t->id, 'name' => 'Σβησμένος', 'phone1' => '6970001111']);
        $trashed->delete();
        $viaContact = Customer::create(['company_id' => $t->id, 'name' => 'Μέσω επαφής']);
        CustomerContact::create(['company_id' => $t->id, 'customer_id' => $viaContact->id, 'name' => 'Μ', 'phone' => '6970001111']);

        $match = $m->find($t->id, '123456789', null, ['+30 697 000 1111']);
        $this->assertCount(3, $match->customers);
        $this->assertSame([$live->id], $match->directCustomers->pluck('id')->all());

        // Capped (>= PREVIEW_LIMIT banner rows): direct comes from its own query — same answer.
        for ($i = 1; $i <= LeadMatcher::PREVIEW_LIMIT; $i++) {
            CustomerContact::create(['company_id' => $t->id, 'customer_id' => Customer::create(['company_id' => $t->id, 'name' => "Επαφή {$i}"])->id, 'name' => 'Μ', 'phone' => '6970001111']);
        }
        $capped = $m->find($t->id, '123456789', null, ['+30 697 000 1111']);
        $this->assertCount(LeadMatcher::PREVIEW_LIMIT, $capped->customers);
        $this->assertSame([$live->id], $capped->directCustomers->pluck('id')->all());

        // DNC: uncapped preview decides from the rows; capped falls back to EXISTS (both true here).
        Lead::create(['company_id' => $t->id, 'name' => 'DNC', 'email' => 'a@b.gr', 'status' => LeadStatus::DoNotContact, 'lost_reason' => 'x']);
        $this->assertTrue($m->find($t->id, null, 'a@b.gr')->hasDoNotContact());
        for ($i = 1; $i <= LeadMatcher::PREVIEW_LIMIT; $i++) {
            Lead::create(['company_id' => $t->id, 'name' => "Νεότερο {$i}", 'email' => 'a@b.gr']);
        }
        $this->assertTrue($m->find($t->id, null, 'a@b.gr')->hasDoNotContact());
    }

    public function test_customers_owning_afm_helper(): void
    {
        $t = $this->tenant();
        $owner = Customer::create(['company_id' => $t->id, 'name' => 'Κάτοχος', 'afm' => 'EL123456789']);
        $byPhone = Customer::create(['company_id' => $t->id, 'name' => 'Μόνο τηλέφωνο', 'phone1' => '2310123456']);

        $match = app(LeadMatcher::class)->find($t->id, '123456789', null, ['2310123456']);

        $this->assertSame([$owner->id, $byPhone->id], $match->directCustomers->pluck('id')->sort()->values()->all());
        $this->assertSame([$owner->id], $match->customersOwningAfm(' 123-456-789')->pluck('id')->all());
        $this->assertTrue($match->customersOwningAfm('EL')->isEmpty(), 'A prefix-only ΑΦΜ never selects the no-ΑΦΜ hits.');
        $this->assertTrue($match->customersOwningAfm(null)->isEmpty());
    }

    public function test_ignores_the_lead_being_edited_and_other_tenants(): void
    {
        $t = $this->tenant('a');
        $other = $this->tenant('b');

        $self = Lead::create(['company_id' => $t->id, 'name' => 'Εγώ', 'afm' => '123456789']);
        Lead::create(['company_id' => $other->id, 'name' => 'Άλλη εταιρεία', 'afm' => '123456789']);
        Customer::create(['company_id' => $other->id, 'name' => 'Άλλης εταιρείας', 'afm' => '123456789']);

        $match = app(LeadMatcher::class)->find($t->id, '123456789', null, [], ignoreLeadId: $self->id);

        $this->assertTrue($match->isEmpty(), 'Own row and other tenants must never match.');
    }
}
