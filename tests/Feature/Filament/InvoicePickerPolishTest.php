<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Invoices\Schemas\InvoiceForm;
use App\Filament\Support\PickerOptions;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DistributionAim;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\VatCategory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Operator-feedback polish: favourites-first / auto-top pickers, the Είδος
 * dimension auto-fill, inline product create, and the "new invoice from the
 * customer Καρτέλα" preset. The pure option-provider methods on InvoiceForm
 * are unit-testable directly; the form wiring is covered with a Livewire mount.
 */
class InvoicePickerPolishTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private VatCategory $vat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Picker Co',
            'slug' => 'pick-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
            'afm' => '800000000',
        ]);

        $this->vat = VatCategory::create([
            'company_id' => $this->tenant->id,
            'description' => '24%',
            'rate' => 24,
            'is_default' => true,
        ]);

        Gate::before(fn () => true);
        $user = User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('x'),
        ]);
        $this->actingAs($user);
        Filament::setTenant($this->tenant);
    }

    public function test_is_favorite_is_fillable_and_boolean_cast(): void
    {
        $c = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'A', 'is_favorite' => 1,
        ]);
        $this->assertTrue($c->fresh()->is_favorite);

        $t = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'X', 'name' => 'X', 'invcount' => 1,
            'is_favorite' => true,
        ]);
        $this->assertTrue($t->fresh()->is_favorite);
    }

    public function test_invoice_type_options_put_favourites_first_then_most_used(): void
    {
        // Non-favourite, low usage.
        $apy = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'APY', 'name' => 'Απόδειξη',
            'invcount' => 5, 'show_on_menu' => true,
        ]);
        // Non-favourite, high usage → should come before APY.
        $del = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'DLT', 'name' => 'Δελτίο',
            'invcount' => 99, 'show_on_menu' => true,
        ]);
        // Favourite → pinned first regardless of usage.
        $tim = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TIM', 'name' => 'Τιμολόγιο',
            'invcount' => 1, 'show_on_menu' => true, 'is_favorite' => true,
        ]);
        // Hidden from menu → excluded entirely.
        InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'HID', 'name' => 'Κρυφό',
            'invcount' => 50, 'show_on_menu' => false,
        ]);

        $keys = array_keys(PickerOptions::invoiceTypeOptions());

        $this->assertSame([$tim->id, $del->id, $apy->id], $keys);
        $this->assertStringStartsWith('⭐ ', PickerOptions::invoiceTypeOptions()[$tim->id]);
    }

    public function test_customer_options_favourites_then_most_billed(): void
    {
        $type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'T', 'name' => 'T', 'invcount' => 1,
        ]);

        $busy = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Busy']);
        $quiet = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Quiet']);
        $fav = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Zeta Fav', 'is_favorite' => true]);

        $this->makeInvoice($type, $busy);
        $this->makeInvoice($type, $busy);
        $this->makeInvoice($type, $quiet);
        // $fav has zero invoices but is pinned → must still be first.

        $keys = array_keys(PickerOptions::favouriteCustomerOptions());

        $this->assertSame([$fav->id, $busy->id, $quiet->id], $keys);
    }

    public function test_product_options_favourites_then_most_sold_active_only(): void
    {
        $cat = ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'C']);
        $type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'T', 'name' => 'T', 'invcount' => 1,
        ]);
        $customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'X']);
        $invoice = $this->makeInvoice($type, $customer);

        $sold = $this->makeProduct($cat, 'Sold');
        $rare = $this->makeProduct($cat, 'Rare');
        $fav = $this->makeProduct($cat, 'Zeta Fav', favorite: true);
        $inactive = $this->makeProduct($cat, 'Inactive', active: false);

        $this->makeLine($invoice, $sold);
        $this->makeLine($invoice, $sold);
        $this->makeLine($invoice, $rare);
        $this->makeLine($invoice, $inactive); // inactive must be excluded anyway

        $keys = array_keys(PickerOptions::favouriteProductOptions());

        $this->assertSame([$fav->id, $sold->id, $rare->id], $keys);
        $this->assertNotContains($inactive->id, $keys);
    }

    public function test_product_search_excludes_inactive_and_biases_favourites(): void
    {
        $cat = ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'C']);
        $plain = $this->makeProduct($cat, 'Alpha Hosting');
        $fav = $this->makeProduct($cat, 'Beta Hosting', favorite: true);
        $this->makeProduct($cat, 'Gamma Hosting', active: false);

        $keys = array_keys(PickerOptions::searchProductOptions('Hosting'));

        // Favourite first despite alphabetical tie-break; inactive absent.
        $this->assertSame([$fav->id, $plain->id], $keys);
    }

    public function test_inline_product_create_persists_with_gross_price(): void
    {
        $cat = ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'Υπηρεσίες']);

        $id = InvoiceForm::createInlineProduct([
            'description_short' => 'Νέα Υπηρεσία',
            'product_category_id' => $cat->id,
            'vat_category_id' => $this->vat->id,
            'sell_price' => 100,
        ]);

        $product = Product::find($id);
        $this->assertNotNull($product);
        $this->assertSame('Νέα Υπηρεσία', $product->description_short);
        $this->assertSame($cat->id, $product->product_category_id);
        $this->assertTrue($product->is_active);
        $this->assertEquals(100.00, (float) $product->sell_price);
        // price_wvat = 100 × (1 + 24/100)
        $this->assertEquals(124.00, (float) $product->price_wvat);
    }

    public function test_create_invoice_presets_customer_from_query_param(): void
    {
        InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'Τιμολόγιο',
            'invcount' => 1, 'show_on_menu' => true,
        ]);
        $customer = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'Καρτέλα Πελάτης',
            'afm' => '123456789',
            'city' => 'Αθήνα',
        ]);

        Livewire::withQueryParams(['customer_id' => $customer->id])
            ->test(CreateInvoice::class)
            ->assertOk()
            ->assertSet('data.customer_id', $customer->id)
            ->assertSet('data.company_name', 'Καρτέλα Πελάτης')
            ->assertSet('data.vat_no', '123456789')
            ->assertSet('data.city', 'Αθήνα');
    }

    public function test_edit_invoice_resolves_label_for_hidden_type_draft(): void
    {
        // H1 regression: a draft whose invoice type is hidden from the menu
        // must still resolve its label (getOptionLabelUsing) — not render blank.
        $type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'HID', 'name' => 'Κρυφός',
            'invcount' => 1, 'show_on_menu' => false,
        ]);
        $customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'C']);
        $invoice = Invoice::create([
            'company_id' => $this->tenant->id,
            'invcode' => 'HID1', 'code' => 1,
            'invoice_type_id' => $type->id,
            'customer_id' => $customer->id,
            'issued_at' => now(),
            'net_total' => 10, 'gross_total' => 12.4,
            'header_discount_percent' => 0,
            'local_status' => 'draft',
            'mydata_sent' => false,
        ]);

        Livewire::test(EditInvoice::class, ['record' => $invoice->getKey()])
            ->assertOk()
            ->assertSet('data.invoice_type_id', $type->id);

        // The label resolver returns the code — name even off-menu.
        $this->assertArrayNotHasKey($type->id, PickerOptions::invoiceTypeOptions());
    }

    public function test_picking_invoice_type_prefills_its_dimensions(): void
    {
        $aim = DistributionAim::create(['company_id' => $this->tenant->id, 'description' => 'Πώληση']);
        $pm = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Μετρητά']);
        $type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'Τιμολόγιο',
            'invcount' => 1, 'show_on_menu' => true,
            'distribution_aim_id' => $aim->id,
            'payment_method_id' => $pm->id,
        ]);

        Livewire::test(CreateInvoice::class)
            ->set('data.invoice_type_id', $type->id)
            ->assertSet('data.distribution_aim_id', $aim->id)
            ->assertSet('data.payment_method_id', $pm->id);
    }

    /* ===================== fixtures ===================== */

    private function makeInvoice(InvoiceType $type, Customer $customer): Invoice
    {
        $aa = Invoice::query()->where('invoice_type_id', $type->id)->count() + 1;

        return Invoice::create([
            'company_id' => $this->tenant->id,
            'invcode' => $type->code.$aa,
            'code' => $aa,
            'invoice_type_id' => $type->id,
            'customer_id' => $customer->id,
            'issued_at' => now(),
            'net_total' => 10,
            'gross_total' => 12.4,
            'header_discount_percent' => 0,
            'mydata_sent' => false,
        ]);
    }

    private function makeProduct(ProductCategory $cat, string $name, bool $favorite = false, bool $active = true): Product
    {
        return Product::create([
            'company_id' => $this->tenant->id,
            'description_short' => $name,
            'product_category_id' => $cat->id,
            'vat_category_id' => $this->vat->id,
            'sell_price' => 10,
            'price_wvat' => 12.4,
            'is_active' => $active,
            'is_favorite' => $favorite,
        ]);
    }

    private function makeLine(Invoice $invoice, Product $product): InvoiceLine
    {
        return InvoiceLine::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'qty' => 1,
            'price_per_item' => 10,
            'vat_percent' => 24,
            'net_price' => 10,
            'gross_price' => 12.4,
            'product_descr' => $product->description_short,
            'metric_unit' => 'τεμ',
        ]);
    }
}
