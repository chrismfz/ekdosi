<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fresh-install convenience: creating a Greek/myDATA tenant through the UI
 * pre-installs the standard AADE lookups (VAT categories + by-the-book
 * classified invoice types). Non-Greek tenants are left clean.
 */
class CompanyAutoSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Admin', 'email' => 'a-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]));

        // CreateCompany redirects to the tenant-scoped companies.index after
        // save, so the panel needs a current tenant. This host tenant is made
        // via Company::create (not the page), so it is NOT itself auto-seeded.
        Filament::setTenant(Company::create([
            'name' => 'Host', 'slug' => 'host-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]));
    }

    public function test_creating_a_gr_tenant_preinstalls_classified_lookups(): void
    {
        $slug = 'gr-'.uniqid();

        Livewire::test(CreateCompany::class)
            ->fillForm([
                'name' => 'Νέα ΑΕ',
                'slug' => $slug,
                'country_code' => 'GR',
                'einvoice_provider' => 'gr-mydata',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // Scope-free reads: a current tenant is set, so the BelongsToCompany
        // global scope would otherwise filter these to the host tenant.
        $company = Company::query()->withoutGlobalScopes()->where('slug', $slug)->firstOrFail();

        $this->assertGreaterThan(0, DB::table('vat_categories')->where('company_id', $company->id)->count());

        $tpy = DB::table('invoice_types')->where('company_id', $company->id)->where('code', 'ΤΠΥ')->first();
        $this->assertNotNull($tpy);
        $this->assertSame('2.1', $tpy->mydata_type);
        $this->assertSame('E3_561_001', $tpy->mydata_income_class);
        $this->assertSame('category1_3', $tpy->mydata_income_class_category);

        // The full lookup set is installed too.
        $this->assertSame(8, DB::table('payment_methods')->where('company_id', $company->id)->count());
        $this->assertGreaterThan(0, DB::table('distribution_aims')->where('company_id', $company->id)->count());
        $this->assertGreaterThan(0, DB::table('metric_units')->where('company_id', $company->id)->count());
        $this->assertGreaterThan(0, DB::table('delivery_methods')->where('company_id', $company->id)->count());
        $this->assertGreaterThan(0, DB::table('product_categories')->where('company_id', $company->id)->count());
    }

    public function test_creating_a_non_gr_tenant_installs_no_greek_lookups(): void
    {
        $slug = 'ee-'.uniqid();

        Livewire::test(CreateCompany::class)
            ->fillForm([
                'name' => 'EE OÜ',
                'slug' => $slug,
                'country_code' => 'EE',
                'einvoice_provider' => 'ee-peppol',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $company = Company::query()->withoutGlobalScopes()->where('slug', $slug)->firstOrFail();

        $this->assertSame(0, DB::table('invoice_types')->where('company_id', $company->id)->count());
        $this->assertSame(0, DB::table('vat_categories')->where('company_id', $company->id)->count());
        $this->assertSame(0, DB::table('payment_methods')->where('company_id', $company->id)->count());
        $this->assertSame(0, DB::table('metric_units')->where('company_id', $company->id)->count());
    }
}
