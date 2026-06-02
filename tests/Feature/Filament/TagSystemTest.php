<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Tags\Pages\CreateTag;
use App\Filament\Support\Tags\TagControls;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\Tag;
use App\Models\User;
use App\Models\VatCategory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tenant-scoped tag system: morph attach across all four entities, the
 * pinned-tabs taxonomy (Έξοδα-style), the tag filter predicate, and that
 * the TagResource auto-assigns company_id via Filament tenancy.
 */
class TagSystemTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Tag Co',
            'slug' => 'tag-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
            'afm' => '800000000',
        ]);

        Gate::before(fn () => true);
        $user = User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('x'),
        ]);
        $this->actingAs($user);
        Filament::setTenant($this->tenant);
    }

    public function test_tags_attach_to_each_entity_with_correct_morph_type(): void
    {
        $tag = $this->tag('VIP');

        $customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'C']);
        $supplier = Supplier::create(['company_id' => $this->tenant->id, 'name' => 'S', 'afm' => '1']);
        $product = $this->product('P');
        $invoice = $this->invoice($customer);
        $expense = Expense::create(['company_id' => $this->tenant->id, 'source' => 'manual']);

        $customer->tags()->attach($tag);
        $supplier->tags()->attach($tag);
        $product->tags()->attach($tag);
        $invoice->tags()->attach($tag);
        $expense->tags()->attach($tag);

        $this->assertTrue($customer->fresh()->tags->contains($tag));
        $this->assertTrue($supplier->fresh()->tags->contains($tag));
        $this->assertTrue($product->fresh()->tags->contains($tag));
        $this->assertTrue($invoice->fresh()->tags->contains($tag));
        $this->assertTrue($expense->fresh()->tags->contains($tag));

        // Morph type stored as the FQCN (no morphMap configured).
        $this->assertDatabaseHas('taggables', [
            'tag_id' => $tag->id,
            'taggable_type' => Customer::class,
            'taggable_id' => $customer->id,
        ]);
    }

    public function test_pinned_tabs_only_include_pinned_tags_used_by_that_model(): void
    {
        $pinnedUsed = $this->tag('Χονδρική', pinned: true);
        $pinnedUnused = $this->tag('Πινομένο-αχρησιμοποίητο', pinned: true);
        $unpinnedUsed = $this->tag('Απλό', pinned: false);

        $customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'C']);
        $customer->tags()->attach([$pinnedUsed->id, $unpinnedUsed->id]);

        // A pinned tag used only on a PRODUCT must not pollute the customer tabs.
        $productPinned = $this->tag('Hardware', pinned: true);
        $this->product('P')->tags()->attach($productPinned);

        $tabs = TagControls::pinnedTabs(Customer::class);

        $this->assertSame(
            ['all', 'tag_'.$pinnedUsed->id],
            array_keys($tabs),
        );
        $this->assertArrayNotHasKey('tag_'.$pinnedUnused->id, $tabs);
        $this->assertArrayNotHasKey('tag_'.$unpinnedUsed->id, $tabs);
        $this->assertArrayNotHasKey('tag_'.$productPinned->id, $tabs);
    }

    public function test_tag_tabs_carry_count_badges(): void
    {
        $tag = $this->tag('Χονδρική', pinned: true);
        foreach (['A', 'B', 'C'] as $name) {
            Customer::create(['company_id' => $this->tenant->id, 'name' => $name])
                ->tags()->attach($tag);
        }
        // An untagged customer must not be counted.
        Customer::create(['company_id' => $this->tenant->id, 'name' => 'D']);

        $tabs = TagControls::pinnedTabs(Customer::class);

        $this->assertEquals(3, $tabs['tag_'.$tag->id]->getBadge());
    }

    public function test_tag_filter_predicate_narrows_to_tagged_records(): void
    {
        $tag = $this->tag('VIP');
        $tagged = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Tagged']);
        $untagged = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Untagged']);
        $tagged->tags()->attach($tag);

        // Same predicate the filter()/tab queries use.
        $ids = Customer::query()
            ->whereHas('tags', fn ($q) => $q->whereIn('tags.id', [$tag->id]))
            ->pluck('id')
            ->all();

        $this->assertSame([$tagged->id], $ids);
        $this->assertNotContains($untagged->id, $ids);
    }

    public function test_tag_resource_create_assigns_company_id_via_tenancy(): void
    {
        Livewire::test(CreateTag::class)
            ->fillForm([
                'name' => 'Νέα Ετικέτα',
                'is_pinned' => true,
                'sort_order' => 5,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $tag = Tag::query()->where('name', 'Νέα Ετικέτα')->first();
        $this->assertNotNull($tag);
        $this->assertSame($this->tenant->id, $tag->company_id);
        $this->assertTrue($tag->is_pinned);
    }

    /* ===================== fixtures ===================== */

    private function tag(string $name, bool $pinned = false): Tag
    {
        return Tag::create([
            'company_id' => $this->tenant->id,
            'name' => $name,
            'is_pinned' => $pinned,
        ]);
    }

    private function product(string $name): Product
    {
        $cat = ProductCategory::firstOrCreate(
            ['company_id' => $this->tenant->id, 'description_short' => 'Cat'],
        );
        $vat = VatCategory::firstOrCreate(
            ['company_id' => $this->tenant->id, 'description' => '24%'],
            ['rate' => 24, 'is_default' => true],
        );

        return Product::create([
            'company_id' => $this->tenant->id,
            'description_short' => $name,
            'product_category_id' => $cat->id,
            'vat_category_id' => $vat->id,
            'sell_price' => 10,
            'price_wvat' => 12.4,
        ]);
    }

    private function invoice(Customer $customer): Invoice
    {
        $type = InvoiceType::firstOrCreate(
            ['company_id' => $this->tenant->id, 'code' => 'TPY'],
            ['name' => 'Τιμολόγιο', 'invcount' => 1],
        );

        return Invoice::create([
            'company_id' => $this->tenant->id,
            'invcode' => 'TPY1',
            'code' => 1,
            'invoice_type_id' => $type->id,
            'customer_id' => $customer->id,
            'issued_at' => now(),
            'net_total' => 10,
            'gross_total' => 12.4,
            'header_discount_percent' => 0,
            'mydata_sent' => false,
        ]);
    }
}
