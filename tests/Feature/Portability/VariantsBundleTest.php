<?php

namespace Tests\Feature\Portability;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductCategory;
use App\Models\VatCategory;
use App\Services\Portability\CompanyDataWiper;
use App\Services\Portability\CompanyExporter;
use App\Services\Portability\CompanyImporter;
use App\Services\Products\VariantGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Variants travel in a full company bundle: attributes + values (setup), the
 * variant ↔ value links, and each variant's parent_product_id rewired to the
 * NEW parent id (self-reference patched after the products pass). A re-import
 * into the same company converges instead of tripping the unique indexes.
 */
class VariantsBundleTest extends TestCase
{
    use RefreshDatabase;

    public function test_variants_round_trip_into_a_fresh_company_and_reimport_converges(): void
    {
        $src = Company::create(['name' => 'Ρούχα ΟΕ', 'slug' => 'rouxa', 'country_code' => 'GR', 'afm' => '800561849']);
        $vat = VatCategory::create(['company_id' => $src->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
        $cat = ProductCategory::create(['company_id' => $src->id, 'description_short' => 'ΕΝ', 'description' => 'Ένδυση']);
        $color = ProductAttribute::create(['company_id' => $src->id, 'name' => 'Χρώμα', 'kind' => ProductAttribute::KIND_COLOR, 'sort' => 1]);
        $size = ProductAttribute::create(['company_id' => $src->id, 'name' => 'Μέγεθος', 'kind' => ProductAttribute::KIND_SIZE, 'sort' => 2]);
        foreach ([[$color, ['Μαύρο']], [$size, ['S', 'M']]] as [$attribute, $values]) {
            foreach ($values as $i => $value) {
                ProductAttributeValue::create(['company_id' => $src->id, 'product_attribute_id' => $attribute->id, 'value' => $value, 'sort' => $i]);
            }
        }
        $parent = Product::create([
            'company_id' => $src->id, 'kind' => Product::KIND_VARIABLE, 'description_short' => 'Παντελόνι',
            'product_category_id' => $cat->id, 'vat_category_id' => $vat->id, 'track_stock' => true,
        ]);
        app(VariantGenerator::class)->generate($parent, [
            $color->id => $color->values()->pluck('id')->all(),
            $size->id => $size->values()->pluck('id')->all(),
        ]);

        $bundle = app(CompanyExporter::class)->build($src, 'passphrase', 'p@ss', true);
        Company::where('slug', 'rouxa')->update(['slug' => 'rouxa-src', 'afm' => '000000000']);

        app(CompanyImporter::class)->run($bundle, ['new' => true, 'execute' => true, 'passphrase' => 'p@ss']);

        $company = Company::where('slug', 'rouxa')->firstOrFail();
        $newParent = Product::where('company_id', $company->id)->where('kind', Product::KIND_VARIABLE)->firstOrFail();
        $newVariants = Product::where('company_id', $company->id)->where('kind', Product::KIND_VARIANT)->orderBy('id')->get();

        $this->assertCount(2, $newVariants);
        $this->assertNotSame($parent->id, $newParent->id);
        foreach ($newVariants as $variant) {
            $this->assertSame($newParent->id, $variant->parent_product_id, 'parent rewired to the NEW id');
            $this->assertCount(2, $variant->variantValues);
            $this->assertSame([$company->id], $variant->variantValues->pluck('company_id')->unique()->values()->all());
        }
        $this->assertSame(['Μαύρο', 'S'], $newVariants->first()->variantValues
            ->sortBy(fn ($v) => $v->attribute->sort)->pluck('value')->values()->all());

        // The dry-run predicts exactly what execute does for the rewired-key tables:
        // the attribute values MATCH (update), they are not reported as inserts.
        $plan = app(CompanyImporter::class)->run($bundle, ['into' => 'rouxa', 'execute' => false, 'passphrase' => 'p@ss'])['tables'];
        $this->assertSame(['insert' => 0, 'update' => 2], $plan['product_attributes']);
        $this->assertSame(['insert' => 0, 'update' => 3], $plan['product_attribute_values']);

        // Re-import INTO the same company: the setup side (attributes + values,
        // keyed on the rewired attribute id) converges instead of tripping the
        // unique index. Legacy-less products re-insert — the importer's documented
        // --into limitation — but every link still points at a variant of THIS
        // company with exactly one value per attribute (no unique violation).
        app(CompanyImporter::class)->run($bundle, ['into' => 'rouxa', 'execute' => true, 'passphrase' => 'p@ss']);

        $this->assertSame(2, ProductAttribute::where('company_id', $company->id)->count());
        $this->assertSame(3, DB::table('product_attribute_values')->where('company_id', $company->id)->count());
        $variantCount = Product::where('company_id', $company->id)->where('kind', Product::KIND_VARIANT)->count();
        $this->assertSame($variantCount * 2, DB::table('product_variant_values')->where('company_id', $company->id)->count());
        $this->assertSame(0, Product::where('company_id', $company->id)->where('kind', Product::KIND_VARIANT)->whereNull('parent_product_id')->count());
    }

    public function test_wiping_parties_also_drops_the_variant_links(): void
    {
        $c = Company::create(['name' => 'Wipe', 'slug' => 'wipe-v', 'country_code' => 'GR']);
        $vat = VatCategory::create(['company_id' => $c->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
        $cat = ProductCategory::create(['company_id' => $c->id, 'description_short' => 'ΕΝ', 'description' => 'Ένδυση']);
        $size = ProductAttribute::create(['company_id' => $c->id, 'name' => 'Μέγεθος', 'kind' => ProductAttribute::KIND_SIZE]);
        ProductAttributeValue::create(['company_id' => $c->id, 'product_attribute_id' => $size->id, 'value' => 'M']);
        $parent = Product::create([
            'company_id' => $c->id, 'kind' => Product::KIND_VARIABLE, 'description_short' => 'Π',
            'product_category_id' => $cat->id, 'vat_category_id' => $vat->id,
        ]);
        app(VariantGenerator::class)->generate($parent, [$size->id => $size->values()->pluck('id')->all()]);

        app(CompanyDataWiper::class)->wipe($c, keepParties: false, resetCounter: false, force: true);

        $this->assertSame(0, DB::table('product_variant_values')->where('company_id', $c->id)->count());
        $this->assertSame(0, Product::withTrashed()->where('company_id', $c->id)->count());
        // Attributes are setup (kept on a rebuild, like product categories).
        $this->assertSame(1, ProductAttribute::where('company_id', $c->id)->count());
    }
}
