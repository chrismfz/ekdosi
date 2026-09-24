<?php

namespace Tests\Feature\Portability;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DistributionAim;
use App\Models\InvoiceType;
use App\Models\User;
use App\Services\Portability\CompanyExporter;
use App\Services\Portability\CompanyImporter;
use App\Services\TenantRoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Completeness of the company bundle beyond the plain `companies` row + setup
 * tables: (1) the assigned OPERATORS (company_user pivot + their managed role),
 * carried without any password; (2) the WHMCS default RECEIPT/UNPAID type FKs,
 * rewired through the invoice_types map like the invoice-type default; and
 * (3) secrets surviving a genuine APP_KEY change between export and import.
 */
class CompanyBundleCompletenessTest extends TestCase
{
    use RefreshDatabase;

    private function sourceCompany(): Company
    {
        $company = Company::create([
            'name' => 'Source OE', 'slug' => 'src', 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'afm' => '800561849',
            'mydata_subscription_key_production' => 'PRODKEY',
            'gsis_password' => 'gsis-pw',
        ]);
        DistributionAim::create(['company_id' => $company->id, 'description' => 'Πώληση']);

        return $company->fresh();
    }

    private function assignUser(Company $c, string $email, string $name, ?string $role): User
    {
        $u = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('src-secret')]);
        $u->companies()->syncWithoutDetaching([$c->id]);
        if ($role !== null) {
            app(TenantRoleProvisioner::class)->setRoleInCompany($u, $c, $role);
        }

        return $u;
    }

    private function bundle(Company $company): array
    {
        return app(CompanyExporter::class)->build($company, 'passphrase', 'p@ss');
    }

    // ── (1) operators ───────────────────────────────────────────────────────

    public function test_export_carries_assigned_operators_with_role_and_no_password(): void
    {
        $company = $this->sourceCompany();
        $this->assignUser($company, 'admin@src.test', 'Admin One', TenantRoleProvisioner::ROLE_COMPANY_ADMIN);
        $this->assignUser($company, 'op@src.test', 'Operator Two', TenantRoleProvisioner::ROLE_OPERATOR);

        $users = $this->bundle($company)['users'];

        $byEmail = collect($users)->keyBy('email');
        $this->assertCount(2, $users);
        $this->assertSame('Admin One', $byEmail['admin@src.test']['name']);
        $this->assertSame(TenantRoleProvisioner::ROLE_COMPANY_ADMIN, $byEmail['admin@src.test']['role']);
        $this->assertSame(TenantRoleProvisioner::ROLE_OPERATOR, $byEmail['op@src.test']['role']);

        // No credential ever rides in the bundle.
        foreach ($users as $u) {
            $this->assertArrayNotHasKey('password', $u);
            $this->assertArrayNotHasKey('id', $u);
        }
    }

    public function test_import_new_links_an_existing_operator_and_creates_a_missing_one(): void
    {
        $company = $this->sourceCompany();
        $this->assignUser($company, 'keep@src.test', 'Keep Me', TenantRoleProvisioner::ROLE_COMPANY_ADMIN);
        $this->assignUser($company, 'gone@src.test', 'Fresh Hire', TenantRoleProvisioner::ROLE_OPERATOR);

        $bundle = $this->bundle($company);

        // Simulate another VM: drop the company AND the operator that doesn't
        // exist there yet; keep the one that does (matched by email on import).
        Company::where('slug', 'src')->forceDelete();
        User::where('email', 'gone@src.test')->forceDelete();

        $summary = app(CompanyImporter::class)->run($bundle, [
            'new' => true, 'execute' => true, 'passphrase' => 'p@ss',
        ]);

        $this->assertSame(['attach' => 1, 'create' => 1], $summary['users']);

        $new = Company::where('slug', 'src')->firstOrFail();
        $provisioner = app(TenantRoleProvisioner::class);

        // Existing user linked, keeps their exported role.
        $keep = User::where('email', 'keep@src.test')->firstOrFail();
        $this->assertTrue($keep->companies()->whereKey($new->id)->exists());
        $this->assertSame(TenantRoleProvisioner::ROLE_COMPANY_ADMIN, $provisioner->roleInCompany($keep, $new));

        // Missing user CREATED, linked + roled, and un-loggable (random password
        // set → not equal to any known/empty value; login only via reset).
        $fresh = User::where('email', 'gone@src.test')->firstOrFail();
        $this->assertTrue($fresh->companies()->whereKey($new->id)->exists());
        $this->assertSame(TenantRoleProvisioner::ROLE_OPERATOR, $provisioner->roleInCompany($fresh, $new));
        $this->assertNotNull($fresh->password);
        $this->assertFalse(Hash::check('', $fresh->password));
        $this->assertFalse(Hash::check('src-secret', $fresh->password));
    }

    public function test_dry_run_predicts_the_operator_attach_create_split_and_writes_no_user(): void
    {
        $company = $this->sourceCompany();
        $this->assignUser($company, 'keep@src.test', 'Keep Me', TenantRoleProvisioner::ROLE_OPERATOR);
        $this->assignUser($company, 'gone@src.test', 'Fresh Hire', TenantRoleProvisioner::ROLE_OPERATOR);

        $bundle = $this->bundle($company);
        Company::where('slug', 'src')->forceDelete();
        User::where('email', 'gone@src.test')->forceDelete();

        $summary = app(CompanyImporter::class)->run($bundle, [
            'new' => true, 'execute' => false, 'passphrase' => 'p@ss',
        ]);

        $this->assertTrue($summary['dry_run']);
        $this->assertSame(['attach' => 1, 'create' => 1], $summary['users']);
        // Dry-run must not have created the missing operator.
        $this->assertNull(User::where('email', 'gone@src.test')->first());
    }

    public function test_import_caps_an_exported_super_admin_to_company_admin(): void
    {
        $company = $this->sourceCompany();
        $provisioner = app(TenantRoleProvisioner::class);
        $superName = $provisioner->managedRoleNames()[0];

        $boss = User::create(['name' => 'Boss', 'email' => 'boss@src.test', 'password' => bcrypt('x')]);
        $boss->companies()->syncWithoutDetaching([$company->id]);
        $provisioner->assignSuperAdmin($boss, $company);

        // The export honestly records the source role…
        $bundle = $this->bundle($company);
        $this->assertSame($superName, collect($bundle['users'])->firstWhere('email', 'boss@src.test')['role']);

        // …but importing onto a (potentially shared) box must NOT mint a global
        // super_admin from bundle data. Fresh VM: drop company + boss so import
        // creates them.
        Company::where('slug', 'src')->forceDelete();
        User::where('email', 'boss@src.test')->forceDelete();

        app(CompanyImporter::class)->run($bundle, ['new' => true, 'execute' => true, 'passphrase' => 'p@ss']);

        $new = Company::where('slug', 'src')->firstOrFail();
        $boss = User::where('email', 'boss@src.test')->firstOrFail();
        // Capped to company_admin — NOT super_admin, and NOT a system super_admin
        // (no cross-tenant Gate::before bypass granted from a bundle).
        $this->assertSame(TenantRoleProvisioner::ROLE_COMPANY_ADMIN, $provisioner->roleInCompany($boss, $new));
        $this->assertFalse($provisioner->isSystemSuperAdmin($boss));
    }

    public function test_into_restore_does_not_re_role_a_live_existing_operator(): void
    {
        $company = $this->sourceCompany();
        $provisioner = app(TenantRoleProvisioner::class);

        // Bundle captures op@src as company_admin…
        $op = $this->assignUser($company, 'op@src.test', 'Op', TenantRoleProvisioner::ROLE_COMPANY_ADMIN);
        $bundle = $this->bundle($company);

        // …then a live change (post-dating the bundle) demotes them to operator.
        $provisioner->setRoleInCompany($op, $company, TenantRoleProvisioner::ROLE_OPERATOR);

        // An --into restore must NOT silently re-promote the live user.
        app(CompanyImporter::class)->run($bundle, ['into' => 'src', 'execute' => true, 'passphrase' => 'p@ss']);

        $this->assertSame(TenantRoleProvisioner::ROLE_OPERATOR, $provisioner->roleInCompany($op->fresh(), $company));
        // Still linked to the company.
        $this->assertTrue($op->fresh()->companies()->whereKey($company->id)->exists());
    }

    public function test_into_restore_roles_an_existing_user_who_is_new_to_the_company(): void
    {
        $company = $this->sourceCompany();
        $provisioner = app(TenantRoleProvisioner::class);

        $u = $this->assignUser($company, 'newcomer@src.test', 'Newcomer', TenantRoleProvisioner::ROLE_OPERATOR);
        $bundle = $this->bundle($company);

        // The user still EXISTS globally but is no longer a member of this company
        // and holds no role here (detached / another tenant) → an --into restore
        // should provision them, unlike a live member (previous test).
        $provisioner->setRoleInCompany($u, $company, null);
        $u->companies()->detach($company->id);
        $this->assertNull($provisioner->roleInCompany($u, $company));

        app(CompanyImporter::class)->run($bundle, ['into' => 'src', 'execute' => true, 'passphrase' => 'p@ss']);

        $this->assertTrue($u->fresh()->companies()->whereKey($company->id)->exists());
        $this->assertSame(TenantRoleProvisioner::ROLE_OPERATOR, $provisioner->roleInCompany($u->fresh(), $company));
    }

    // ── (2) WHMCS default receipt/unpaid type FKs ────────────────────────────

    public function test_whmcs_default_receipt_and_unpaid_type_fks_are_rewired_on_import(): void
    {
        $company = $this->sourceCompany();
        $invoiceType = InvoiceType::create(['company_id' => $company->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1]);
        $receiptType = InvoiceType::create(['company_id' => $company->id, 'code' => 'ALP', 'name' => 'ΑΛΠ', 'invcount' => 1]);
        $unpaidType = InvoiceType::create(['company_id' => $company->id, 'code' => 'PROF', 'name' => 'Προσφορά', 'invcount' => 1]);
        $company->forceFill([
            'whmcs_default_invoice_type_id' => $invoiceType->id,
            'whmcs_default_receipt_type_id' => $receiptType->id,
            'whmcs_default_unpaid_type_id' => $unpaidType->id,
        ])->save();

        $bundle = $this->bundle($company->fresh());
        Company::where('slug', 'src')->forceDelete();

        app(CompanyImporter::class)->run($bundle, ['new' => true, 'execute' => true, 'passphrase' => 'p@ss']);

        $new = Company::where('slug', 'src')->firstOrFail();
        // Each default points at the IMPORTED type with the matching code — the
        // NEW id (auto-increment continues past the source), never the stale
        // source id (which is the bug: only invoice_type_id used to rewire).
        $codeToId = InvoiceType::where('company_id', $new->id)->pluck('id', 'code');
        $this->assertSame((int) $codeToId['TPY'], (int) $new->whmcs_default_invoice_type_id);
        $this->assertSame((int) $codeToId['ALP'], (int) $new->whmcs_default_receipt_type_id);
        $this->assertSame((int) $codeToId['PROF'], (int) $new->whmcs_default_unpaid_type_id);
        // And the rewired ids are genuinely different from the source ids.
        $this->assertNotSame($receiptType->id, (int) $new->whmcs_default_receipt_type_id);
        $this->assertNotSame($unpaidType->id, (int) $new->whmcs_default_unpaid_type_id);
    }

    public function test_a_customers_default_series_is_rewired_to_the_imported_type(): void
    {
        $company = $this->sourceCompany();
        InvoiceType::create(['company_id' => $company->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1]);
        $internal = InvoiceType::create(['company_id' => $company->id, 'code' => 'ESO', 'name' => 'Εσωτερικά', 'invcount' => 1, 'is_informal' => true]);
        Customer::create(['company_id' => $company->id, 'name' => 'Εμείς', 'afm' => '123456789', 'default_invoice_type_id' => $internal->id]);

        $bundle = app(CompanyExporter::class)->build($company->fresh(), 'passphrase', 'p@ss', true);
        Company::where('slug', 'src')->forceDelete();

        app(CompanyImporter::class)->run($bundle, ['new' => true, 'full' => true, 'execute' => true, 'passphrase' => 'p@ss']);

        $new = Company::where('slug', 'src')->firstOrFail();
        $imported = InvoiceType::where('company_id', $new->id)->where('code', 'ESO')->firstOrFail();
        // The imported customer points at the NEW company's informal series — never
        // the stale source id (which would reference another tenant's type).
        $this->assertSame($imported->id, (int) Customer::where('company_id', $new->id)->value('default_invoice_type_id'));
        $this->assertNotSame($internal->id, $imported->id);
        $this->assertTrue((bool) $imported->is_informal);
    }

    // ── (3) cross-APP_KEY secret portability ─────────────────────────────────

    public function test_sealed_secrets_survive_an_app_key_change_between_export_and_import(): void
    {
        config(['ekdosi.secrets.encrypt_at_rest' => true]);

        // Source secrets are ciphertext under the CURRENT app key (K1).
        $company = $this->sourceCompany();
        $bundle = $this->bundle($company); // sealed with the passphrase (key-independent)

        // Simulate a DIFFERENT VM with a different APP_KEY.
        Company::where('slug', 'src')->forceDelete();
        $this->swapAppKey();

        app(CompanyImporter::class)->run($bundle, ['new' => true, 'execute' => true, 'passphrase' => 'p@ss']);

        $new = Company::where('slug', 'src')->firstOrFail();
        // Re-encrypted under K2 → decrypts to the original plaintext.
        $this->assertSame('PRODKEY', $new->mydata_subscription_key_production);
        $this->assertSame('gsis-pw', $new->gsis_password);
        // At rest it is genuinely ciphertext under K2 (encrypt_at_rest is on), not plaintext.
        $raw = DB::table('companies')->where('id', $new->id)->value('gsis_password');
        $this->assertNotSame('gsis-pw', $raw);
    }

    /** Rebind the encrypter to a fresh APP_KEY (both the container singleton and the facade cache). */
    private function swapAppKey(): void
    {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    }
}
