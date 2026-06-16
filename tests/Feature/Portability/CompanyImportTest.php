<?php

namespace Tests\Feature\Portability;

use App\Models\Company;
use App\Models\DistributionAim;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\VatCategory;
use App\Services\Portability\CompanyExporter;
use App\Services\Portability\CompanyImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class CompanyImportTest extends TestCase
{
    use RefreshDatabase;

    private function sourceCompany(): Company
    {
        $company = Company::create([
            'name' => 'Source OE', 'slug' => 'src', 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'afm' => '800561849',
            'mydata_aade_id_production' => '800561849M',
            'mydata_subscription_key_production' => 'PRODKEY',
            'gsis_password' => 'gsis-pw',
        ]);
        VatCategory::create(['company_id' => $company->id, 'description' => 'ΦΠΑ 24%', 'rate' => 24, 'is_default' => true]);
        PaymentMethod::create(['company_id' => $company->id, 'description' => 'Μετρητά', 'due_days' => 0]);
        $aim = DistributionAim::create(['company_id' => $company->id, 'description' => 'Πώληση']);
        InvoiceType::create([
            'company_id' => $company->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 7,
            'mydata_type' => '2.1', 'mydata_income_class' => 'E3_561_001',
            'distribution_aim_id' => $aim->id,   // intra-setup FK to rewire
        ]);

        return $company->fresh();
    }

    private function bundle(Company $company): array
    {
        return app(CompanyExporter::class)->build($company, 'passphrase', 'p@ss');
    }

    public function test_import_new_recreates_settings_setup_and_rewires_fks(): void
    {
        $bundle = $this->bundle($this->sourceCompany());

        // Simulate another VM: drop the source so the slug is free.
        Company::where('slug', 'src')->forceDelete();

        $summary = app(CompanyImporter::class)->run($bundle, [
            'new' => true, 'execute' => true, 'passphrase' => 'p@ss',
        ]);

        $this->assertSame('create', $summary['company']);

        $company = Company::where('slug', 'src')->firstOrFail();
        // Settings + secrets (re-encrypted under this APP_KEY, decrypt to plaintext).
        $this->assertSame('800561849M', $company->mydata_aade_id_production);
        $this->assertSame('PRODKEY', $company->mydata_subscription_key_production);
        $this->assertSame('gsis-pw', $company->gsis_password);

        // Setup restored with the myDATA mapping + counter preserved.
        $type = InvoiceType::where('company_id', $company->id)->where('code', 'TPY')->firstOrFail();
        $this->assertSame('E3_561_001', $type->mydata_income_class);
        $this->assertSame(7, $type->invcount);

        // The intra-setup FK was rewired to the NEW distribution_aim id (not the source's).
        $aim = DistributionAim::where('company_id', $company->id)->where('description', 'Πώληση')->firstOrFail();
        $this->assertSame($aim->id, $type->distribution_aim_id);

        $this->assertSame(1, VatCategory::where('company_id', $company->id)->count());
        $this->assertSame(1, PaymentMethod::where('company_id', $company->id)->count());
    }

    public function test_reimport_into_is_idempotent_and_updates_in_place(): void
    {
        $source = $this->sourceCompany();
        $bundle = $this->bundle($source);

        // Re-import the bundle back INTO the same (existing) company.
        $importer = app(CompanyImporter::class);
        $importer->run($bundle, ['into' => 'src', 'execute' => true, 'passphrase' => 'p@ss']);
        $importer->run($bundle, ['into' => 'src', 'execute' => true, 'passphrase' => 'p@ss']);

        // No duplicate setup rows — matched by natural key and updated in place.
        $this->assertSame(1, InvoiceType::where('company_id', $source->id)->where('code', 'TPY')->count());
        $this->assertSame(1, VatCategory::where('company_id', $source->id)->count());
        $this->assertSame(1, PaymentMethod::where('company_id', $source->id)->count());
        $this->assertSame(1, DistributionAim::where('company_id', $source->id)->count());
    }

    public function test_multiple_billing_connections_same_source_survive(): void
    {
        $source = $this->sourceCompany();
        // Two connections of the SAME source, disambiguated by label.
        DB::table('billing_connections')->insert([
            ['company_id' => $source->id, 'source' => 'whmcs', 'label' => 'Shop A', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => $source->id, 'source' => 'whmcs', 'label' => 'Shop B', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $bundle = $this->bundle($source->fresh());

        // Re-import into the same company twice → both rows kept, not collapsed.
        $importer = app(CompanyImporter::class);
        $importer->run($bundle, ['into' => 'src', 'execute' => true, 'passphrase' => 'p@ss']);
        $importer->run($bundle, ['into' => 'src', 'execute' => true, 'passphrase' => 'p@ss']);

        $this->assertSame(2, DB::table('billing_connections')->where('company_id', $source->id)->count());
    }

    public function test_key_less_row_does_not_duplicate_on_reimport(): void
    {
        $source = $this->sourceCompany();
        // A lookup row with a NULL natural key (no name) → signature fallback.
        DB::table('metric_units')->insert([
            'company_id' => $source->id, 'name' => null, 'notes' => 'kg',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $bundle = $this->bundle($source->fresh());

        $importer = app(CompanyImporter::class);
        $importer->run($bundle, ['into' => 'src', 'execute' => true, 'passphrase' => 'p@ss']);
        $importer->run($bundle, ['into' => 'src', 'execute' => true, 'passphrase' => 'p@ss']);

        $this->assertSame(1, DB::table('metric_units')->where('company_id', $source->id)->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $bundle = $this->bundle($this->sourceCompany());
        Company::where('slug', 'src')->forceDelete();

        $summary = app(CompanyImporter::class)->run($bundle, [
            'new' => true, 'execute' => false, 'passphrase' => 'p@ss',
        ]);

        $this->assertTrue($summary['dry_run']);
        $this->assertArrayHasKey('invoice_types', $summary['tables']);
        $this->assertSame(0, Company::where('slug', 'src')->count());
    }

    public function test_new_refuses_when_slug_exists(): void
    {
        $bundle = $this->bundle($this->sourceCompany());

        $this->expectException(RuntimeException::class);
        app(CompanyImporter::class)->run($bundle, ['new' => true, 'execute' => true, 'passphrase' => 'p@ss']);
    }

    public function test_wrong_passphrase_aborts_before_writing(): void
    {
        $bundle = $this->bundle($this->sourceCompany());
        Company::where('slug', 'src')->forceDelete();

        try {
            app(CompanyImporter::class)->run($bundle, ['new' => true, 'execute' => true, 'passphrase' => 'WRONG']);
            $this->fail('expected a decrypt failure');
        } catch (\Throwable) {
            // nothing created
        }
        $this->assertSame(0, Company::where('slug', 'src')->count());
    }

    /**
     * The «χωρίς υποχρεωτικό κωδικό» path: a raw (no-passphrase) export imports
     * with NO passphrase and still restores the secrets in clear (then they get
     * re-encrypted under the target VM's APP_KEY on save). Mirrors the new
     * UI/CLI raw mode end-to-end.
     */
    public function test_raw_bundle_imports_without_a_passphrase(): void
    {
        $bundle = app(CompanyExporter::class)->build($this->sourceCompany(), 'raw', null);
        $this->assertSame('raw', $bundle['secrets']['mode']);

        Company::where('slug', 'src')->forceDelete();

        $summary = app(CompanyImporter::class)->run($bundle, [
            'new' => true, 'execute' => true, 'passphrase' => null,
        ]);
        $this->assertSame('create', $summary['company']);

        $company = Company::where('slug', 'src')->firstOrFail();
        $this->assertSame('PRODKEY', $company->mydata_subscription_key_production);
        $this->assertSame('gsis-pw', $company->gsis_password);
        $this->assertSame(7, InvoiceType::where('company_id', $company->id)->where('code', 'TPY')->firstOrFail()->invcount);
    }

    public function test_import_into_existing_does_not_touch_its_roles(): void
    {
        $company = $this->sourceCompany();
        $bundle = $this->bundle($company);
        $before = DB::table('roles')->where('company_id', $company->id)->orderBy('id')->pluck('id')->all();
        $this->assertNotEmpty($before);   // observer provisioned roles on create

        // --into an EXISTING company must NOT re-provision/re-sync roles (would
        // clobber any manual per-tenant permission customization).
        app(CompanyImporter::class)->run($bundle, [
            'into' => 'src', 'execute' => true, 'passphrase' => 'p@ss',
        ]);

        $after = DB::table('roles')->where('company_id', $company->id)->orderBy('id')->pluck('id')->all();
        $this->assertSame($before, $after);
    }

    public function test_import_new_provisions_roles_after_commit(): void
    {
        $bundle = $this->bundle($this->sourceCompany());
        Company::where('slug', 'src')->forceDelete();

        app(CompanyImporter::class)->run($bundle, [
            'new' => true, 'execute' => true, 'passphrase' => 'p@ss',
        ]);

        $company = Company::where('slug', 'src')->firstOrFail();
        $this->assertSame(1, DB::table('roles')
            ->where('company_id', $company->id)->where('name', 'super_admin')->count());
    }

    /**
     * Regression: the company row is exported via attributesToArray(), which can
     * carry non-column aggregates (e.g. users_count from the Companies list's
     * withCount). The export must strip them so the bundle stays column-clean.
     */
    public function test_export_strips_non_column_aggregate_attributes(): void
    {
        $company = $this->sourceCompany();
        $company->setAttribute('users_count', 5);   // as a withCount() list query would

        $bundle = $this->bundle($company);

        $this->assertArrayNotHasKey('users_count', $bundle['company']);
    }

    /**
     * Regression: an OLDER bundle (built before the export fix) may carry a stray
     * non-column attribute in its company payload. Import must tolerate it instead
     * of dying with SQLSTATE 42S22 «Unknown column 'users_count'».
     */
    public function test_import_tolerates_stray_non_column_in_company_payload(): void
    {
        $bundle = $this->bundle($this->sourceCompany());
        Company::where('slug', 'src')->forceDelete();

        $bundle['company']['users_count'] = 9;   // simulate a pre-fix bundle

        Log::spy();

        $summary = app(CompanyImporter::class)->run($bundle, [
            'new' => true, 'execute' => true, 'passphrase' => 'p@ss',
        ]);

        $this->assertSame('create', $summary['company']);
        $this->assertNotNull(Company::where('slug', 'src')->first());

        // (a) the drop is surfaced (visibility for a real schema skew).
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $msg, array $ctx = []): bool => str_contains($msg, 'dropped non-column')
                && in_array('users_count', $ctx['dropped'] ?? [], true))
            ->once();
    }
}
