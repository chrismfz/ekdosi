<?php

namespace Tests\Feature\Portability;

use App\Enums\LeadActivityType;
use App\Enums\LeadStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Quote;
use App\Services\Portability\CompanyExporter;
use App\Services\Portability\CompanyImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Leads in the --full bundle: the timeline follows its lead, quotes keep their
 * lead link, the converted-customer link is rewired, and — the point of the
 * external-review finding — a SOFT-DELETED lead stays deleted after a restore
 * (it was kept only so «μην ξαναενοχλήσετε» keeps matching).
 */
class LeadsBundleTest extends TestCase
{
    use RefreshDatabase;

    public function test_leads_round_trip_and_soft_deleted_rows_stay_deleted(): void
    {
        $src = Company::create([
            'name' => 'Leads OE', 'slug' => 'leads', 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'afm' => '800561849',
        ]);

        $customer = Customer::create(['company_id' => $src->id, 'name' => 'Πελάτης από lead', 'afm' => '801280908']);
        $won = Lead::create(['company_id' => $src->id, 'name' => 'Κερδισμένο', 'status' => LeadStatus::Won, 'converted_customer_id' => $customer->id]);
        LeadActivity::create(['company_id' => $src->id, 'lead_id' => $won->id, 'type' => LeadActivityType::Call, 'outcome' => 'answered', 'happened_at' => now()->subDay(), 'body' => 'μιλήσαμε']);
        Quote::create(['company_id' => $src->id, 'lead_id' => $won->id, 'code' => 'ΠΡ-1']);

        $dnc = Lead::create(['company_id' => $src->id, 'name' => 'Σβησμένο DNC', 'email' => 'no@thanks.gr', 'status' => LeadStatus::DoNotContact, 'lost_reason' => 'το ζήτησε']);
        $dnc->delete();

        $deletedCustomer = Customer::create(['company_id' => $src->id, 'name' => 'Διαγραμμένος πελάτης', 'afm' => '999999999']);
        $deletedCustomer->delete();

        $bundle = app(CompanyExporter::class)->build($src, 'passphrase', 'p@ss', true);

        Company::where('slug', 'leads')->update(['slug' => 'leads-src', 'afm' => '000000000']);
        app(CompanyImporter::class)->run($bundle, ['new' => true, 'execute' => true, 'passphrase' => 'p@ss']);

        $company = Company::where('slug', 'leads')->firstOrFail();

        $newCustomer = Customer::where('company_id', $company->id)->where('afm', '801280908')->firstOrFail();
        $newWon = Lead::where('company_id', $company->id)->where('name', 'Κερδισμένο')->firstOrFail();
        $this->assertSame($newCustomer->id, $newWon->converted_customer_id, 'converted_customer_id rewired to the NEW customer');
        $this->assertSame($newWon->id, $newCustomer->originLead?->id);

        $activity = LeadActivity::where('company_id', $company->id)->firstOrFail();
        $this->assertSame($newWon->id, $activity->lead_id, 'timeline follows its lead');
        $this->assertSame('μιλήσαμε', $activity->body);

        $quote = Quote::where('company_id', $company->id)->where('code', 'ΠΡ-1')->firstOrFail();
        $this->assertSame($newWon->id, $quote->lead_id, 'quotes.lead_id rewired');

        // The soft-deleted rows came across AND stayed deleted.
        $this->assertNull(Lead::where('company_id', $company->id)->where('name', 'Σβησμένο DNC')->first(), 'not resurrected');
        $newDnc = Lead::withTrashed()->where('company_id', $company->id)->where('name', 'Σβησμένο DNC')->firstOrFail();
        $this->assertTrue($newDnc->trashed());
        $this->assertSame(LeadStatus::DoNotContact, $newDnc->status);

        $this->assertNull(Customer::where('company_id', $company->id)->where('afm', '999999999')->first());
        $this->assertTrue(Customer::withTrashed()->where('company_id', $company->id)->where('afm', '999999999')->firstOrFail()->trashed());
    }
}
