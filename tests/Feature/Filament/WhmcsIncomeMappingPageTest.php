<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\WhmcsIncomeMapping;
use App\Models\Company;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\WhmcsIncomeMap;
use App\Services\TenantRoleProvisioner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MYD-006 bridge — the «Αντιστοίχιση WHMCS → κατηγορία εσόδων» page: pulls the WHMCS
 * product catalogue, maps a whole GROUP to a §8.6 bucket, persists to
 * whmcs_income_maps. Gated on a configured WHMCS integration + super_admin.
 */
class WhmcsIncomeMappingPageTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(bool $whmcs = true): Company
    {
        return Company::create([
            'name' => 'Bridge OE', 'slug' => 'br-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'whmcs_api_url' => $whmcs ? 'https://example.gr/includes/api.php' : null,
            'whmcs_api_identifier' => $whmcs ? 'ID' : null,
            'whmcs_api_secret' => $whmcs ? 'SECRET' : null,
        ]);
    }

    private function actingSuperAdmin(Company $company): User
    {
        $user = User::create(['name' => 'Su', 'email' => 'su-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($company->id);
        app(TenantRoleProvisioner::class)->assignSuperAdmin($user, $company);
        $this->actingAs($user);
        Filament::setTenant($company);

        return $user;
    }

    #[Test]
    public function access_requires_a_configured_whmcs_integration(): void
    {
        $noWhmcs = $this->tenant(whmcs: false);
        $this->actingSuperAdmin($noWhmcs);
        $this->assertFalse(WhmcsIncomeMapping::canAccess(), 'no WHMCS creds → hidden');

        $withWhmcs = $this->tenant(whmcs: true);
        $this->actingSuperAdmin($withWhmcs);
        $this->assertTrue(WhmcsIncomeMapping::canAccess());
    }

    #[Test]
    public function fetch_groups_the_catalogue_and_save_persists_the_group_mapping(): void
    {
        $company = $this->tenant();
        $this->actingSuperAdmin($company);

        Http::fake([
            'example.gr/*' => Http::response([
                'result' => 'success',
                'products' => ['product' => [
                    ['pid' => 42, 'gid' => 3, 'name' => 'Personal2', 'groupname' => 'Web Hosting'],
                    ['pid' => 43, 'gid' => 3, 'name' => 'Business', 'groupname' => 'Web Hosting'],
                    ['pid' => 50, 'gid' => 9, 'name' => 'SSL', 'groupname' => 'Certificates'],
                ]],
            ], 200),
        ]);

        $component = Livewire::test(WhmcsIncomeMapping::class)
            ->call('fetch')
            ->assertSet('fetched', true);

        // Two groups (Web Hosting, Certificates), Web Hosting carries both packages.
        $groups = $component->get('groups');
        $this->assertCount(2, $groups);

        // Map the «Web Hosting» group (gid 3) to services and save.
        $component->set('choice.3', 'category1_3')->call('save');

        $this->assertDatabaseHas('whmcs_income_maps', [
            'company_id' => $company->id, 'scope' => 'group', 'whmcs_key' => 3,
            'income_class_category' => 'category1_3',
        ]);

        // Clearing the choice unmaps it.
        $component->set('choice.3', '')->call('save');
        $this->assertDatabaseMissing('whmcs_income_maps', [
            'company_id' => $company->id, 'scope' => 'group', 'whmcs_key' => 3,
        ]);
    }

    #[Test]
    public function save_persists_the_ekdosi_product_category_alongside_the_income_class(): void
    {
        $company = $this->tenant();
        $this->actingSuperAdmin($company);
        $cat = ProductCategory::create(['company_id' => $company->id, 'description_short' => 'Web Hosting']);

        Http::fake([
            'example.gr/*' => Http::response([
                'result' => 'success',
                'products' => ['product' => [
                    ['pid' => 42, 'gid' => 3, 'name' => 'Personal2', 'groupname' => 'Web Hosting'],
                ]],
            ], 200),
        ]);

        Livewire::test(WhmcsIncomeMapping::class)
            ->call('fetch')
            ->set('choice.3', 'category1_3')
            ->set('categoryChoice.3', (string) $cat->id)
            ->call('save');

        $this->assertDatabaseHas('whmcs_income_maps', [
            'company_id' => $company->id, 'scope' => 'group', 'whmcs_key' => 3,
            'income_class_category' => 'category1_3', 'product_category_id' => $cat->id,
        ]);

        // Prehydrates on next mount.
        Livewire::test(WhmcsIncomeMapping::class)
            ->assertSet('categoryChoice.3', (string) $cat->id);
    }

    #[Test]
    public function a_category_chosen_without_a_section_8_6_class_warns_and_is_not_silently_dropped(): void
    {
        // The P2: picking a «Κατηγορία ekdosi» but leaving §8.6 empty used to
        // discard the category silently (no row can exist without a §8.6 class —
        // income_class_category is NOT NULL). Now the operator is warned by name,
        // and — as before — no half-row is written.
        $company = $this->tenant();
        $this->actingSuperAdmin($company);
        $cat = ProductCategory::create(['company_id' => $company->id, 'description_short' => 'Web Hosting']);

        Http::fake([
            'example.gr/*' => Http::response([
                'result' => 'success',
                'products' => ['product' => [
                    ['pid' => 42, 'gid' => 3, 'name' => 'Personal2', 'groupname' => 'Web Hosting'],
                ]],
            ], 200),
        ]);

        Livewire::test(WhmcsIncomeMapping::class)
            ->call('fetch')
            // Category chosen, §8.6 left «— δεν έχει οριστεί —» (empty).
            ->set('choice.3', '')
            ->set('categoryChoice.3', (string) $cat->id)
            ->call('save')
            ->assertNotified('1 ομάδα/ες: η «Κατηγορία ekdosi» δεν αποθηκεύτηκε');

        // Nothing persisted for that group (no §8.6-less row snuck in).
        $this->assertDatabaseMissing('whmcs_income_maps', [
            'company_id' => $company->id, 'scope' => 'group', 'whmcs_key' => 3,
        ]);
    }

    #[Test]
    public function unmapping_a_group_does_not_warn_about_its_prehydrated_category(): void
    {
        // A group saved with §8.6 + category prehydrates categoryChoice on mount.
        // Clearing its §8.6 to intentionally UNMAP must NOT raise the «category not
        // saved» warning — that leftover category belongs to the unmap, not a new
        // lost selection (regression guard for the warning's false-positive).
        $company = $this->tenant();
        $this->actingSuperAdmin($company);
        $cat = ProductCategory::create(['company_id' => $company->id, 'description_short' => 'Web Hosting']);
        WhmcsIncomeMap::create([
            'company_id' => $company->id, 'scope' => WhmcsIncomeMap::SCOPE_GROUP,
            'whmcs_key' => 3, 'income_class_category' => 'category1_3', 'product_category_id' => $cat->id,
        ]);

        Http::fake(['example.gr/*' => Http::response([
            'result' => 'success',
            'products' => ['product' => [['pid' => 42, 'gid' => 3, 'name' => 'Personal2', 'groupname' => 'Web Hosting']]],
        ], 200)]);

        Livewire::test(WhmcsIncomeMapping::class)
            ->call('fetch')
            ->assertSet('categoryChoice.3', (string) $cat->id) // prehydrated leftover
            ->set('choice.3', '')                              // unmap: clear §8.6
            ->call('save')
            ->assertNotified('Αποθηκεύτηκαν 0 αντιστοιχίσεις (1 καθαρίστηκαν)')
            ->assertNotNotified('1 ομάδα/ες: η «Κατηγορία ekdosi» δεν αποθηκεύτηκε');

        $this->assertDatabaseMissing('whmcs_income_maps', [
            'company_id' => $company->id, 'scope' => 'group', 'whmcs_key' => 3,
        ]);
    }

    #[Test]
    public function changing_a_mapped_groups_category_without_section_8_6_still_warns(): void
    {
        // Re-map path: a group is already mapped (§8.6 + catA); the operator switches
        // the category to catB but clears §8.6. That NEW category can't be saved
        // without a §8.6 class, so it must still warn — not be silently dropped just
        // because an existing row was there to delete.
        $company = $this->tenant();
        $this->actingSuperAdmin($company);
        $catA = ProductCategory::create(['company_id' => $company->id, 'description_short' => 'Web Hosting']);
        $catB = ProductCategory::create(['company_id' => $company->id, 'description_short' => 'VPS']);
        WhmcsIncomeMap::create([
            'company_id' => $company->id, 'scope' => WhmcsIncomeMap::SCOPE_GROUP,
            'whmcs_key' => 3, 'income_class_category' => 'category1_3', 'product_category_id' => $catA->id,
        ]);

        Http::fake(['example.gr/*' => Http::response([
            'result' => 'success',
            'products' => ['product' => [['pid' => 42, 'gid' => 3, 'name' => 'Personal2', 'groupname' => 'Web Hosting']]],
        ], 200)]);

        Livewire::test(WhmcsIncomeMapping::class)
            ->call('fetch')
            ->set('choice.3', '')                          // §8.6 cleared
            ->set('categoryChoice.3', (string) $catB->id)  // but a DIFFERENT category chosen
            ->call('save')
            ->assertNotified('1 ομάδα/ες: η «Κατηγορία ekdosi» δεν αποθηκεύτηκε');

        $this->assertDatabaseMissing('whmcs_income_maps', [
            'company_id' => $company->id, 'scope' => 'group', 'whmcs_key' => 3,
        ]);
    }

    #[Test]
    public function a_second_save_after_an_unmap_does_not_re_warn(): void
    {
        // Regression: after a deliberate unmap the form re-syncs to DB, so the
        // orphaned prehydrated category no longer re-triggers the «δεν αποθηκεύτηκε»
        // warning on a later save.
        $company = $this->tenant();
        $this->actingSuperAdmin($company);
        $cat = ProductCategory::create(['company_id' => $company->id, 'description_short' => 'Web Hosting']);
        WhmcsIncomeMap::create([
            'company_id' => $company->id, 'scope' => WhmcsIncomeMap::SCOPE_GROUP,
            'whmcs_key' => 3, 'income_class_category' => 'category1_3', 'product_category_id' => $cat->id,
        ]);

        Http::fake(['example.gr/*' => Http::response([
            'result' => 'success',
            'products' => ['product' => [['pid' => 42, 'gid' => 3, 'name' => 'Personal2', 'groupname' => 'Web Hosting']]],
        ], 200)]);

        $component = Livewire::test(WhmcsIncomeMapping::class)
            ->call('fetch')
            ->set('choice.3', '')  // unmap (category dropdown untouched → prehydrated)
            ->call('save')
            ->assertNotNotified('1 ομάδα/ες: η «Κατηγορία ekdosi» δεν αποθηκεύτηκε');

        // Second save must stay quiet (before the fix it re-warned every time).
        $component->call('save')
            ->assertNotNotified('1 ομάδα/ες: η «Κατηγορία ekdosi» δεν αποθηκεύτηκε');
    }

    #[Test]
    public function mount_prehydrates_saved_group_choices(): void
    {
        $company = $this->tenant();
        $this->actingSuperAdmin($company);
        WhmcsIncomeMap::create([
            'company_id' => $company->id, 'scope' => WhmcsIncomeMap::SCOPE_GROUP,
            'whmcs_key' => 3, 'income_class_category' => 'category1_3',
        ]);

        Livewire::test(WhmcsIncomeMapping::class)
            ->assertSet('choice.3', 'category1_3');
    }
}
