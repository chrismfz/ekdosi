<?php

namespace Tests\Feature\Invoice;

use App\Actions\ConvertInformalToFiscal;
use App\Actions\ReissueInvoiceAsDraft;
use App\Actions\StageServiceRenewal;
use App\Enums\BillingCycle;
use App\Enums\ServiceContractStatus;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ReturnInvoiceExtra;
use App\Models\ServiceContract;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\VatCategory;
use App\Services\Dashboard\DashboardMetrics;
use App\Services\RecomputeInvoiceTotals;
use App\Services\Stock\StockService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * «Μετατροπή σε φορολογικό» (docs/non-billable-services.md §5): an issued informal
 * document → a NEW fiscal DRAFT (today's date, no service link), issued the normal
 * way. The informal stays as the trace; the service doesn't advance again and the
 * stock doesn't move again — one delivery.
 */
class InformalConversionTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private PaymentMethod $credit;

    private InvoiceType $fiscalType;

    private InvoiceType $informalType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Conversion test', 'slug' => 'conv-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'afm' => '800561849',
        ]);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Φίλος', 'afm' => '123456789']);
        $this->credit = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Πίστωση', 'due_days' => 30]);
        $this->fiscalType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΤΠΥ', 'name' => 'Τιμολόγιο Παροχής', 'invcount' => 1, 'mydata_type' => '2.1',
        ]);
        $this->informalType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΕΣΩ', 'name' => 'Εσωτερικά', 'invcount' => 1, 'is_informal' => true,
        ]);
    }

    public function test_an_issued_informal_becomes_a_fiscal_draft_and_stays_as_the_trace(): void
    {
        Carbon::setTestNow('2026-09-25 10:00:00');
        $informal = $this->informal(qty: 2, issuedAt: '2026-05-10 10:00:00');

        $fiscal = app(ConvertInformalToFiscal::class)($informal, $this->fiscalType);

        $this->assertSame('draft', $fiscal->local_status);
        $this->assertSame($this->fiscalType->id, $fiscal->invoice_type_id);
        $this->assertSame($informal->id, $fiscal->converted_from_invoice_id);
        $this->assertNull($fiscal->code, 'numbered at issue, like any draft');
        $this->assertSame('2026-09-25', $fiscal->issued_at->toDateString(), 'today — never backdated');
        $this->assertNull($fiscal->service_contract_id);
        $this->assertSame((float) $informal->gross_total, (float) $fiscal->gross_total);
        $this->assertCount(1, $fiscal->lines);

        // The informal stays: active, numbered, linked, and still out of every total.
        $informal->refresh();
        $this->assertSame('active', $informal->local_status);
        $this->assertSame($fiscal->id, $informal->liveConversion()?->id);
        $this->assertSame(0.0, (new DashboardMetrics($this->tenant))->outstandingReceivables());

        // Once — while the conversion is live.
        try {
            app(ConvertInformalToFiscal::class)($informal, $this->fiscalType);
            $this->fail('An informal was converted twice.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('έχει ήδη μετατραπεί', $e->getMessage());
        }

        // A cancelled conversion (a mistake) frees it again.
        $fiscal->update(['local_status' => 'cancelled']);
        $again = app(ConvertInformalToFiscal::class)($informal->fresh(), $this->fiscalType);
        $this->assertSame($again->id, $informal->fresh()->liveConversion()?->id);
        Carbon::setTestNow();
    }

    public function test_only_an_issued_informal_to_a_fiscal_sale_type(): void
    {
        $credit = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΠΙΣ', 'name' => 'Πιστωτικό', 'invcount' => 1, 'mydata_type' => '5.1', 'is_credit' => true]);
        $otherInformal = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΔΟΚ', 'name' => 'Δοκιμές', 'invcount' => 1, 'is_informal' => true]);
        $active = $this->informal();

        $cases = [
            'draft informal' => [$this->informal(status: 'draft'), $this->fiscalType, 'πρόχειρο'],
            'cancelled informal' => [$this->informal(status: 'cancelled'), $this->fiscalType, 'ακυρωμένο'],
            'fiscal source' => [$this->document($this->fiscalType), $this->fiscalType, 'Μόνο ένα άτυπο'],
            'to informal' => [$active, $otherInformal, 'φορολογικό είδος'],
            'to credit' => [$active, $credit, 'φορολογικό είδος'],
        ];
        foreach ($cases as $name => [$source, $type, $why]) {
            try {
                app(ConvertInformalToFiscal::class)($source, $type);
                $this->fail($name.' was converted.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($why, $e->getMessage(), $name);
            }
        }
        $this->assertSame(0, Invoice::query()->whereNotNull('converted_from_invoice_id')->count());
    }

    public function test_the_fiscal_document_never_moves_the_stock_again(): void
    {
        $cat = ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'HW', 'markup' => 0]);
        $vat = VatCategory::create(['company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
        $ssd = Product::create([
            'company_id' => $this->tenant->id, 'description_short' => 'SSD',
            'product_category_id' => $cat->id, 'vat_category_id' => $vat->id, 'track_stock' => true,
        ]);
        $stock = app(StockService::class);
        $stock->record($ssd, 10, StockMovement::REASON_INITIAL);

        // Given to a friend: the informal's finalize takes it out of stock.
        $informal = $this->informal(status: 'draft', product: $ssd);
        $informal->update(['local_status' => 'active']);
        $this->assertSame(9.0, $stock->currentStock($ssd));

        // The friend pays after all → converted and issued: still ONE stock-out…
        $fiscal = app(ConvertInformalToFiscal::class)($informal, $this->fiscalType);
        $fiscal->update(['local_status' => 'active']);
        $this->assertSame(9.0, $stock->currentStock($ssd));

        // …and cancelling the fiscal doesn't bring the goods back (the informal
        // delivery stands).
        $fiscal->update(['local_status' => 'cancelled']);
        $this->assertSame(9.0, $stock->currentStock($ssd));
    }

    public function test_the_service_does_not_advance_again(): void
    {
        $contract = ServiceContract::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'invoice_type_id' => $this->informalType->id, 'payment_method_id' => $this->credit->id,
            'description' => 'Hosting φίλου', 'billing_cycle' => BillingCycle::Annual, 'quantity' => 1,
            'amount' => 100, 'vat_percent' => 24, 'status' => ServiceContractStatus::Active,
            'start_date' => '2025-10-01', 'next_due_date' => '2026-10-01',
        ]);
        $informal = app(StageServiceRenewal::class)($contract, Carbon::parse('2026-10-01'));
        $informal->update(['local_status' => 'active']);
        $this->assertSame('2027-10-01', $contract->fresh()->next_due_date->toDateString());

        $fiscal = app(ConvertInformalToFiscal::class)($informal->fresh(), $this->fiscalType);
        $fiscal->update(['local_status' => 'active']);

        $this->assertNull($fiscal->service_contract_id);
        $this->assertSame('2027-10-01', $contract->fresh()->next_due_date->toDateString());
    }

    public function test_the_view_page_converts_and_then_freezes_the_informal(): void
    {
        $this->actingAsOperator();
        $informal = $this->informal();

        Livewire::test(ViewInvoice::class, ['record' => $informal->getKey()])
            ->assertActionVisible('revert_to_draft')
            ->assertActionVisible('cancel_local')
            ->callAction('convert_to_fiscal', data: ['invoice_type_id' => $this->fiscalType->id])
            ->assertHasNoActionErrors();

        $fiscal = Invoice::query()->where('converted_from_invoice_id', $informal->id)->sole();
        $this->assertSame('draft', $fiscal->local_status);

        // The informal is now a frozen trace: no second conversion, no revert/cancel.
        Livewire::test(ViewInvoice::class, ['record' => $informal->getKey()])
            ->assertActionHidden('convert_to_fiscal')
            ->assertActionHidden('revert_to_draft')
            ->assertActionHidden('cancel_local');
        // A fiscal document never offers it.
        Livewire::test(ViewInvoice::class, ['record' => $fiscal->getKey()])
            ->assertActionHidden('convert_to_fiscal');
    }

    public function test_a_conversion_never_becomes_informal_itself(): void
    {
        $fiscal = app(ConvertInformalToFiscal::class)($this->informal(), $this->fiscalType);

        try {
            $fiscal->update(['invoice_type_id' => $this->informalType->id]);
            $this->fail('A conversion was moved back into an informal series.');
        } catch (RuntimeException $e) {
            $this->assertSame(Invoice::INFORMAL_NOT_CONVERSION, $e->getMessage());
        }
        $this->assertSame($this->fiscalType->id, $fiscal->fresh()->invoice_type_id);
    }

    public function test_the_fiscal_moves_only_the_quantity_the_informal_did_not(): void
    {
        [$ssd, $stock] = $this->trackedProduct();
        $informal = $this->informal(status: 'draft', product: $ssd);
        $informal->update(['local_status' => 'active']);                 // −1
        $fiscal = app(ConvertInformalToFiscal::class)($informal, $this->fiscalType);
        $fiscal->lines()->first()->update(['qty' => 5]);                 // the friend took 4 more

        $fiscal->update(['local_status' => 'active']);
        $this->assertSame(5.0, 10 - $stock->currentStock($ssd), 'the 4 extra leave stock, the 1 not again');

        // Cancelling the fiscal gives back only what IT took out.
        $fiscal->update(['local_status' => 'cancelled']);
        $this->assertSame(9.0, $stock->currentStock($ssd));
    }

    public function test_revive_never_makes_a_second_live_conversion_nor_wakes_a_converted_informal(): void
    {
        $this->actingAsOperator();
        $informal = $this->informal();
        $first = app(ConvertInformalToFiscal::class)($informal, $this->fiscalType);
        $first->update(['local_status' => 'cancelled']);                 // a mistake…
        $second = app(ConvertInformalToFiscal::class)($informal->fresh(), $this->fiscalType); // …redone

        // The first can't come back: two live fiscal documents for one delivery.
        Livewire::test(ViewInvoice::class, ['record' => $first->getKey()])->assertActionHidden('revive');
        $this->assertRefused(fn () => $first->fresh()->update(['local_status' => 'draft']));

        // An informal cancelled while unfrozen can't come back while its conversion lives.
        $second->update(['local_status' => 'cancelled']);
        $informal->fresh()->update(['local_status' => 'cancelled']);
        $second->fresh()->update(['local_status' => 'draft']);           // the conversion revived
        Livewire::test(ViewInvoice::class, ['record' => $informal->getKey()])->assertActionHidden('revive');
        $this->assertRefused(fn () => $informal->fresh()->update(['local_status' => 'draft']));
        $this->assertSame('cancelled', $informal->fresh()->local_status);
    }

    public function test_a_credited_conversion_still_holds_the_informal(): void
    {
        $informal = $this->informal();
        $fiscal = app(ConvertInformalToFiscal::class)($informal, $this->fiscalType);
        $fiscal->update(['local_status' => 'active']);

        // Credited (even fully) — it still stands legally: no second conversion.
        $fiscal->forceFill(['credited_total' => $fiscal->gross_total])->saveQuietly();
        $this->assertSame($fiscal->id, $informal->fresh()->liveConversion()?->id);
        $this->expectExceptionMessage('έχει ήδη μετατραπεί');
        app(ConvertInformalToFiscal::class)($informal->fresh(), $this->fiscalType);
    }

    public function test_the_line_snapshot_carries_over_including_the_vat_exemption_reason(): void
    {
        $informal = $this->informal();
        $informal->lines()->first()->update([
            'vat_percent' => 0, 'vat_exemption_category' => 4,
            'mydata_income_class' => 'category1_3', 'mydata_income_class_category' => 'E3_561_001',
        ]);

        $line = app(ConvertInformalToFiscal::class)($informal->fresh(), $this->fiscalType)->lines()->first();

        $this->assertSame(4, $line->vat_exemption_category);
        // The hidden §8.6 snapshot is NOT copied: the fiscal re-resolves it.
        $this->assertNull($line->mydata_income_class);
        $this->assertNull($line->mydata_income_class_category);
    }

    public function test_the_edit_form_refuses_an_informal_series_on_a_conversion(): void
    {
        $this->actingAsOperator();
        $fiscal = app(ConvertInformalToFiscal::class)($this->informal(), $this->fiscalType);

        Livewire::test(EditInvoice::class, ['record' => $fiscal->getKey()])
            ->fillForm(['invoice_type_id' => $this->informalType->id])
            ->call('save')
            ->assertHasFormErrors(['invoice_type_id']);
        $this->assertSame($this->fiscalType->id, $fiscal->fresh()->invoice_type_id);
    }

    public function test_stock_stays_right_through_cancel_and_revive_cycles(): void
    {
        [$ssd, $stock] = $this->trackedProduct();
        $informal = $this->informal(status: 'draft', product: $ssd);
        $informal->lines()->first()->update(['qty' => 3]);
        $informal->update(['local_status' => 'active']);                  // −3
        $fiscal = app(ConvertInformalToFiscal::class)($informal->fresh(), $this->fiscalType);
        $fiscal->lines()->first()->update(['qty' => 5]);
        $fiscal->update(['local_status' => 'active']);                    // takes over: −5 in all
        $this->assertSame(5.0, $stock->currentStock($ssd));

        $fiscal->update(['local_status' => 'cancelled']);                 // back to the informal: −3
        $this->assertSame(7.0, $stock->currentStock($ssd));
        $informal->fresh()->update(['local_status' => 'cancelled']);      // nothing delivered
        $this->assertSame(10.0, $stock->currentStock($ssd));
        $fiscal->fresh()->update(['local_status' => 'active']);           // revived: the fiscal alone, −5
        $this->assertSame(5.0, $stock->currentStock($ssd));
    }

    public function test_a_credit_note_on_the_fiscal_returns_the_goods_once(): void
    {
        [$ssd, $stock] = $this->trackedProduct();
        $informal = $this->informal(status: 'draft', product: $ssd);
        $informal->update(['local_status' => 'active']);                  // −1
        $fiscal = app(ConvertInformalToFiscal::class)($informal, $this->fiscalType);
        $fiscal->update(['local_status' => 'active']);                    // still −1
        $creditType = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΠΙΣ', 'name' => 'Πιστωτικό', 'invcount' => 1, 'mydata_type' => '5.1', 'is_credit' => true]);
        $credit = Invoice::create([
            'company_id' => $this->tenant->id, 'invoice_type_id' => $creditType->id, 'customer_id' => $this->customer->id,
            'credited_invoice_id' => $fiscal->id, 'issued_at' => now(), 'local_status' => 'draft',
        ]);
        InvoiceLine::create(['company_id' => $this->tenant->id, 'invoice_id' => $credit->id, 'product_id' => $ssd->id, 'qty' => 1, 'price_per_item' => 100, 'vat_percent' => 24, 'product_descr' => 'SSD']);
        $credit->update(['local_status' => 'active']);                    // +1

        $this->assertSame(10.0, $stock->currentStock($ssd));

        // «Ακύρωση & επανέκδοση»: the reissue continues the conversion and sells again
        // (the goods went back with the credit) — it takes nothing over twice.
        $reissue = app(ReissueInvoiceAsDraft::class)($fiscal->fresh());
        $reissue->update(['local_status' => 'active']);
        $this->assertSame(9.0, $stock->currentStock($ssd));
    }

    public function test_cancelling_a_credited_conversion_hands_back_only_what_its_cancel_returned(): void
    {
        [$ssd, $stock] = $this->trackedProduct();
        $informal = $this->informal(status: 'draft', product: $ssd);
        $informal->update(['local_status' => 'active']);                  // 9
        $fiscal = app(ConvertInformalToFiscal::class)($informal, $this->fiscalType);
        $fiscal->update(['local_status' => 'active']);                    // 9
        $creditType = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΠΙΣ', 'name' => 'Πιστωτικό', 'invcount' => 1, 'mydata_type' => '5.1', 'is_credit' => true]);
        $fiscalLine = $fiscal->lines()->first();
        $credit = Invoice::create([
            'company_id' => $this->tenant->id, 'invoice_type_id' => $creditType->id, 'customer_id' => $this->customer->id,
            'credited_invoice_id' => $fiscal->id, 'issued_at' => now(), 'local_status' => 'draft',
        ]);
        InvoiceLine::create(['company_id' => $this->tenant->id, 'invoice_id' => $credit->id, 'product_id' => $ssd->id, 'original_line_id' => $fiscalLine->id, 'qty' => 1, 'price_per_item' => 100, 'vat_percent' => 24, 'product_descr' => 'SSD']);
        ReturnInvoiceExtra::create(['company_id' => $this->tenant->id, 'invoice_line_id' => $fiscalLine->id, 'qty_returned' => 1]);
        $credit->update(['local_status' => 'active']);                    // 10 — the goods came back

        // The credited original is then cancelled too (e.g. «Ακύρωση μέσω myDATA»):
        // its cancel gives nothing back, so nothing is handed to the informal either.
        $fiscal->fresh()->update(['local_status' => 'cancelled']);
        $this->assertSame(10.0, $stock->currentStock($ssd));
    }

    public function test_cancelling_a_conversion_that_never_took_over_changes_no_stock(): void
    {
        [$ssd, $stock] = $this->trackedProduct();
        $informal = $this->informal(status: 'draft', product: $ssd);
        $informal->update(['local_status' => 'active']);                  // −1
        $fiscal = app(ConvertInformalToFiscal::class)($informal, $this->fiscalType);

        $fiscal->update(['local_status' => 'cancelled']);                 // a draft, discarded
        $this->assertSame(9.0, $stock->currentStock($ssd));

        // Converted again and issued: still one stock-out.
        $again = app(ConvertInformalToFiscal::class)($informal->fresh(), $this->fiscalType);
        $again->update(['local_status' => 'active']);
        $this->assertSame(9.0, $stock->currentStock($ssd));
    }

    public function test_a_reissued_conversion_keeps_the_informal_converted(): void
    {
        $informal = $this->informal();
        $fiscal = app(ConvertInformalToFiscal::class)($informal, $this->fiscalType);
        $fiscal->update(['local_status' => 'active']);

        $reissue = app(ReissueInvoiceAsDraft::class)($fiscal->fresh());
        $fiscal->forceFill(['credited_total' => $fiscal->fresh()->payable_total ?? $fiscal->gross_total])->saveQuietly(); // storno

        $this->assertSame($informal->id, $reissue->converted_from_invoice_id);
        // Both the credited original and its reissue are the informal's conversions —
        // it stays frozen, and a new conversion is refused.
        $this->assertSame(2, Invoice::liveConversionsOf($informal->id)->count());
        $this->expectExceptionMessage('έχει ήδη μετατραπεί');
        app(ConvertInformalToFiscal::class)($informal->fresh(), $this->fiscalType);
    }

    public function test_an_informal_with_a_linked_delivery_note_is_not_converted(): void
    {
        $informal = $this->informal();
        $dnType = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΔΑΠ', 'name' => 'Δελτίο', 'invcount' => 1, 'mydata_type' => '9.3']);
        DeliveryNote::create([
            'company_id' => $this->tenant->id, 'delivery_type_id' => $dnType->id, 'invoice_id' => $informal->id,
            'customer_id' => $this->customer->id, 'issued_at' => now(), 'local_status' => 'draft',
        ]);

        $this->expectExceptionMessage('συνδεδεμένο δελτίο αποστολής');
        app(ConvertInformalToFiscal::class)($informal, $this->fiscalType);
    }

    private function assertRefused(callable $write): void
    {
        try {
            $write();
            $this->fail('The write was not refused.');
        } catch (RuntimeException $e) {
            $this->assertSame(Invoice::INFORMAL_ALREADY_CONVERTED, $e->getMessage());
        }
    }

    /** @return array{0: Product, 1: StockService} */
    private function trackedProduct(): array
    {
        $cat = ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'HW', 'markup' => 0]);
        $vat = VatCategory::create(['company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
        $ssd = Product::create([
            'company_id' => $this->tenant->id, 'description_short' => 'SSD',
            'product_category_id' => $cat->id, 'vat_category_id' => $vat->id, 'track_stock' => true,
        ]);
        $stock = app(StockService::class);
        $stock->record($ssd, 10, StockMovement::REASON_INITIAL);

        return [$ssd, $stock];
    }

    private function informal(int $qty = 1, string $status = 'active', ?string $issuedAt = null, ?Product $product = null): Invoice
    {
        return $this->document($this->informalType, $qty, $status, $issuedAt, $product);
    }

    private function document(InvoiceType $type, int $qty = 1, string $status = 'active', ?string $issuedAt = null, ?Product $product = null): Invoice
    {
        $numbered = $status !== 'draft';
        $invoice = Invoice::create(array_filter([
            'company_id' => $this->tenant->id, 'invoice_type_id' => $type->id, 'customer_id' => $this->customer->id,
            'payment_method_id' => $this->credit->id, 'issued_at' => $issuedAt ?? now(), 'local_status' => $status,
            'company_name' => $this->customer->name, 'vat_no' => $this->customer->afm,
            'invcode' => $numbered ? $type->code.uniqid() : null, 'code' => $numbered ? 1 : null,
        ], fn ($v) => $v !== null));
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $invoice->id, 'product_id' => $product?->id,
            'qty' => $qty, 'price_per_item' => 100, 'vat_percent' => 24, 'product_descr' => 'Υπηρεσία',
        ]);

        return app(RecomputeInvoiceTotals::class)($invoice)->fresh();
    }

    private function actingAsOperator(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('x')]));
        Filament::setTenant($this->tenant);
    }
}
