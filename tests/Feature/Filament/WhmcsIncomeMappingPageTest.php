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
