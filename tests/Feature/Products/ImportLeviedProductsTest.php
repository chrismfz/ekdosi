<?php

namespace Tests\Feature\Products;

use App\Actions\ImportLeviedProducts;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Models\VatCategory;
use App\Support\Products\LeviedProductTemplates;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The «θεσμικά τέλη» template importer: selected templates become products
 * carrying the correct myDATA product-linked fee (Fees §8.7 + category + €/unit),
 * idempotent on re-run.
 */
class ImportLeviedProductsTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'Levy OE', 'slug' => 'levy-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        VatCategory::create(['company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
    }

    public function test_imports_selected_templates_with_the_correct_fee(): void
    {
        $res = app(ImportLeviedProducts::class)($this->tenant, ['plastic_bag', 'recycling']);

        $this->assertSame(2, count($res['created']));
        $this->assertSame([], $res['skipped']);

        $bag = Product::where('company_id', $this->tenant->id)
            ->where('description_short', 'Πλαστική σακούλα — περιβαλλοντικό τέλος')->firstOrFail();
        $this->assertSame(2, (int) $bag->mydata_tax_type);          // Fees §8.7
        $this->assertSame(8, (int) $bag->mydata_tax_category);      // TYPE_8
        $this->assertEqualsWithDelta(0.07, (float) $bag->mydata_tax_per_unit, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $bag->sell_price, 0.001);
        $this->assertTrue((bool) $bag->is_active);

        // Only the two selected were created.
        $this->assertSame(2, Product::where('company_id', $this->tenant->id)->count());
    }

    public function test_reimport_skips_existing_and_does_not_duplicate(): void
    {
        app(ImportLeviedProducts::class)($this->tenant, ['plastic_bag']);
        $res = app(ImportLeviedProducts::class)($this->tenant, ['plastic_bag', 'recycling']);

        // plastic_bag already there → skipped; recycling is new → created.
        $this->assertSame(['Τέλος ανακύκλωσης'], $res['created']);
        $this->assertSame(['Πλαστική σακούλα — περιβαλλοντικό τέλος'], $res['skipped']);
        $this->assertSame(2, Product::where('company_id', $this->tenant->id)->count());
    }

    public function test_unknown_key_is_ignored(): void
    {
        $res = app(ImportLeviedProducts::class)($this->tenant, ['nope', 'accommodation']);

        $this->assertSame(['Τέλος διαμονής παρεπιδημούντων'], $res['created']);
        $this->assertSame(1, Product::where('company_id', $this->tenant->id)->count());
    }

    public function test_catalogue_only_uses_fees_taxtype(): void
    {
        // Guards the §8.7 assumption — every template is a Fees levy.
        foreach (LeviedProductTemplates::all() as $t) {
            $this->assertIsInt($t['tax_category']);
            $this->assertGreaterThan(0, $t['per_unit']);
        }
        $this->assertSame(2, LeviedProductTemplates::TAX_TYPE_FEES);
    }

    public function test_falls_back_to_any_vat_when_no_default(): void
    {
        // Fresh tenant whose only VAT category is NOT flagged default — the
        // import must still succeed (products.vat_category_id is NOT NULL).
        $tenant = Company::create([
            'name' => 'NoDef OE', 'slug' => 'nd-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $vat = VatCategory::create(['company_id' => $tenant->id, 'description' => '13%', 'rate' => 13, 'is_default' => false]);

        $res = app(ImportLeviedProducts::class)($tenant, ['plastic_bag']);

        $this->assertNull($res['error']);
        $bag = Product::where('company_id', $tenant->id)->where('mydata_tax_category', 8)->firstOrFail();
        $this->assertSame($vat->id, (int) $bag->vat_category_id);
    }

    public function test_no_vat_category_returns_a_graceful_error_not_a_crash(): void
    {
        // From-zero tenant with NO VAT category at all → bail with error='no_vat'
        // (the action notifies «φτιάξε ΦΠΑ πρώτα») instead of an integrity crash.
        $tenant = Company::create([
            'name' => 'Bare OE', 'slug' => 'bare-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);

        $res = app(ImportLeviedProducts::class)($tenant, ['plastic_bag']);

        $this->assertSame('no_vat', $res['error']);
        $this->assertSame([], $res['created']);
        $this->assertSame(0, Product::where('company_id', $tenant->id)->count());
    }

    public function test_filament_action_imports_the_selected_templates(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]));
        Filament::setTenant($this->tenant);

        Livewire::test(ListProducts::class)
            ->callAction('import_levied_templates', data: ['templates' => ['plastic_bag']])
            ->assertHasNoActionErrors();

        $this->assertTrue(
            Product::where('company_id', $this->tenant->id)
                ->where('mydata_tax_category', 8)->exists(),
        );
    }
}
