<?php

namespace Tests\Feature\Portability;

use App\Models\BankAccount;
use App\Models\Company;
use App\Models\DistributionAim;
use App\Models\DomainRegistrarConnection;
use App\Models\InvoiceType;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentMethod;
use App\Models\VatCategory;
use App\Models\WhmcsPaymentMap;
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
        $pm = PaymentMethod::create(['company_id' => $company->id, 'description' => 'Μετρητά', 'due_days' => 0]);
        // WHMCS gateway map with a payment_method_id FK to rewire on import.
        WhmcsPaymentMap::create(['company_id' => $company->id, 'whmcs_gateway' => 'stripe', 'payment_method_id' => $pm->id]);
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

        // The WHMCS gateway map's payment_method_id FK was rewired to the NEW
        // tenant's payment method (not the source's stale id).
        $pm = PaymentMethod::where('company_id', $company->id)->firstOrFail();
        $map = DB::table('whmcs_payment_maps')->where('company_id', $company->id)->where('whmcs_gateway', 'stripe')->first();
        $this->assertNotNull($map);
        $this->assertSame($pm->id, (int) $map->payment_method_id);
    }

    public function test_payment_gateway_connections_travel_with_sealed_secrets_and_rewired_bank_ids(): void
    {
        // Devbox → production: the operator wants the payment methods (Eurobank mid
        // + Shared Secret, and the manual bank-deposit) to LAND configured, secrets
        // and all — nothing re-typed. The secret must ride passphrase-sealed (not
        // raw APP_KEY ciphertext), and the manual gateway's bank_account_ids must
        // rewire to the target's freshly imported bank accounts.
        $company = $this->sourceCompany();
        $bank = BankAccount::create([
            'company_id' => $company->id, 'bank_name' => 'Eurobank', 'iban' => 'GR-IBAN-1',
            'account_name' => 'Δικαιούχος', 'is_active' => true,
        ]);
        // The channel's auto-«Τρόπος» (myDATA method) must also survive — rewired to
        // the target's imported payment_methods, not the stale source id.
        $method = PaymentMethod::create([
            'company_id' => $company->id, 'description' => 'Ηλεκτρονικά μέσα Πληρωμών', 'due_days' => 0,
        ]);
        PaymentGatewayConnection::create([
            'company_id' => $company->id, 'gateway' => 'eurobank', 'label' => 'Κάρτα',
            'is_active' => true, 'sort' => 0, 'payment_method_id' => $method->id,
            'config' => ['merchant_id' => 'MID999', 'shared_secret' => 'TOP-SECRET-XYZ', 'lang' => 'el', 'testmode' => false],
        ]);
        PaymentGatewayConnection::create([
            'company_id' => $company->id, 'gateway' => 'manual', 'label' => 'Κατάθεση',
            'is_active' => true, 'sort' => 1,
            'config' => ['bank_account_ids' => [$bank->id], 'instructions' => 'ref = αριθμός'],
        ]);

        $bundle = $this->bundle($company);

        // The secret is SEALED — not in the clear anywhere in the connections blob.
        $this->assertCount(2, $bundle['connections']['rows']);
        $this->assertSame('passphrase', $bundle['connections']['secrets']['mode']);
        $this->assertStringNotContainsString('TOP-SECRET-XYZ', json_encode($bundle['connections']['secrets']));

        // Simulate the target VM (fresh slug + fresh bank-account id).
        Company::where('slug', 'src')->forceDelete();
        app(CompanyImporter::class)->run($bundle, ['new' => true, 'execute' => true, 'passphrase' => 'p@ss']);

        $target = Company::where('slug', 'src')->firstOrFail();
        $eb = PaymentGatewayConnection::where('company_id', $target->id)->where('gateway', 'eurobank')->firstOrFail();
        // The secret survived + decrypts under the TARGET's APP_KEY.
        $this->assertSame('MID999', $eb->config['merchant_id']);
        $this->assertSame('TOP-SECRET-XYZ', $eb->config['shared_secret']);
        $this->assertTrue((bool) $eb->is_active);
        // The myDATA-method FK rewired to the target's own imported payment_method.
        $targetMethod = PaymentMethod::where('company_id', $target->id)
            ->where('description', 'Ηλεκτρονικά μέσα Πληρωμών')->firstOrFail();
        $this->assertSame($targetMethod->id, $eb->payment_method_id);

        $newBank = BankAccount::where('company_id', $target->id)->firstOrFail();
        $manual = PaymentGatewayConnection::where('company_id', $target->id)->where('gateway', 'manual')->firstOrFail();
        // bank_account_ids rewired old→new (not the stale source id).
        $this->assertSame([$newBank->id], $manual->config['bank_account_ids']);
    }

    public function test_domain_registrar_connections_travel_with_sealed_secrets(): void
    {
        // Πυλώνας A / A2c-3: the registrar accounts (encrypted API creds) ride
        // the SAME sealed machinery as the payment gateways — devbox →
        // production lands them configured, nothing re-typed, and the secret
        // never travels as raw APP_KEY ciphertext.
        $company = $this->sourceCompany();
        DomainRegistrarConnection::create([
            'company_id' => $company->id, 'registrar' => 'openprovider', 'label' => 'OP MyIP',
            'is_active' => true, 'mode' => 'production',
            'config' => ['username' => 'myip', 'password' => 'OP-TOP-SECRET'],
        ]);
        DomainRegistrarConnection::create([
            'company_id' => $company->id, 'registrar' => 'manual', 'label' => 'Χειροκίνητα',
            'is_active' => true, 'mode' => 'off', 'config' => [],
        ]);
        // A trashed connection is a tombstone, not config — must NOT travel.
        DomainRegistrarConnection::create([
            'company_id' => $company->id, 'registrar' => 'openprovider', 'label' => 'Παλιό',
            'is_active' => false, 'mode' => 'off', 'config' => ['username' => 'old', 'password' => 'x'],
        ])->delete();

        $bundle = $this->bundle($company);

        $this->assertCount(2, $bundle['domain_connections']['rows']);
        $this->assertSame('passphrase', $bundle['domain_connections']['secrets']['mode']);
        $this->assertStringNotContainsString('OP-TOP-SECRET', json_encode($bundle['domain_connections']));
        $this->assertSame(2, $bundle['manifest']['counts']['domain_registrar_connections']);

        // Simulate the target VM.
        Company::where('slug', 'src')->forceDelete();
        app(CompanyImporter::class)->run($bundle, ['new' => true, 'execute' => true, 'passphrase' => 'p@ss']);

        $target = Company::where('slug', 'src')->firstOrFail();
        $op = DomainRegistrarConnection::where('company_id', $target->id)
            ->where('registrar', 'openprovider')->firstOrFail();
        // The creds survived + decrypt under the TARGET's APP_KEY; mode carried.
        $this->assertSame('myip', $op->config['username']);
        $this->assertSame('OP-TOP-SECRET', $op->config['password']);
        $this->assertSame('production', $op->mode);
        $this->assertTrue((bool) $op->is_active);
        $this->assertSame('OP MyIP', $op->label);

        // Exactly the two live rows — the tombstone did not travel.
        $this->assertSame(2, DomainRegistrarConnection::where('company_id', $target->id)->count());
        $this->assertNull(DomainRegistrarConnection::where('company_id', $target->id)->where('label', 'Παλιό')->first());

        // Idempotent: a re-import updates in place, never duplicates.
        app(CompanyImporter::class)->run($bundle, ['execute' => true, 'passphrase' => 'p@ss']);
        $this->assertSame(2, DomainRegistrarConnection::where('company_id', $target->id)->count());
    }

    public function test_two_domain_connections_sharing_registrar_and_label_both_survive(): void
    {
        // Two accounts at the SAME registrar with the same label — the
        // consumed-tracking must land BOTH (the gateways' idiom).
        $company = $this->sourceCompany();
        DomainRegistrarConnection::create([
            'company_id' => $company->id, 'registrar' => 'openprovider', 'label' => null,
            'is_active' => true, 'mode' => 'production', 'config' => ['username' => 'a', 'password' => 'pw-a'],
        ]);
        DomainRegistrarConnection::create([
            'company_id' => $company->id, 'registrar' => 'openprovider', 'label' => null,
            'is_active' => false, 'mode' => 'sandbox', 'config' => ['username' => 'b', 'password' => 'pw-b'],
        ]);

        $bundle = $this->bundle($company);
        Company::where('slug', 'src')->forceDelete();
        app(CompanyImporter::class)->run($bundle, ['new' => true, 'execute' => true, 'passphrase' => 'p@ss']);

        $target = Company::where('slug', 'src')->firstOrFail();
        $rows = DomainRegistrarConnection::where('company_id', $target->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['a', 'b'], $rows->map(fn ($r) => $r->config['username'])->all());
        $this->assertSame(['pw-a', 'pw-b'], $rows->map(fn ($r) => $r->config['password'])->all());
    }

    public function test_two_connections_sharing_gateway_and_label_both_survive(): void
    {
        // A tenant with TWO eurobank methods labelled the same (or two manual with a
        // null label) must NOT collapse to one on import — each secret is distinct.
        $company = $this->sourceCompany();
        PaymentGatewayConnection::create([
            'company_id' => $company->id, 'gateway' => 'eurobank', 'label' => 'Κάρτα',
            'is_active' => true, 'sort' => 0, 'config' => ['merchant_id' => 'MID-A', 'shared_secret' => 'SEC-A'],
        ]);
        PaymentGatewayConnection::create([
            'company_id' => $company->id, 'gateway' => 'eurobank', 'label' => 'Κάρτα',
            'is_active' => true, 'sort' => 1, 'config' => ['merchant_id' => 'MID-B', 'shared_secret' => 'SEC-B'],
        ]);

        $bundle = $this->bundle($company);
        Company::where('slug', 'src')->forceDelete();
        $importer = app(CompanyImporter::class);
        $importer->run($bundle, ['new' => true, 'execute' => true, 'passphrase' => 'p@ss']);
        // Re-import into the same company → still exactly two (idempotent, no dupes).
        $importer->run($bundle, ['into' => 'src', 'execute' => true, 'passphrase' => 'p@ss']);

        $target = Company::where('slug', 'src')->firstOrFail();
        $conns = PaymentGatewayConnection::where('company_id', $target->id)->where('gateway', 'eurobank')->orderBy('sort')->get();
        $this->assertCount(2, $conns);
        $this->assertSame(['MID-A', 'MID-B'], $conns->pluck('config.merchant_id')->all());
        $this->assertSame(['SEC-A', 'SEC-B'], $conns->pluck('config.shared_secret')->all());
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
        // whmcs_payment_maps matched by (company, gateway) → no unique-collision on re-import.
        $this->assertSame(1, DB::table('whmcs_payment_maps')->where('company_id', $source->id)->count());
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

    public function test_import_into_heals_a_roles_less_company(): void
    {
        $company = $this->sourceCompany();
        $bundle = $this->bundle($company);
        // Simulate a company that has lost its roles.
        DB::table('roles')->where('company_id', $company->id)->delete();
        $this->assertSame(0, DB::table('roles')->where('company_id', $company->id)->count());

        app(CompanyImporter::class)->run($bundle, [
            'into' => 'src', 'execute' => true, 'passphrase' => 'p@ss',
        ]);

        // --into re-creates the managed role ROWS (rows-only heal, no clobber).
        $this->assertTrue(DB::table('roles')->where('company_id', $company->id)
            ->where('name', 'super_admin')->exists());
        $this->assertGreaterThanOrEqual(3, DB::table('roles')->where('company_id', $company->id)->count());
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
