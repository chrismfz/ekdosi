<?php

namespace Tests\Feature\Pos;

use App\Actions\CreatePosSale;
use App\Actions\PosSaleNotIssued;
use App\Contracts\EInvoiceSubmitter;
use App\Enums\PaymentStatus;
use App\Filament\Pages\PointOfSale;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\VatCategory;
use App\Services\EInvoiceSubmitterFactory;
use App\Services\InvoiceNumberer;
use App\Services\Products\VariantGenerator;
use App\Services\Stock\StockService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * «Ταμείο» (docs/woocommerce-bridge-plan.md §11): a cart becomes an ISSUED 11.1
 * receipt — numbered, active, cash-settled, stock moved — through the normal
 * invoice pipeline; failures leave a draft, never a half-sale.
 */
class PosSaleTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private VatCategory $vat;

    private ProductCategory $category;

    private InvoiceType $receipt;

    private PaymentMethod $cash;

    protected function setUp(): void
    {
        parent::setUp();

        // A non-filing tenant: issuing = numbering at finalize (no AADE round-trip).
        $this->tenant = Company::create(['name' => 'Κατάστημα', 'slug' => 'pos-'.uniqid(), 'country_code' => 'GR', 'einvoice_provider' => 'none']);
        $this->vat = VatCategory::create(['company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
        $this->category = ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'Ένδυση', 'markup' => 0]);
        $this->receipt = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΑΛΠ', 'name' => 'Απόδειξη Λιανικής Πώλησης', 'invcount' => 1, 'mydata_type' => '11.1', 'show_on_menu' => true]);
        $this->cash = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Μετρητά', 'due_days' => 0, 'mydata_payment_type' => 3]);
        $this->tenant->update(['pos_enabled' => true, 'pos_invoice_type_id' => $this->receipt->id, 'pos_payment_method_id' => $this->cash->id]);
    }

    public function test_a_cart_becomes_an_issued_cash_settled_receipt_and_moves_stock(): void
    {
        $shirt = $this->product('Μπλούζα', 20.00, ['track_stock' => true]);
        app(StockService::class)->record($shirt, 10, StockMovement::REASON_RECEIPT);
        $socks = $this->product('Κάλτσες', 4.03);

        $invoice = app(CreatePosSale::class)($this->tenant->fresh(), [
            ['product_id' => $shirt->id, 'qty' => 2, 'discount' => 10],
            ['product_id' => $socks->id, 'qty' => 3],
        ]);

        $this->assertSame('active', $invoice->local_status);
        $this->assertNotNull($invoice->code, 'numbered');
        $this->assertSame($this->receipt->id, $invoice->invoice_type_id);
        $this->assertNull($invoice->customer_id, 'anonymous retail');
        $this->assertSame($this->cash->id, $invoice->payment_method_id);

        // The till's preview math == what the receipt stores, line by line.
        $expected = CreatePosSale::lineTotals($shirt, 2, 10)['gross'] + CreatePosSale::lineTotals($socks, 3)['gross'];
        $this->assertSame(round($expected, 2), (float) $invoice->gross_total);
        $this->assertSame(44.64 + 14.99, (float) $invoice->gross_total);

        $this->assertSame(PaymentStatus::Paid->value, (string) ($invoice->fresh()->payment_status?->value ?? $invoice->fresh()->payment_status), 'cash is settled at issue — in the CACHE the lists read');
        $this->assertSame(8.0, app(StockService::class)->currentStock($shirt));
    }

    public function test_what_a_till_will_not_sell(): void
    {
        [$parent] = $this->variableWithSizes();
        $inactive = $this->product('Παλιό', 5, ['is_active' => false]);
        $other = Company::create(['name' => 'Άλλη', 'slug' => 'pos-o-'.uniqid(), 'country_code' => 'GR']);
        $foreign = Product::create(['company_id' => $other->id, 'description_short' => 'Ξένο', 'product_category_id' => ProductCategory::create(['company_id' => $other->id, 'description_short' => 'Χ', 'markup' => 0])->id, 'vat_category_id' => VatCategory::create(['company_id' => $other->id, 'description' => '24', 'rate' => 24])->id, 'sell_price' => 1]);

        foreach ([$parent->id, $inactive->id, $foreign->id] as $productId) {
            $this->assertRefused(fn () => app(CreatePosSale::class)($this->tenant->fresh(), [['product_id' => $productId, 'qty' => 1]]));
        }
        $this->assertRefused(fn () => app(CreatePosSale::class)($this->tenant->fresh(), []));
        $this->assertSame(0, Invoice::count(), 'a refused cart leaves nothing behind');

        $this->tenant->update(['pos_enabled' => false]);
        $this->assertRefused(fn () => app(CreatePosSale::class)($this->tenant->fresh(), [['product_id' => $this->product('Χ', 1)->id, 'qty' => 1]]));

        $this->tenant->update(['pos_enabled' => true, 'pos_invoice_type_id' => null]);
        $this->assertRefused(fn () => app(CreatePosSale::class)($this->tenant->fresh(), [['product_id' => $this->product('Ψ', 1)->id, 'qty' => 1]]));
    }

    public function test_a_filing_tenant_issues_through_the_submitter(): void
    {
        $this->tenant->update(['einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox']);
        $submitter = \Mockery::mock(EInvoiceSubmitter::class);
        $submitter->shouldReceive('submit')->once()->andReturnUsing(function (Invoice $invoice): ?MyDataMark {
            app(InvoiceNumberer::class)->assign($invoice);
            $invoice->forceFill(['local_status' => 'active', 'mydata_mark' => '400000000000123', 'mydata_url' => 'https://example.test/qr'])->save();

            return null;
        });
        $this->fakeSubmitter($submitter);

        $invoice = app(CreatePosSale::class)($this->tenant->fresh(), [['product_id' => $this->product('Μπλούζα', 20)->id, 'qty' => 1]]);

        $this->assertSame('active', $invoice->local_status);
        $this->assertSame('400000000000123', $invoice->mydata_mark);
    }

    public function test_a_failed_filing_leaves_a_draft_and_says_which(): void
    {
        $this->tenant->update(['einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox']);
        $submitter = \Mockery::mock(EInvoiceSubmitter::class);
        $submitter->shouldReceive('submit')->andThrow(new RuntimeException('provider down'));
        $this->fakeSubmitter($submitter);

        try {
            app(CreatePosSale::class)($this->tenant->fresh(), [['product_id' => $this->product('Μπλούζα', 20)->id, 'qty' => 1]]);
            $this->fail('expected the failure to surface');
        } catch (PosSaleNotIssued $e) {
            $draft = Invoice::sole();
            $this->assertSame('draft', $draft->local_status);
            $this->assertSame($draft->id, $e->invoiceId);
            $this->assertStringContainsString('provider down', $e->getMessage());
            $this->assertStringContainsString('#'.$draft->id, $e->getMessage());
        }
    }

    public function test_a_failed_issue_empties_the_till_so_the_sale_is_never_rung_twice(): void
    {
        // A timed-out filing may already be at AADE (in-doubt; no dedup there): a second
        // «Έκδοση» of the same cart would be a SECOND legal receipt for one sale.
        $this->tenant->update(['einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox']);
        $submitter = \Mockery::mock(EInvoiceSubmitter::class);
        $submitter->shouldReceive('submit')->once()->andThrow(new RuntimeException('timeout'));
        $this->fakeSubmitter($submitter);
        $shirt = $this->product('Μπλούζα', 20);
        $this->operator();

        Livewire::test(PointOfSale::class)
            ->call('choose', $shirt->id)
            ->call('checkout')
            ->assertSet('cart', [])
            ->assertDispatched('pos-print-cancel')
            ->assertNotDispatched('pos-print')
            ->call('checkout');   // nothing left to ring → no second document

        $this->assertSame(1, Invoice::count());
    }

    public function test_a_refusal_before_anything_is_created_keeps_the_cart(): void
    {
        // Its VAT category was deleted (soft) — the till must not guess a rate.
        $gone = VatCategory::create(['company_id' => $this->tenant->id, 'description' => '13%', 'rate' => 13]);
        $noVat = $this->product('Χωρίς ΦΠΑ', 5, ['vat_category_id' => $gone->id]);
        $gone->delete();
        $this->operator();

        Livewire::test(PointOfSale::class)
            ->call('choose', $noVat->id)
            ->call('checkout')
            ->assertCount('cart', 1);

        $this->assertSame(0, Invoice::count(), 'no guessed VAT rate on a legal receipt');
    }

    public function test_the_till_total_includes_product_levies_like_the_receipt(): void
    {
        // The plastic bag: a per-unit fee (taxType 2) on top of its VAT.
        $bag = $this->product('Σακούλα', 0.01, ['mydata_tax_type' => 2, 'mydata_tax_category' => 8, 'mydata_tax_per_unit' => 0.07]);
        $shirt = $this->product('Μπλούζα', 20);
        $this->operator();

        $page = Livewire::test(PointOfSale::class)
            ->call('choose', $shirt->id)
            ->call('choose', $bag->id)
            ->call('increment', 1);
        $this->assertSame(24.96, $page->instance()->total);   // 24,80 + 2 bags (0,02 + 2 × 0,07 fee)

        $page->set('tendered', '30')->call('checkout');
        $invoice = Invoice::sole();
        $this->assertSame(24.96, $invoice->payableTotal(), 'the till charged what the receipt says');
    }

    public function test_the_till_screen_scans_picks_variants_and_issues(): void
    {
        [$parent, $m] = $this->variableWithSizes();
        $m->update(['barcode' => '5201234567890']);
        $simple = $this->product('Ζώνη', 10.00);
        $this->operator();

        Livewire::test(PointOfSale::class)
            ->assertOk()
            ->call('scanCode', '5201234567890')                       // a variant's barcode → straight to the cart
            ->assertSet('cart.0.product_id', $m->id)
            ->call('scanCode', 'NK-PANT')                             // the parent's SKU → variant picker
            ->assertSet('pickParent', $parent->id)
            ->call('choose', $m->id)                                 // same variant again → qty 2
            ->assertSet('cart.0.qty', 2.0)
            ->call('choose', $simple->id)
            ->assertCount('cart', 2)
            ->set('tendered', '100')
            ->call('checkout')
            ->assertSet('cart', [])
            ->assertDispatched('pos-print');

        $invoice = Invoice::sole();
        $this->assertSame('active', $invoice->local_status);
        $this->assertSame(2, $invoice->lines()->count());
    }

    public function test_the_last_receipt_id_cannot_be_set_from_the_browser(): void
    {
        $this->operator();
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(PointOfSale::class)->set('lastInvoiceId', 999);
    }

    public function test_the_till_is_hidden_without_the_switch_or_the_permission(): void
    {
        $this->operator();
        $this->assertTrue(PointOfSale::canAccess());

        $this->tenant->update(['pos_enabled' => false]);
        Filament::setTenant($this->tenant->fresh());
        $this->assertFalse(PointOfSale::canAccess());
    }

    public function test_the_receipt_prints_for_the_companys_operators_only(): void
    {
        $invoice = app(CreatePosSale::class)($this->tenant->fresh(), [['product_id' => $this->product('Μπλούζα', 20)->id, 'qty' => 1]]);
        $url = URL::temporarySignedRoute('pos.receipt', now()->addMinutes(5), ['invoice' => $invoice->id]);

        $this->get($url)->assertRedirect();   // guest

        // A REAL team-scoped cashier (no Gate::before): the route is outside the panel,
        // so the controller must scope Spatie's team id itself.
        $registrar = app(PermissionRegistrar::class);
        Permission::findOrCreate('View:PointOfSale', 'web');
        $cashier = User::create(['name' => 'Ταμίας', 'email' => 'c-'.uniqid().'@e.test', 'password' => bcrypt('x')]);
        $this->tenant->users()->attach($cashier);
        $registrar->setPermissionsTeamId($this->tenant->id);
        $cashier->givePermissionTo('View:PointOfSale');
        $registrar->forgetCachedPermissions();
        $registrar->setPermissionsTeamId(null);
        $this->actingAs($cashier)->get($url)->assertOk()->assertSee($invoice->invcode)->assertSee('24,80');

        // …but only the TILL's receipts: another document of the company needs View:Invoice.
        $other = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΤΠΥ', 'name' => 'Τιμολόγιο', 'invcount' => 1, 'mydata_type' => '2.1']);
        $invoice->forceFill(['invoice_type_id' => $other->id])->save();
        $this->actingAs($cashier)->get($url)->assertForbidden();
        $invoice->forceFill(['invoice_type_id' => $this->receipt->id])->save();

        Gate::before(fn () => true);
        $outsider = User::create(['name' => 'X', 'email' => 'x-'.uniqid().'@e.test', 'password' => bcrypt('x')]);
        $this->actingAs($outsider)->get($url)->assertForbidden();

        $operator = $this->operator();
        $this->actingAs($operator)->get($url)->assertOk();
        $this->actingAs($operator)->get(route('pos.receipt', ['invoice' => $invoice->id]))->assertForbidden();   // unsigned

        $invoice->update(['local_status' => 'draft']);
        $this->actingAs($operator)->get($url)->assertNotFound();   // never a draft
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function assertRefused(callable $call): void
    {
        try {
            $call();
            $this->fail('expected a refusal');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }

    private function fakeSubmitter(EInvoiceSubmitter $submitter): void
    {
        $factory = \Mockery::mock(EInvoiceSubmitterFactory::class);
        $factory->shouldReceive('for')->andReturn($submitter);
        $this->app->instance(EInvoiceSubmitterFactory::class, $factory);
    }

    private function operator(): User
    {
        Gate::before(fn () => true);
        $user = User::create(['name' => 'Ταμίας', 'email' => 'pos-'.uniqid().'@e.test', 'password' => bcrypt('x')]);
        $this->tenant->users()->attach($user);
        $this->actingAs($user);
        Filament::setTenant($this->tenant->fresh());

        return $user;
    }

    private function product(string $name, float $net, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'company_id' => $this->tenant->id,
            'description_short' => $name,
            'product_category_id' => $this->category->id,
            'vat_category_id' => $this->vat->id,
            'sell_price' => $net,
            'price_wvat' => round($net * 1.24, 2),
        ], $overrides));
    }

    /** @return array{0: Product, 1: Product} the variable parent + its «M» variant */
    private function variableWithSizes(): array
    {
        $size = ProductAttribute::create(['company_id' => $this->tenant->id, 'name' => 'Μέγεθος', 'kind' => ProductAttribute::KIND_SIZE]);
        foreach (['S', 'M'] as $i => $v) {
            ProductAttributeValue::create(['company_id' => $this->tenant->id, 'product_attribute_id' => $size->id, 'value' => $v, 'sort' => $i]);
        }
        $parent = $this->product('Παντελόνι', 30, ['kind' => Product::KIND_VARIABLE, 'sku' => 'NK-PANT']);
        app(VariantGenerator::class)->generate($parent, [$size->id => $size->values()->pluck('id')->all()]);

        return [$parent, $parent->variants()->get()->first(fn (Product $v) => str_ends_with($v->description_short, 'M'))];
    }
}
