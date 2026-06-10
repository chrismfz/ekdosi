<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\VatCategories\Pages\EditVatCategory;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\VatCategory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GuardedDeleteAction blocks deleting a lookup that is still referenced (a VAT
 * category in use by products) — preventing the silent soft-delete that would
 * leave those products' VAT field blank — and lets an unused one delete.
 */
class GuardedDeleteActionTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->actingAs(User::create(['name' => 'A', 'email' => 'a-'.uniqid().'@t.local', 'password' => bcrypt('x')]));
        $this->tenant = Company::create([
            'name' => 'T', 'slug' => 't-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        Filament::setTenant($this->tenant);
    }

    #[Test]
    public function it_blocks_deleting_a_vat_category_in_use(): void
    {
        $vat = VatCategory::create(['company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24]);
        $cat = ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'Γ']);
        Product::create([
            'company_id' => $this->tenant->id, 'product_category_id' => $cat->id,
            'vat_category_id' => $vat->id, 'description_short' => 'Π', 'sell_price' => 10,
        ]);

        Livewire::test(EditVatCategory::class, ['record' => $vat->getRouteKey()])
            ->callAction('delete');

        // Still there (not even soft-deleted) — the guard halted the action.
        $this->assertDatabaseHas('vat_categories', ['id' => $vat->id, 'deleted_at' => null]);
    }

    #[Test]
    public function it_allows_deleting_an_unused_vat_category(): void
    {
        $vat = VatCategory::create(['company_id' => $this->tenant->id, 'description' => '13%', 'rate' => 13]);

        Livewire::test(EditVatCategory::class, ['record' => $vat->getRouteKey()])
            ->callAction('delete');

        $this->assertSoftDeleted('vat_categories', ['id' => $vat->id]);
    }
}
