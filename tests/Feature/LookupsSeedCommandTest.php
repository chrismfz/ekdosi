<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ProductCategory;
use App\Models\VatCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `lookups:seed` — the headless backfill/top-up of the standard Greek AADE lookups
 * for existing tenants (idempotent + fill-empty). Companion to the install seed and
 * the panel «Εισαγωγή τυπικών» action.
 */
class LookupsSeedCommandTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $country = 'GR'): Company
    {
        return Company::create([
            'name' => 'T '.$country, 'slug' => 't-'.uniqid(),
            'country_code' => $country, 'einvoice_provider' => $country === 'GR' ? 'gr-mydata' : 'ee-peppol',
            'mydata_mode' => 'off',
        ]);
    }

    public function test_seeds_a_named_tenant_with_classified_product_categories(): void
    {
        $t = $this->tenant();

        $this->artisan('lookups:seed', ['--tenant' => $t->slug])->assertExitCode(0);

        // The core lookups are now present…
        $this->assertGreaterThan(0, VatCategory::where('company_id', $t->id)->count());
        // …and the product categories carry their §8.6 income bucket out of the box.
        $this->assertSame('category1_3', ProductCategory::where('company_id', $t->id)->where('description_short', 'Υπηρεσίες')->value('mydata_income_class_category'));
        $this->assertSame('category1_1', ProductCategory::where('company_id', $t->id)->where('description_short', 'Εμπορεύματα')->value('mydata_income_class_category'));
    }

    public function test_tops_up_missing_lookups_without_touching_an_existing_product_category(): void
    {
        $t = $this->tenant();
        // An existing category with NO bucket (legacy import) must NOT be silently
        // reclassified — new-rows-only (MYD-006 safety). The command still tops up
        // every OTHER missing lookup around it.
        ProductCategory::create(['company_id' => $t->id, 'description_short' => 'Υπηρεσίες', 'markup' => 0]);

        $this->artisan('lookups:seed', ['--tenant' => $t->slug])->assertExitCode(0);

        $this->assertNull(
            ProductCategory::where('company_id', $t->id)->where('description_short', 'Υπηρεσίες')->value('mydata_income_class_category'),
            'the existing bucket is left untouched (no silent §8.6 reclassification)'
        );
        // The lookups that WERE missing are now seeded.
        $this->assertGreaterThan(0, VatCategory::where('company_id', $t->id)->count());
        // …and the standard categories that did NOT already exist arrive classified.
        $this->assertSame('category1_1', ProductCategory::where('company_id', $t->id)->where('description_short', 'Εμπορεύματα')->value('mydata_income_class_category'));
    }

    public function test_is_idempotent_on_a_second_run(): void
    {
        $t = $this->tenant();
        $this->artisan('lookups:seed', ['--tenant' => $t->slug])->assertExitCode(0);

        Artisan::call('lookups:seed', ['--tenant' => $t->slug, '--json' => true]);
        $out = json_decode(Artisan::output(), true);

        // Second run creates nothing (all skipped) — everything already present.
        $this->assertSame(0, $out[0]['seeded']['vat']['created']);
        $this->assertSame(0, $out[0]['seeded']['product_categories']['created']);
    }

    public function test_all_covers_gr_tenants_and_skips_non_gr(): void
    {
        $gr = $this->tenant('GR');
        $ee = $this->tenant('EE');

        $this->artisan('lookups:seed', ['--all' => true])->assertExitCode(0);

        $this->assertGreaterThan(0, VatCategory::where('company_id', $gr->id)->count());
        $this->assertSame(0, VatCategory::where('company_id', $ee->id)->count(), 'EE tenant is not seeded with Greek AADE lookups');
    }

    public function test_requires_a_selector(): void
    {
        $this->artisan('lookups:seed')->assertExitCode(1);
    }

    public function test_unknown_tenant_fails(): void
    {
        $this->artisan('lookups:seed', ['--tenant' => 'does-not-exist'])->assertExitCode(1);
    }
}
