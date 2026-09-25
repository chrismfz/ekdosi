<?php

namespace Tests\Feature\MyData;

use App\Filament\Pages\CnCatalogPage;
use App\Jobs\ImportCnCatalog;
use App\Models\CnCode;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\VatCategory;
use App\Services\Taric\CnCatalog;
use App\Services\TenantRoleProvisioner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Συνδυασμένη Ονοματολογία reference list (cn_codes): the bundled 2026 load, search for
 * the product form, the EU RDF import, obsolete-code detection, the page + queued refresh.
 */
class CnCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_bundled_2026_list_is_loaded_by_the_migration(): void
    {
        $this->assertGreaterThan(9000, CnCode::query()->where('year', 2026)->count());
        $this->assertSame(2026, app(CnCatalog::class)->latestYear());
    }

    public function test_search_by_code_or_greek_words_returns_ten_char_keys(): void
    {
        $byCode = app(CnCatalog::class)->search('8471 30');
        $this->assertArrayHasKey('8471300000', $byCode);                  // CN 8 + «00»

        $byWords = app(CnCatalog::class)->search('φορητές 10 kg');
        $this->assertArrayHasKey('8471300000', $byWords);
        $this->assertStringContainsString('8471 30 00', $byWords['8471300000']);

        $this->assertArrayHasKey('8471300000', app(CnCatalog::class)->search('8471.30.00'));  // dotted code
        $this->assertStringNotContainsString('8471 30 00 — Αυτόματες μηχανές επεξεργασίας πληροφοριών, φορητές, με βάρος που δεν υπερβαίνει τα 10 kg… · 8471 ', $byWords['8471300000']); // heading not re-prefixed
        $this->assertSame([], app(CnCatalog::class)->search('100%_'));                        // wildcards escaped
        $this->assertSame([], app(CnCatalog::class)->search(''));
        $this->assertNull(app(CnCatalog::class)->labelFor('9999999900'));
    }

    public function test_rdf_import_builds_paths_and_replaces_only_that_year(): void
    {
        $n = app(CnCatalog::class)->importRdf($this->rdfFile(), 2027, minRows: 1);

        $this->assertSame(2, $n);
        $leaf = CnCode::query()->where('year', 2027)->where('code', '85444290')->first();
        $this->assertSame('Άλλοι', $leaf->description_el);
        $this->assertSame('Σύρματα, καλώδια › Που έχουν τεμάχια σύνδεσης › Άλλοι', $leaf->path_el);
        $this->assertGreaterThan(9000, CnCode::query()->where('year', 2026)->count()); // untouched
        $this->assertSame(2027, app(CnCatalog::class)->latestYear());
    }

    public function test_a_partial_file_never_wipes_a_year(): void
    {
        $this->expectException(\RuntimeException::class);
        app(CnCatalog::class)->importRdf($this->rdfFile(), 2026);                 // 2 rows < 1000
    }

    public function test_the_eu_import_resolves_the_year_dataset_and_downloads_the_rdf(): void
    {
        Http::fake([
            'data.europa.eu/api/hub/search/datasets/combined-nomenclature-2027' => Http::response(['result' => ['distributions' => [
                ['access_url' => ['https://op.europa.eu/dl?fileName=ESTAT-CN2027-skos-ap-eu.rdf']],
                ['access_url' => ['https://op.europa.eu/dl?fileName=ESTAT-CN2027.rdf']],
            ]]]),
            'op.europa.eu/*' => Http::response(file_get_contents($this->rdfFile())),
        ]);

        $r = app(CnCatalog::class)->importFromEu(2027, minRows: 1);

        $this->assertSame(2, $r['count']);
        $this->assertStringContainsString('ESTAT-CN2027.rdf', $r['source']);      // core file preferred over AP-EU
    }

    public function test_the_download_is_only_fetched_from_an_eu_host(): void
    {
        Http::fake(['data.europa.eu/*' => Http::response(['result' => ['distributions' => [
            ['access_url' => ['https://evil.example/ESTAT-CN2027.rdf']],
        ]]])]);

        $this->expectException(\RuntimeException::class);
        app(CnCatalog::class)->importFromEu(2027, minRows: 1);
    }

    public function test_obsolete_products_are_those_missing_from_the_latest_year(): void
    {
        $tenant = Company::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'country_code' => 'GR']);
        $vat = VatCategory::create(['company_id' => $tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
        $cat = ProductCategory::create(['company_id' => $tenant->id, 'description_short' => 'HW']);
        $mk = fn (string $name, string $code) => Product::create(['company_id' => $tenant->id, 'description_short' => $name,
            'taric_code' => $code, 'product_category_id' => $cat->id, 'vat_category_id' => $vat->id]);
        $mk('Laptop', '84713000');                 // exists in 2026
        $mk('Old thing', '99999999');              // not a CN code

        $this->assertSame(['Old thing'], app(CnCatalog::class)->obsoleteProducts($tenant)->pluck('description_short')->all());
    }

    public function test_the_page_queues_the_refresh_for_a_super_admin(): void
    {
        Queue::fake();
        $tenant = Company::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'country_code' => 'GR']);
        Gate::before(fn () => true);
        $user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $this->actingAs($user);
        Filament::setTenant($tenant);
        $this->partialMock(TenantRoleProvisioner::class, fn ($m) => $m->shouldReceive('isSuperAdminAnywhere')->andReturn(true));

        Livewire::test(CnCatalogPage::class)
            ->assertOk()
            ->assertSee('Φορτωμένο έτος')
            ->callAction('refresh_eu', data: ['year' => 2026]);

        Queue::assertPushed(ImportCnCatalog::class, fn ($job) => $job->year === 2026 && $job->userId === $user->id);
    }

    public function test_the_refresh_is_hidden_from_a_non_super_admin(): void
    {
        $tenant = Company::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'country_code' => 'GR']);
        Gate::before(fn () => true);
        $this->actingAs(User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]));
        Filament::setTenant($tenant);
        $this->partialMock(TenantRoleProvisioner::class, fn ($m) => $m->shouldReceive('isSuperAdminAnywhere')->andReturn(false));

        Livewire::test(CnCatalogPage::class)->assertOk()->assertActionHidden('refresh_eu');
    }

    public function test_the_cli_imports_a_local_file(): void
    {
        $this->artisan('cn:import', ['--year' => 2025, '--file' => $this->rdfFile()])->assertFailed();   // < 1000 rows guard
    }

    /** A 2-leaf SKOS/RDF shaped like the EU file (4 › 6 › 8 digits, el + en labels). */
    private function rdfFile(): string
    {
        $concept = fn (string $nota, string $el) => '<rdf:Description rdf:about="http://data.europa.eu/xsp/cn/'.preg_replace('/\D/', '', $nota).'">'
            .'<prefLabel xmlns="http://www.w3.org/2004/02/skos/core#" xml:lang="el">'.$nota.' - '.$el.'</prefLabel>'
            .'<prefLabel xmlns="http://www.w3.org/2004/02/skos/core#" xml:lang="en">'.$nota.' - x</prefLabel>'
            .'<notation xmlns="http://www.w3.org/2004/02/skos/core#">'.$nota.'</notation></rdf:Description>';
        $xml = '<?xml version="1.0" encoding="UTF-8"?><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            .$concept('8544', 'Σύρματα, καλώδια')
            .$concept('8544 42', '-- Που έχουν τεμάχια σύνδεσης')
            .$concept('8544 42 10', '--- Για τηλεπικοινωνίες')
            .$concept('8544 42 90', '--- Άλλοι')
            .'</rdf:RDF>';
        $path = tempnam(sys_get_temp_dir(), 'cnt');
        file_put_contents($path, $xml);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }
}
