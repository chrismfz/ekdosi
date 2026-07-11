<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\InvoiceTypes\Pages\EditInvoiceType;
use App\Filament\Resources\InvoiceTypes\Pages\ListInvoiceTypes;
use App\Filament\Resources\VatCategories\Pages\EditVatCategory;
use App\Filament\Resources\VatCategories\Pages\ListVatCategories;
use App\Models\Company;
use App\Models\InvoiceType;
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

    #[Test]
    public function bulk_delete_skips_in_use_lookups_and_deletes_free_ones(): void
    {
        // SET-2: the bulk path was unguarded. It must PARTIAL-skip in-use rows
        // (leaving them) while deleting the free ones.
        $inUse = VatCategory::create(['company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24]);
        $cat = ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'Γ']);
        Product::create([
            'company_id' => $this->tenant->id, 'product_category_id' => $cat->id,
            'vat_category_id' => $inUse->id, 'description_short' => 'Π', 'sell_price' => 10,
        ]);
        $free = VatCategory::create(['company_id' => $this->tenant->id, 'description' => '13%', 'rate' => 13]);

        Livewire::test(ListVatCategories::class)
            ->callTableBulkAction('delete', [$inUse->getKey(), $free->getKey()]);

        $this->assertDatabaseHas('vat_categories', ['id' => $inUse->id, 'deleted_at' => null]); // skipped
        $this->assertSoftDeleted('vat_categories', ['id' => $free->id]);                         // deleted
    }

    #[Test]
    public function force_delete_bulk_is_hidden_on_the_active_view(): void
    {
        // SET-2 review fix: permanent delete must stay gated behind the trashed
        // view (mirrors the stock ForceDeleteBulkAction) — never one click away
        // on the default active list.
        Livewire::test(ListVatCategories::class)
            ->assertTableBulkActionHidden('forceDelete');
    }

    #[Test]
    public function bulk_force_delete_skips_an_in_use_lookup(): void
    {
        // SET-2: force-delete (permanent) must ALSO refuse an in-use lookup —
        // otherwise a restrictOnDelete FK 500s or a nullOnDelete blanks the ref.
        $inUse = VatCategory::create(['company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24]);
        $cat = ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'Γ']);
        Product::create([
            'company_id' => $this->tenant->id, 'product_category_id' => $cat->id,
            'vat_category_id' => $inUse->id, 'description_short' => 'Π', 'sell_price' => 10,
        ]);
        $inUse->delete();   // soft-delete so it shows on the trashed view (still referenced by the product)

        Livewire::test(ListVatCategories::class)
            ->filterTable('trashed', false)   // «only trashed» → force-delete is visible
            ->callTableBulkAction('forceDelete', [$inUse->getKey()]);

        $this->assertDatabaseHas('vat_categories', ['id' => $inUse->id]);   // still present — force blocked
    }

    #[Test]
    public function it_blocks_an_invoice_type_used_as_a_whmcs_default(): void
    {
        // The non-obvious dependency the review surfaced: an invoice type wired as
        // a tenant's WHMCS auto-issue default (companies.whmcs_default_invoice_type_id).
        $type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΤΠΥ', 'name' => 'ΤΠΥ', 'invcount' => 0,
        ]);
        $this->tenant->forceFill(['whmcs_default_invoice_type_id' => $type->id])->save();

        Livewire::test(EditInvoiceType::class, ['record' => $type->getRouteKey()])
            ->callAction('delete');

        $this->assertDatabaseHas('invoice_types', ['id' => $type->id, 'deleted_at' => null]);
    }

    #[Test]
    public function it_blocks_an_invoice_type_used_as_a_whmcs_receipt_default(): void
    {
        // SET-2 closed a gap: the RECEIPT-type default (whmcs_default_receipt_type_id)
        // was missing from the old single-record map. Now guarded too.
        $type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΑΠΥ', 'name' => 'ΑΠΥ', 'invcount' => 0,
        ]);
        $this->tenant->forceFill(['whmcs_default_receipt_type_id' => $type->id])->save();

        Livewire::test(EditInvoiceType::class, ['record' => $type->getRouteKey()])
            ->callAction('delete');

        $this->assertDatabaseHas('invoice_types', ['id' => $type->id, 'deleted_at' => null]);
    }

    #[Test]
    public function bulk_delete_skips_an_in_use_invoice_type_across_resources(): void
    {
        // Proves the per-resource wiring beyond VatCategory: a restrictOnDelete
        // resource (invoice_types) partial-skips an in-use row on the bulk path.
        $used = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΤΠΥ', 'name' => 'ΤΠΥ', 'invcount' => 0,
        ]);
        $this->tenant->forceFill(['whmcs_default_invoice_type_id' => $used->id])->save();
        $free = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΑΠΥ', 'name' => 'ΑΠΥ', 'invcount' => 0,
        ]);

        Livewire::test(ListInvoiceTypes::class)
            ->callTableBulkAction('delete', [$used->getKey(), $free->getKey()]);

        $this->assertDatabaseHas('invoice_types', ['id' => $used->id, 'deleted_at' => null]); // skipped
        $this->assertSoftDeleted('invoice_types', ['id' => $free->id]);                        // deleted
    }
}
