<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Models\Company;
use App\Models\User;
use App\Services\MyData\MyDataLookupSeeder;
use App\Support\Tenancy\CompanyContext;
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
                'send_channel' => 'mydata-off',
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
                'send_channel' => 'peppol',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $company = Company::query()->withoutGlobalScopes()->where('slug', $slug)->firstOrFail();

        $this->assertSame(0, DB::table('invoice_types')->where('company_id', $company->id)->count());
        $this->assertSame(0, DB::table('vat_categories')->where('company_id', $company->id)->count());
        $this->assertSame(0, DB::table('payment_methods')->where('company_id', $company->id)->count());
        $this->assertSame(0, DB::table('metric_units')->where('company_id', $company->id)->count());
    }

    public function test_switching_a_tenant_to_greek_seeds_the_standard_lookups(): void
    {
        // SET-5: a tenant created as «none»/PEPPOL starts with EMPTY lookups
        // (created outside the page, so afterCreate never seeded it).
        $company = Company::query()->withoutGlobalScopes()->create([
            'name' => 'Switcher', 'slug' => 'sw-'.uniqid(),
            'country_code' => 'EE', 'einvoice_provider' => 'none', 'mydata_mode' => 'off',
        ]);
        $this->assertSame(0, DB::table('vat_categories')->where('company_id', $company->id)->count());

        // Flip the provider to Greek filing through the edit page → seeded.
        Livewire::test(EditCompany::class, ['record' => $company->getRouteKey()])
            ->fillForm([
                'country_code' => 'GR',
                'send_channel' => 'mydata-off',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $company->refresh();
        $this->assertSame('gr-mydata', $company->einvoice_provider);
        $this->assertGreaterThan(0, DB::table('vat_categories')->where('company_id', $company->id)->count());
        $this->assertGreaterThan(0, DB::table('invoice_types')->where('company_id', $company->id)->count());
        $this->assertGreaterThan(0, DB::table('payment_methods')->where('company_id', $company->id)->count());
    }

    public function test_reseed_on_switch_is_idempotent_even_under_a_different_ambient_tenant(): void
    {
        // Regression: CompanyResource is NOT tenant-scoped, so a super_admin can
        // edit company B while the panel context (CompanyContext) is pinned to a
        // DIFFERENT tenant A. The re-seed must run against B, not intersect the
        // scope with A — otherwise every idempotency probe (`where(company_id,B)`
        // AND scope `company_id=A`) is false → duplicate VAT rows + an
        // invoice_types unique-key throw. afterSave now wraps the seed in
        // actAs($record) to keep the scope honest.
        $b = Company::query()->withoutGlobalScopes()->create([
            'name' => 'Beta', 'slug' => 'b-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'none', 'mydata_mode' => 'off',
        ]);
        // B ALREADY has the full lookup set (e.g. ETL-imported), so the switch is
        // a genuine RE-seed, not a first seed.
        app(CompanyContext::class)->actAs($b, fn () => app(MyDataLookupSeeder::class)->seedStandardLookups($b));
        $vatBefore = DB::table('vat_categories')->where('company_id', $b->id)->count();
        $typesBefore = DB::table('invoice_types')->where('company_id', $b->id)->count();
        $this->assertGreaterThan(0, $vatBefore);

        // Pin the ambient tenant to a DIFFERENT company (A) — the mismatch.
        $a = Company::query()->withoutGlobalScopes()->create([
            'name' => 'Alpha', 'slug' => 'a-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        app(CompanyContext::class)->set($a);

        Livewire::test(EditCompany::class, ['record' => $b->getRouteKey()])
            ->fillForm(['country_code' => 'GR', 'send_channel' => 'mydata-off'])
            ->call('save')
            ->assertHasNoFormErrors();

        // No duplicates, no throw: the re-seed ran idempotently against B.
        $this->assertSame($vatBefore, DB::table('vat_categories')->where('company_id', $b->id)->count());
        $this->assertSame($typesBefore, DB::table('invoice_types')->where('company_id', $b->id)->count());
    }

    public function test_editing_a_greek_tenant_without_changing_provider_does_not_reseed(): void
    {
        // A provider-unrelated edit (e.g. renaming) must NOT trigger the seeder —
        // wasChanged('einvoice_provider') gates it. The host tenant is gr-mydata
        // but was made via Company::create, so it has no lookups to begin with.
        $company = Company::query()->withoutGlobalScopes()->create([
            'name' => 'Greek Co', 'slug' => 'grc-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);

        Livewire::test(EditCompany::class, ['record' => $company->getRouteKey()])
            ->fillForm(['name' => 'Greek Co Renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        // No provider change → no seeding.
        $this->assertSame(0, DB::table('vat_categories')->where('company_id', $company->id)->count());
    }
}
