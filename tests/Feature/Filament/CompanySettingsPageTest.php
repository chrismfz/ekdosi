<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\CompanySettings;
use App\Models\Activity;
use App\Models\Company;
use App\Models\CompanyBackupSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * «Ρυθμίσεις εταιρείας» — the self-service safe-subset settings page for
 * company_admin. Proves: (a) company_admin auto-gets View:CompanySettings while
 * operator does not, (b) the form prefills + saves only the whitelisted fields,
 * (c) a crafted payload can't reach a non-whitelisted (super_admin) column.
 */
class CompanySettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private string $guard = 'web';

    private function makeCompany(): Company
    {
        return Company::create([
            'name' => 'Settings OE', 'slug' => 'set-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    // ── Permission wiring ───────────────────────────────────────────────────

    #[Test]
    public function company_admin_gets_the_page_permission_operator_does_not(): void
    {
        // The page perm + a couple of operator-eligible perms shield:generate makes.
        foreach (['View:CompanySettings', 'ViewAny:Invoice', 'Create:Invoice'] as $n) {
            Permission::findOrCreate($n, $this->guard);
        }

        $company = $this->makeCompany();
        app(TenantRoleProvisioner::class)->ensureStandardRoles($company);

        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Role::where('name', TenantRoleProvisioner::ROLE_COMPANY_ADMIN)->where('company_id', $company->getKey())->first();
        $operator = Role::where('name', TenantRoleProvisioner::ROLE_OPERATOR)->where('company_id', $company->getKey())->first();

        $this->assertContains('View:CompanySettings', $admin->permissions->pluck('name')->all(),
            'company_admin must self-serve their own company settings');
        $this->assertNotContains('View:CompanySettings', $operator->permissions->pluck('name')->all(),
            'operator must NOT reach company settings');
    }

    #[Test]
    public function a_user_without_the_permission_cannot_access(): void
    {
        $company = $this->makeCompany();
        $user = User::create(['name' => 'Plain', 'email' => 'p-'.uniqid().'@test.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($company->id);
        $this->actingAs($user);
        Filament::setTenant($company);

        $this->assertFalse(CompanySettings::canAccess());
    }

    // ── Page behaviour (permission bypassed) ────────────────────────────────

    private function actAsAuthorized(): Company
    {
        Gate::before(fn () => true); // bypass View:CompanySettings — behaviour, not gating
        $user = User::create(['name' => 'Admin', 'email' => 'a-'.uniqid().'@test.local', 'password' => bcrypt('x')]);
        $this->actingAs($user);
        $company = $this->makeCompany();
        $user->companies()->attach($company->id);
        Filament::setTenant($company);

        return $company;
    }

    #[Test]
    public function it_renders_and_prefills_from_the_current_tenant(): void
    {
        $company = $this->actAsAuthorized();
        $company->update(['pdf_footer_text' => 'Ευχαριστούμε', 'auto_email_on_issue' => true]);

        Livewire::test(CompanySettings::class)
            ->assertSuccessful()
            ->assertSee('Εμφάνιση PDF')
            ->assertSee('Αυτόματα αντίγραφα ασφαλείας')
            ->assertSet('data.pdf_footer_text', 'Ευχαριστούμε')
            ->assertSet('data.auto_email_on_issue', true)
            ->assertSet('data.backup_frequency', 'off'); // default when no setting row
    }

    #[Test]
    public function saving_writes_the_safe_fields_plus_backup_and_audits(): void
    {
        $company = $this->actAsAuthorized();

        Livewire::test(CompanySettings::class)
            ->set('data.pdf_footer_text', 'Νέο υποσέλιδο')
            ->set('data.mail_from_name', 'ACME Τιμολόγια')
            ->set('data.show_customer_balance_on_pdf', true)
            ->set('data.backup_enabled', true)
            ->set('data.backup_frequency', 'daily')
            ->set('data.backup_run_at_time', '03:30')
            ->call('save')
            ->assertHasNoErrors();

        $company->refresh();
        $this->assertSame('Νέο υποσέλιδο', $company->pdf_footer_text);
        $this->assertSame('ACME Τιμολόγια', $company->mail_from_name);
        $this->assertTrue((bool) $company->show_customer_balance_on_pdf);

        $backup = CompanyBackupSetting::where('company_id', $company->id)->first();
        $this->assertNotNull($backup);
        $this->assertTrue((bool) $backup->enabled);
        $this->assertSame('daily', $backup->frequency);
        $this->assertSame('03:30', $backup->run_at_time);
        // A row CREATED via self-service defaults to raw (local-only): a company_admin
        // can't set a passphrase, so 'passphrase' mode + null passphrase would make
        // every scheduled run throw. raw keeps the local backup actually runnable.
        $this->assertSame('raw', $backup->secrets_mode);

        // The audit diff must land in `attribute_changes` (what the «Ιστορικό» feed
        // renders), not `properties` — else the change shows blank.
        $row = Activity::where('log_name', 'company_settings')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('Ενημέρωση ρυθμίσεων εταιρείας', $row->description);
        $this->assertSame($company->id, $row->company_id);
        $changed = $row->attribute_changes?->toArray() ?? [];
        $this->assertArrayHasKey('attributes', $changed);
        $this->assertSame('Νέο υποσέλιδο', $changed['attributes']['pdf_footer_text'] ?? null);
        $this->assertSame('daily', $changed['attributes']['backup_frequency'] ?? null);
        $this->assertNotEmpty($row->changeLines(), 'the feed must render per-field diff lines');
    }

    #[Test]
    public function company_admin_toggles_auto_fetch_expenses_on_a_mydata_tenant(): void
    {
        Gate::before(fn () => true);
        $user = User::create(['name' => 'Admin', 'email' => 'a-'.uniqid().'@test.local', 'password' => bcrypt('x')]);
        $this->actingAs($user);
        // canReadMyData tenant → the «Αυτόματη άντληση εξόδων» section renders.
        $company = Company::create([
            'name' => 'MyData OE', 'slug' => 'md-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
            'mydata_aade_id_sandbox' => 'U', 'mydata_subscription_key_sandbox' => 'K',
        ]);
        $user->companies()->attach($company->id);
        Filament::setTenant($company);

        Livewire::test(CompanySettings::class)
            ->assertSuccessful()
            ->assertSee('Αυτόματη άντληση εξόδων (myDATA)')
            ->assertSet('data.mydata_auto_fetch_expenses', false)
            ->set('data.mydata_auto_fetch_expenses', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue((bool) $company->refresh()->mydata_auto_fetch_expenses);
    }

    #[Test]
    public function the_mydata_section_is_hidden_for_a_non_mydata_tenant(): void
    {
        // mode='off' → canReadMyData() false → section not rendered, and saving
        // must NOT null the NOT NULL column (kept at its current value).
        $company = $this->actAsAuthorized(); // makeCompany() is mydata_mode='off'

        Livewire::test(CompanySettings::class)
            ->assertSuccessful()
            ->assertDontSee('Αυτόματη άντληση εξόδων (myDATA)')
            ->set('data.pdf_footer_text', 'x')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse((bool) $company->refresh()->mydata_auto_fetch_expenses);
    }

    #[Test]
    public function saving_cannot_reach_a_non_whitelisted_column(): void
    {
        $company = $this->actAsAuthorized();
        $original = $company->name;

        // Craft a payload that tries to overwrite tenant identity (super_admin-only).
        Livewire::test(CompanySettings::class)
            ->set('data.pdf_footer_text', 'legit change')
            ->set('data.name', 'HACKED IDENTITY')
            ->set('data.mydata_subscription_key_production', 'STOLEN')
            ->call('save')
            ->assertHasNoErrors();

        $company->refresh();
        $this->assertSame($original, $company->name, 'identity must be untouched by the self-service page');
        $this->assertNull($company->mydata_subscription_key_production, 'credentials must be unreachable here');
        $this->assertSame('legit change', $company->pdf_footer_text);
    }

    #[Test]
    public function backup_cadence_update_preserves_existing_super_admin_policy(): void
    {
        $company = $this->actAsAuthorized();
        // super_admin set a full policy (passphrase + remote destination + retention).
        CompanyBackupSetting::create([
            'company_id' => $company->id,
            'enabled' => false,
            'frequency' => 'off',
            'run_at_time' => '02:00',
            'bucket' => 'full',
            'secrets_mode' => 'passphrase',
            'passphrase' => 'sup3r-secret',
            'retention_keep' => 30,
            'destinations' => [['driver' => 'sftp', 'host' => 'backup.example.gr']],
        ]);

        Livewire::test(CompanySettings::class)
            ->set('data.backup_enabled', true)
            ->set('data.backup_frequency', 'weekly')
            ->call('save')
            ->assertHasNoErrors();

        $backup = CompanyBackupSetting::where('company_id', $company->id)->first();
        $this->assertTrue((bool) $backup->enabled);
        $this->assertSame('weekly', $backup->frequency);
        // The sensitive policy the company_admin can't see is untouched.
        $this->assertSame('full', $backup->bucket);
        $this->assertSame('sup3r-secret', $backup->passphrase);
        $this->assertSame(30, $backup->retention_keep);
        $this->assertSame([['driver' => 'sftp', 'host' => 'backup.example.gr']], $backup->destinations);
    }
}
