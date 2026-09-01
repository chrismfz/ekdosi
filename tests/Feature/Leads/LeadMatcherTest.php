<?php

namespace Tests\Feature\Leads;

use App\Enums\LeadStatus;
use App\Models\Company;
use App\Models\Customer;
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
        $lower = Customer::create(['company_id' => $t->id, 'name' => 'Με μικρό πρόθεμα', 'afm' => 'gr 123 456 789']);

        $ids = app(LeadMatcher::class)->find($t->id, '123456789', null)->customers->pluck('id')->sort()->values()->all();

        $this->assertSame([$c->id, $lower->id], $ids);
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
