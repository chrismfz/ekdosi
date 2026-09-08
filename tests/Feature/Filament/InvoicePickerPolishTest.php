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

    public function test_movement_only_9x_types_are_excluded_from_the_invoice_picker(): void
    {
        // 9.x (Δελτία Αποστολής) are movement documents — never the monetary
        // invoice picker (MYD-003), even with show_on_menu=true.
        $monetary = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'Τιμολόγιο',
            'invcount' => 1, 'show_on_menu' => true, 'mydata_type' => '2.1',
        ]);
        $legacyNoType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'LEG', 'name' => 'Legacy',
            'invcount' => 1, 'show_on_menu' => true, 'mydata_type' => null,
        ]);
        $delivery = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΔΑΠ', 'name' => 'Δελτίο Αποστολής',
            'invcount' => 1, 'show_on_menu' => true, 'mydata_type' => '9.3',
        ]);
        // A hypothetical future 9.x must ALSO be excluded (prefix rule, not a fixed
        // set) — so the picker and the builder guard can never drift.
        $futureMovement = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'Δ9', 'name' => 'Μελλοντικό 9.x',
            'invcount' => 1, 'show_on_menu' => true, 'mydata_type' => '9.4',
        ]);

        $keys = array_keys(PickerOptions::invoiceTypeOptions());

        $this->assertContains($monetary->id, $keys);
        $this->assertContains($legacyNoType->id, $keys, 'a null mydata_type stays selectable');
        $this->assertNotContains($delivery->id, $keys, '9.x is excluded from the monetary picker');
        $this->assertNotContains($futureMovement->id, $keys, 'any 9.x is excluded (prefix rule)');
    }

    public function test_scope_monetary_excludes_movement_only_types(): void
    {
        // The ONE shared predicate behind every monetary invoice-type selector
        // (PickerOptions, ViewQuote convert actions, ServiceContractForm renewal
        // type) — so those surfaces can't drift on the 9.x rule (MYD-003).
        $monetary = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'Τιμολόγιο',
            'invcount' => 1, 'mydata_type' => '1.1',
        ]);
        $legacyNoType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'LEG', 'name' => 'Legacy',
            'invcount' => 1, 'mydata_type' => null,
        ]);
        $del93 = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΔΑΠ', 'name' => 'Δελτίο 9.3',
            'invcount' => 1, 'mydata_type' => '9.3',
        ]);
        $del94 = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'Δ9', 'name' => 'Μελλοντικό 9.4',
            'invcount' => 1, 'mydata_type' => '9.4',
        ]);

        $ids = InvoiceType::query()
            ->where('company_id', $this->tenant->id)
            ->monetary()
            ->pluck('id')
            ->all();

        $this->assertContains($monetary->id, $ids);
        $this->assertContains($legacyNoType->id, $ids, 'a null mydata_type stays selectable');
        $this->assertNotContains($del93->id, $ids, '9.3 is a delivery note, not a monetary type');
        $this->assertNotContains($del94->id, $ids, 'any 9.x excluded (prefix rule)');
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

    public function test_customer_picker_lists_whole_small_catalogue_not_capped_at_30(): void
    {
        // 35 plain customers (zero invoices, none favourite). The old on-open list
        // capped at 30 (most-billed) → 5 unreachable without typing. Browse-all
        // returns ALL 35 so the operator can scroll the whole catalogue on open.
        for ($i = 1; $i <= 35; $i++) {
            Customer::create(['company_id' => $this->tenant->id, 'name' => sprintf('Cust %02d', $i)]);
        }

        $this->assertCount(35, PickerOptions::favouriteCustomerOptions());
    }

    public function test_product_picker_lists_whole_small_catalogue_not_capped_at_30(): void
    {
        $cat = ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'C']);
        for ($i = 1; $i <= 35; $i++) {
            $this->makeProduct($cat, sprintf('Prod %02d', $i));
        }

        $this->assertCount(35, PickerOptions::favouriteProductOptions());
    }

    public function test_customer_picker_caps_when_catalogue_exceeds_browse_ceiling(): void
    {
        // Above the browse-all ceiling (200) the on-open list falls back to the
        // capped top slice (30) + search — a 1000-row Select isn't browsable.
        // 201 plain customers (zero invoices, none favourite) → exactly 30 shown.
        for ($i = 1; $i <= 201; $i++) {
            Customer::create(['company_id' => $this->tenant->id, 'name' => sprintf('Cust %03d', $i)]);
        }

        $this->assertCount(30, PickerOptions::favouriteCustomerOptions());
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
