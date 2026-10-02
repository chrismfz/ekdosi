<?php

namespace Tests\Feature\Pos;

use App\Actions\CreatePosReturn;
use App\Actions\CreatePosSale;
use App\Actions\PosExchangeIncomplete;
use App\Filament\Pages\PointOfSale;
use App\Http\Controllers\PosReceiptController;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\VatCategory;
use App\Services\Pos\ReceiptLookup;
use App\Services\Stock\StockService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * «Ταμείο» returns / exchanges (POS PR 2a): the original receipt is found by its
 * barcode / ΜΑΡΚ / number, the returned quantities become a correlated retail credit
 * note (11.4, issued on the spot, stock back, the exact shelf price refunded), and an
 * exchange adds the new sale — the customer pays or gets back the difference.
 */
class PosReturnTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private VatCategory $vat;

    private ProductCategory $category;

    private InvoiceType $receipt;

    private InvoiceType $credit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create(['name' => 'Κατάστημα', 'slug' => 'ret-'.uniqid(), 'country_code' => 'GR', 'einvoice_provider' => 'none']);
        $this->vat = VatCategory::create(['company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
        $this->category = ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'Ένδυση', 'markup' => 0]);
        $this->receipt = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΑΛΠ', 'name' => 'Απόδειξη Λιανικής Πώλησης', 'invcount' => 1, 'mydata_type' => '11.1']);
        $this->credit = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΠΙΛ', 'name' => 'Πιστωτικό Λιανικής', 'invcount' => 1, 'mydata_type' => '11.4', 'is_credit' => true]);
        $cash = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Μετρητά', 'due_days' => 0, 'mydata_payment_type' => 3]);
        $this->tenant->update(['pos_enabled' => true, 'pos_invoice_type_id' => $this->receipt->id, 'pos_payment_method_id' => $cash->id, 'pos_credit_type_id' => $this->credit->id]);
    }

    public function test_a_return_issues_a_correlated_credit_note_refunds_the_shelf_price_and_restocks(): void
    {
        $tee = $this->product('Μπλουζάκι', 8.06, ['price_wvat' => 10.00, 'track_stock' => true]);
        app(StockService::class)->record($tee, 5, StockMovement::REASON_RECEIPT);
        $sale = $this->sell([['product_id' => $tee->id, 'qty' => 2]]);
        $this->assertSame(3.0, app(StockService::class)->currentStock($tee));

        ['credit' => $credit, 'sale' => $none] = app(CreatePosReturn::class)($this->tenant->fresh(), $sale, [$sale->lines()->first()->id => 1]);

        $this->assertNull($none);
        $this->assertSame('active', $credit->local_status);
        $this->assertSame($this->credit->id, $credit->invoice_type_id);
        $this->assertSame($sale->id, $credit->credited_invoice_id);
        $this->assertSame(10.00, (float) $credit->payableTotal(), 'exactly the shelf price back');
        $this->assertSame(4.0, app(StockService::class)->currentStock($tee), 'the returned item is back on the shelf');
        $this->assertSame(1.0, CreatePosReturn::returnableLines($sale->fresh())[0]['remaining']);
    }

    public function test_an_exchange_returns_and_sells_in_one_step(): void
    {
        $tee = $this->product('Μπλουζάκι', 8.06, ['price_wvat' => 10.00]);
        $shirt = $this->product('Πουκάμισο', 10.00, ['price_wvat' => 12.40]);
        $sale = $this->sell([['product_id' => $tee->id, 'qty' => 1]]);

        ['credit' => $credit, 'sale' => $new] = app(CreatePosReturn::class)(
            $this->tenant->fresh(), $sale, [$sale->lines()->first()->id => 1], [['product_id' => $shirt->id, 'qty' => 1]],
        );

        $this->assertSame(10.00, (float) $credit->payableTotal());
        $this->assertSame(12.40, (float) $new->payableTotal());
        $this->assertSame('active', $new->local_status);
        $this->assertSame(3, Invoice::where('local_status', 'active')->count(), 'the original + the credit note + the new sale');
    }

    public function test_never_more_than_was_sold_and_only_this_shops_issued_retail_sales(): void
    {
        $tee = $this->product('Μπλουζάκι', 8.06, ['price_wvat' => 10.00]);
        $sale = $this->sell([['product_id' => $tee->id, 'qty' => 2]]);
        $lineId = $sale->lines()->first()->id;

        app(CreatePosReturn::class)($this->tenant->fresh(), $sale, [$lineId => 2]);
        $this->assertSame([], CreatePosReturn::returnableLines($sale->fresh()));
        $this->assertRefused(fn () => app(CreatePosReturn::class)($this->tenant->fresh(), $sale->fresh(), [$lineId => 1]));

        // A credit note itself is not returnable, and a draft is not a receipt.
        $creditNote = Invoice::whereNotNull('credited_invoice_id')->first();
        $this->assertRefused(fn () => CreatePosReturn::assertReturnable($this->tenant, $creditNote));
        $this->assertNull(app(ReceiptLookup::class)->find($this->tenant, $creditNote->invcode));

        // No credit series → no returns at the till.
        $this->tenant->update(['pos_credit_type_id' => null]);
        $this->assertRefused(fn () => CreatePosReturn::creditType($this->tenant->fresh()));
    }

    public function test_the_receipt_is_found_by_barcode_mark_number_or_qr_url_and_only_in_its_company(): void
    {
        $sale = $this->sell([['product_id' => $this->product('Μπλουζάκι', 8.06, ['price_wvat' => 10.00])->id, 'qty' => 1]]);
        $sale->forceFill(['mydata_mark' => '400001972009030', 'mydata_url' => 'https://demo.invosign.gr/view?mark=400001972009030'])->save();
        $lookup = app(ReceiptLookup::class);

        $this->assertSame('400001972009030', $sale->receiptCode());
        foreach (['400001972009030', 'R'.$sale->id, $sale->invcode, ' '.mb_strtolower($sale->invcode).' ', 'https://demo.invosign.gr/view?mark=400001972009030'] as $code) {
            $this->assertSame($sale->id, $lookup->find($this->tenant, $code)?->id, "«{$code}»");
        }

        $other = Company::create(['name' => 'Άλλη', 'slug' => 'ret-o-'.uniqid(), 'country_code' => 'GR']);
        $this->assertNull($lookup->find($other, '400001972009030'));
        $this->assertNull($lookup->find($other, 'R'.$sale->id));
    }

    public function test_the_till_scans_the_receipt_barcode_and_issues_an_exchange(): void
    {
        $tee = $this->product('Μπλουζάκι', 8.06, ['price_wvat' => 10.00]);
        $shirt = $this->product('Πουκάμισο', 10.00, ['price_wvat' => 12.40]);
        $sale = $this->sell([['product_id' => $tee->id, 'qty' => 1]]);
        $this->operator();

        $page = Livewire::test(PointOfSale::class)
            ->call('scanCode', $sale->receiptCode())                // the receipt's own barcode
            ->assertSet('returnOf', $sale->id)
            ->call('returnMore', $sale->lines()->first()->id)
            ->call('choose', $shirt->id);
        $this->assertSame(2.40, $page->instance()->due);

        $page->call('checkout')
            ->assertSet('returnOf', null)
            ->assertSet('cart', [])
            ->assertDispatched('pos-print', fn ($name, $params) => str_contains($params['url'], 'with='));

        $this->assertSame(1, Invoice::whereNotNull('credited_invoice_id')->where('local_status', 'active')->count());
    }

    public function test_a_plain_return_shows_the_refund_due(): void
    {
        $tee = $this->product('Μπλουζάκι', 8.06, ['price_wvat' => 10.00]);
        $sale = $this->sell([['product_id' => $tee->id, 'qty' => 2]]);
        $this->operator();

        $page = Livewire::test(PointOfSale::class)
            ->call('startReturn')->set('returnCode', $sale->invcode)->call('lookupReturn')
            ->call('returnMore', $sale->lines()->first()->id)
            ->call('returnMore', $sale->lines()->first()->id)
            ->call('returnMore', $sale->lines()->first()->id);   // capped at what was sold

        $this->assertSame(-20.00, $page->instance()->due);
        $page->call('checkout')->assertDispatched('pos-print');
        $this->assertSame(20.00, (float) Invoice::whereNotNull('credited_invoice_id')->sole()->payableTotal());
    }

    public function test_an_exchange_whose_sale_fails_keeps_the_issued_credit_note(): void
    {
        $tee = $this->product('Μπλουζάκι', 8.06, ['price_wvat' => 10.00]);
        $sale = $this->sell([['product_id' => $tee->id, 'qty' => 1]]);
        $gone = $this->product('Αποσυρμένο', 5, ['is_active' => false]);

        try {
            app(CreatePosReturn::class)($this->tenant->fresh(), $sale, [$sale->lines()->first()->id => 1], [['product_id' => $gone->id, 'qty' => 1]]);
            $this->fail('expected an incomplete exchange');
        } catch (PosExchangeIncomplete $e) {
            $credit = Invoice::find($e->creditId);
            $this->assertSame('active', $credit->local_status, 'the return is a filed document — it stands');
            $this->assertStringContainsString($credit->invcode, $e->getMessage());
        }
    }

    public function test_an_exchange_prints_both_documents_in_one_run_with_the_receipt_barcode(): void
    {
        $tee = $this->product('Μπλουζάκι', 8.06, ['price_wvat' => 10.00]);
        $shirt = $this->product('Πουκάμισο', 10.00, ['price_wvat' => 12.40]);
        $sale = $this->sell([['product_id' => $tee->id, 'qty' => 1]]);
        ['credit' => $credit, 'sale' => $new] = app(CreatePosReturn::class)(
            $this->tenant->fresh(), $sale, [$sale->lines()->first()->id => 1], [['product_id' => $shirt->id, 'qty' => 1]],
        );
        $this->operator();

        $html = $this->get(PosReceiptController::signedUrl($credit->id, 5, $new->id))->assertOk()->getContent();
        $text = preg_replace('/\s+/u', ' ', html_entity_decode(preg_replace('/<[^>]+>/', ' ', $html)));

        $this->assertStringContainsString($credit->invcode, $text);
        $this->assertStringContainsString('Επιστροφή για την απόδειξη: '.$sale->invcode, $text);
        $this->assertStringContainsString('ΕΠΙΣΤΡΟΦΗ ΧΡΗΜΑΤΩΝ 10,00 €', $text);
        $this->assertStringContainsString($new->invcode, $text);
        $this->assertStringContainsString('class="barcode"', $html, 'the new sale carries its own barcode');
        $this->assertStringContainsString('R'.$new->id, $text);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function sell(array $items): Invoice
    {
        return app(CreatePosSale::class)($this->tenant->fresh(), $items);
    }

    private function assertRefused(callable $call): void
    {
        try {
            $call();
            $this->fail('expected a refusal');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }

    private function operator(): User
    {
        Gate::before(fn () => true);
        $user = User::create(['name' => 'Ταμίας', 'email' => 'ret-'.uniqid().'@e.test', 'password' => bcrypt('x')]);
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
}
