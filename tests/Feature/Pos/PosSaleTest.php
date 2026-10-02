<?php

namespace Tests\Feature\Pos;

use App\Actions\CreatePosSale;
use App\Actions\PosSaleNotIssued;
use App\Contracts\EInvoiceSubmitter;
use App\Enums\PaymentStatus;
use App\Filament\Pages\PointOfSale;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
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
        // POS-2: the till sells at the SHELF price — socks tagged 5,00 (net 4,03) are
        // 3 × 5,00 = 15,00 exactly (net-anchored math would give 14,99).
        $this->assertSame(44.64 + 15.00, (float) $invoice->gross_total);

        $this->assertSame(PaymentStatus::Paid->value, (string) ($invoice->fresh()->payment_status?->value ?? $invoice->fresh()->payment_status), 'cash is settled at issue — in the CACHE the lists read');
        $this->assertSame(8.0, app(StockService::class)->currentStock($shirt));
    }

    public function test_a_ten_euro_tag_charges_ten_euros(): void
    {
        // The whole point of POS-2: net-anchored math can't reach 10,00 @24% (8,06 → 9,99).
        $tee = $this->product('Μπλουζάκι', 8.06, ['price_wvat' => 10.00]);
        $open = $this->product('ΡΟΥΧΑ 24%', 0, ['pos_open_price' => true]);

        $invoice = app(CreatePosSale::class)($this->tenant->fresh(), [
            ['product_id' => $tee->id, 'qty' => 3],
            ['product_id' => $open->id, 'qty' => 1, 'price' => 10.00],
        ]);

        $this->assertSame(40.00, (float) $invoice->gross_total);
        $this->assertSame(32.25, (float) $invoice->net_total);   // 30,00 → 24,19 + 10,00 → 8,06
        $this->assertSame(['10.00', '10.00'], $invoice->lines()->orderBy('id')->pluck('gross_unit_price')->all());
        $this->assertSame(CreatePosSale::lineTotals($tee, 3)['gross'] + CreatePosSale::lineTotals($open, 1, 0.0, 10.00)['gross'], (float) $invoice->gross_total, 'screen == receipt');
    }

    public function test_a_product_without_a_shelf_price_sells_at_its_net_grossed_up(): void
    {
        $legacy = $this->product('Παλιό είδος', 20.00, ['price_wvat' => 0]);

        $invoice = app(CreatePosSale::class)($this->tenant->fresh(), [['product_id' => $legacy->id, 'qty' => 1]]);

        $this->assertSame(24.80, (float) $invoice->gross_total);
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

    public function test_editing_a_cart_line_does_not_steal_focus_to_the_scan_field(): void
    {
        // A blur from «qty» into «έκπτ.» lands in updatedCart — refocusing the scan
        // field there would send the discount the cashier types to scanCode().
        $this->operator();
        Livewire::test(PointOfSale::class)
            ->call('choose', $this->product('Μπλούζα', 20)->id)
            ->set('cart.0.qty', 3)
            ->assertNotDispatched('pos-focus')
            ->assertSet('cart.0.qty', 3.0);
    }

    public function test_a_step_failing_after_the_filing_committed_is_still_a_sale(): void
    {
        $this->tenant->update(['einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox']);
        $submitter = \Mockery::mock(EInvoiceSubmitter::class);
        $submitter->shouldReceive('submit')->once()->andReturnUsing(function (Invoice $invoice): never {
            app(InvoiceNumberer::class)->assign($invoice);
            $invoice->forceFill(['local_status' => 'active', 'mydata_mark' => '400000000000999'])->save();
            throw new RuntimeException('queue insert failed after the MARK');
        });
        $this->fakeSubmitter($submitter);

        $invoice = app(CreatePosSale::class)($this->tenant->fresh(), [['product_id' => $this->product('Μπλούζα', 20)->id, 'qty' => 1]]);

        $this->assertSame('active', $invoice->local_status, 'issued → the till prints it, never «δεν εκδόθηκε»');
    }

    public function test_a_refusal_before_anything_is_created_keeps_the_cart(): void
    {
        $gone = $this->product('Αποσυρμένο', 5, ['is_active' => false]);
        $this->operator();

        Livewire::test(PointOfSale::class)
            ->set('cart', [['product_id' => $gone->id, 'qty' => 1, 'discount' => 0]])
            ->call('checkout')
            ->assertCount('cart', 1);

        $this->assertSame(0, Invoice::count());
    }

    public function test_a_retired_vat_category_is_refused_not_guessed(): void
    {
        // A soft-deleted VAT category may be retired BECAUSE its rate is wrong — the
        // till refuses (cart kept) like every other surface ignores it; never a guess.
        $retired = VatCategory::create(['company_id' => $this->tenant->id, 'description' => '13%', 'rate' => 13]);
        $product = $this->product('Βιβλίο', 10, ['vat_category_id' => $retired->id]);
        $retired->delete();

        $this->assertRefused(fn () => app(CreatePosSale::class)($this->tenant->fresh(), [['product_id' => $product->id, 'qty' => 1]]));
        $this->assertSame(0, Invoice::count());
    }

    public function test_a_zero_total_receipt_is_refused(): void
    {
        $shirt = $this->product('Μπλούζα', 20);

        $this->assertRefused(fn () => app(CreatePosSale::class)($this->tenant->fresh(), [['product_id' => $shirt->id, 'qty' => 1, 'discount' => 100]]));
        $this->assertSame(0, Invoice::count());
    }

    public function test_typed_amounts_are_parsed_strictly(): void
    {
        foreach (['24,90' => 24.90, '24.90' => 24.90, '24' => 24.0, '1.250,00' => 1250.0, '1.250,50' => 1250.50, '€ 9,99' => 9.99] as $typed => $expected) {
            $this->assertSame($expected, PointOfSale::parseAmount($typed), $typed);
        }
        foreach (['1.250', '1,250', '12,5,0', '24.', 'abc', '', '0,004'] as $typed) {   // «1.250»: 1250 or 1,25? refused
            $this->assertNull(PointOfSale::parseAmount($typed), $typed);
        }
    }

    public function test_the_open_price_prompt_cannot_be_skipped_or_fed_a_scan(): void
    {
        $clothes = $this->product('ΡΟΥΧΑ 24%', 0, ['pos_open_price' => true]);
        $belt = $this->product('Ζώνη', 10, ['barcode' => '5201234567890']);
        $this->operator();

        Livewire::test(PointOfSale::class)
            ->call('choose', $belt->id)
            ->call('choose', $clothes->id)
            ->set('promptPrice', '24,90')
            ->call('checkout')                                   // typed but not added → no sale
            ->assertSet('pricePrompt', $clothes->id)
            ->set('promptPrice', '5201234567890')->call('addOpenPrice')   // a scan in the price box
            ->assertSet('pricePrompt', null)
            ->assertSet('cart.0.qty', 2.0)                       // → the belt, not a €5bn line
            ->assertCount('cart', 1);

        $this->assertSame(0, Invoice::count());
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

    public function test_an_open_price_item_sells_at_the_typed_price_and_nothing_else_does(): void
    {
        $clothes = $this->product('ΡΟΥΧΑ 24%', 0, ['pos_open_price' => true]);
        $shirt = $this->product('Μπλούζα', 20);

        $invoice = app(CreatePosSale::class)($this->tenant->fresh(), [
            ['product_id' => $clothes->id, 'qty' => 1, 'price' => 24.90],
            ['product_id' => $clothes->id, 'qty' => 2, 'price' => '12.40'],
            ['product_id' => $shirt->id, 'qty' => 1, 'price' => 1.00],   // a catalogue item ignores a typed price
        ]);

        $lines = $invoice->lines()->orderBy('id')->get();
        $this->assertSame(20.08, (float) $lines[0]->price_per_item);     // 24,90 / 1,24
        $this->assertSame(24.90, (float) $lines[0]->gross_price);
        $this->assertSame(24.80, (float) $lines[1]->gross_price);
        $this->assertSame(20.00, (float) $lines[2]->price_per_item);
        $this->assertSame(24.90 + 24.80 + 24.80, (float) $invoice->gross_total);

        $this->assertRefused(fn () => app(CreatePosSale::class)($this->tenant->fresh(), [['product_id' => $clothes->id, 'qty' => 1]]));
    }

    public function test_the_till_shows_favourites_and_prompts_for_an_open_price(): void
    {
        $clothes = $this->product('ΡΟΥΧΑ 24%', 0, ['pos_open_price' => true, 'is_favorite' => true]);
        $this->product('Ζώνη', 10, ['is_favorite' => true]);
        $this->product('Κρυφό', 10);
        $this->operator();

        $page = Livewire::test(PointOfSale::class)
            ->assertSee('Αγαπημένα')->assertSee('ΡΟΥΧΑ 24%')->assertSee('Ζώνη')->assertDontSee('Κρυφό')
            ->call('choose', $clothes->id)
            ->assertSet('pricePrompt', $clothes->id)
            ->assertDispatched('pos-price-focus')
            ->assertCount('cart', 0)
            ->set('promptPrice', '0')->call('addOpenPrice')
            ->assertCount('cart', 0)                                 // no zero-price line
            ->set('promptPrice', '24,90')->call('addOpenPrice')
            ->assertSet('pricePrompt', null)
            ->call('choose', $clothes->id)->set('promptPrice', '9,90')->call('addOpenPrice')
            ->assertCount('cart', 2);                                // two prices never merge
        $this->assertSame(34.80, $page->instance()->total);

        $page->call('checkout');
        $this->assertSame(34.80, (float) Invoice::sole()->gross_total);
    }

    public function test_an_issued_receipt_can_be_reprinted_at_80mm_from_the_invoice(): void
    {
        // Paper out / jam at the till → «Απόδειξη 80mm» on the invoice page.
        $invoice = app(CreatePosSale::class)($this->tenant->fresh(), [['product_id' => $this->product('Μπλούζα', 20)->id, 'qty' => 1]]);
        $this->operator();

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
            ->assertActionVisible('receipt_80mm');

        $this->tenant->update(['pos_enabled' => false]);   // no till → no thermal receipt button
        Filament::setTenant($this->tenant->fresh());
        Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
            ->assertActionHidden('receipt_80mm');
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
