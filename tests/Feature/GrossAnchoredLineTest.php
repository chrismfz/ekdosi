<?php

namespace Tests\Feature;

use App\Actions\IssueCreditNote;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Support\VatRateOptions;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\EInvoice\AadeInvoiceDocument;
use App\Services\EInvoice\Transports\InvoSignDocument;
use App\Services\InvoiceVatBreakdown;
use App\Services\MyData\Orphans\OrphanImporter;
use App\Services\RecomputeInvoiceTotals;
use App\Support\LineMoney;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * POS-2: GROSS-anchored (shelf-priced) lines — the VAT-inclusive unit price is the
 * source and the line total is exactly the shelf price (10,00 @24% → 8,06 + 1,94),
 * where a net-anchored 2dp line can only reach 9,99. AADE checks only the sums
 * (sandbox-proven 2026-10-02, direct + InvoSign).
 */
class GrossAnchoredLineTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $receipt;

    private PaymentMethod $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'Κατάστημα', 'slug' => 'ga-'.uniqid(), 'country_code' => 'GR', 'afm' => '800561849',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->receipt = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΑΛΠ', 'name' => 'ΑΛΠ', 'invcount' => 1, 'mydata_type' => '11.1',
            'mydata_income_class' => 'E3_561_003', 'mydata_income_class_category' => 'category1_1',
        ]);
        $this->cash = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Μετρητά', 'due_days' => 0, 'mydata_payment_type' => 3]);
    }

    public function test_the_two_formulas(): void
    {
        // [qty, unit gross, disc%, vat%] => [net, gross]
        foreach ([
            [[1, 10.00, 0, 24], [8.06, 10.00]],
            [[1, 4.00, 0, 24], [3.23, 4.00]],
            [[1, 29.90, 0, 24], [24.11, 29.90]],
            [[3, 1.99, 0, 24], [4.81, 5.97]],
            [[1, 10.00, 15, 24], [6.85, 8.50]],
            [[1, 5.00, 0, 13], [4.42, 5.00]],
            [[1, 1.50, 0, 6], [1.42, 1.50]],
            [[1, 0.01, 0, 24], [0.01, 0.01]],
            [[0.5, 7.99, 0, 24], [3.23, 4.00]],
            [[1, 12.00, 0, 0], [12.00, 12.00]],
        ] as [[$q, $g, $d, $r], [$net, $gross]]) {
            $this->assertSame(['net' => $net, 'gross' => $gross], LineMoney::fromGross($q, $g, $d, $r), "{$q}×{$g} -{$d}% @{$r}");
        }
        // The net-anchored formula is unchanged (the shelf price it can't reach).
        $this->assertSame(['net' => 8.06, 'gross' => 9.99], LineMoney::fromNet(1, 8.06, 0, 24));
    }

    public function test_a_gross_anchored_line_stores_the_exact_shelf_price(): void
    {
        $invoice = $this->invoice([[10.00, 1], [4.00, 1], [1.99, 3]]);
        $lines = $invoice->lines()->orderBy('id')->get();

        $this->assertSame(['8.06', '10.00', '8.06'], [$lines[0]->net_price, $lines[0]->gross_price, $lines[0]->price_per_item]);
        $this->assertSame(['3.23', '4.00'], [$lines[1]->net_price, $lines[1]->gross_price]);
        $this->assertSame(['4.81', '5.97', '1.60'], [$lines[2]->net_price, $lines[2]->gross_price, $lines[2]->price_per_item]);
        $this->assertSame(19.97, (float) $invoice->gross_total);
        $this->assertSame(16.10, (float) $invoice->net_total);
    }

    public function test_the_vat_summary_is_the_sum_of_line_vat_not_net_times_rate(): void
    {
        // 10 × 10,00 — Σvat 19,40 while Σnet × 24% = 19,34 (AADE accepts it: sums only).
        $invoice = $this->invoice(array_fill(0, 10, [10.00, 1]));
        $row = InvoiceVatBreakdown::for($invoice)->rows[0];

        $this->assertSame(80.60, (float) $row['net']);
        $this->assertSame(19.40, (float) $row['vat']);
        $this->assertSame(100.00, (float) $invoice->gross_total);
    }

    public function test_the_model_always_honours_the_anchor_and_a_cleared_one_reprices_by_net(): void
    {
        $line = $this->invoice([[10.00, 1]])->lines()->first();

        // The model never guesses: a stray net value can't override the anchor…
        $line->update(['price_per_item' => 8.06, 'qty' => 2]);
        $this->assertSame(['10.00', '20.00', '8.06'], [$line->fresh()->gross_unit_price, $line->fresh()->gross_price, $line->fresh()->price_per_item]);

        // …the caller re-prices by NET by clearing it explicitly (the invoice form does).
        $line->refresh()->update(['gross_unit_price' => null, 'price_per_item' => 9.00]);
        $this->assertSame(['18.00', '22.32'], [$line->fresh()->net_price, $line->fresh()->gross_price]);
    }

    public function test_a_copy_and_a_partial_credit_keep_the_shelf_price(): void
    {
        $invoice = $this->invoice([[1.99, 3]]);
        $invoice->forceFill(['code' => 1, 'local_status' => 'active', 'mydata_state' => 'VALID'])->save();
        $line = $invoice->lines()->first();

        $this->assertSame('1.99', (string) $line->copyAttributes()['gross_unit_price']);

        $creditType = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'ΠΙΛ', 'name' => 'ΠΙΛ', 'invcount' => 1, 'mydata_type' => '11.4', 'is_credit' => true]);
        $credit = app(IssueCreditNote::class)($invoice->fresh(['lines']), $creditType, [['line_id' => $line->id, 'qty' => 1]]);

        $this->assertSame(1.99, (float) $credit->gross_total, 'one returned item refunds exactly its shelf price');
    }

    public function test_the_aade_payload_files_the_line_vat_as_stored(): void
    {
        $invoice = $this->invoice([[10.00, 1], [4.00, 1]]);
        $invoice->forceFill(['code' => 7])->save();

        $doc = new AadeInvoiceDocument($this->tenant);
        $xml = $doc->toXml($doc->build($invoice->fresh()));

        $this->assertStringContainsString('<netValue>8.06</netValue>', $xml);
        $this->assertStringContainsString('<vatAmount>1.94</vatAmount>', $xml);
        $this->assertStringContainsString('<netValue>3.23</netValue>', $xml);
        $this->assertStringContainsString('<vatAmount>0.77</vatAmount>', $xml);
        $this->assertStringContainsString('<totalVatAmount>2.71</totalVatAmount>', $xml);
        $this->assertStringContainsString('<totalGrossValue>14</totalGrossValue>', $xml);
    }

    public function test_invosign_never_gets_a_negative_rounding_discount(): void
    {
        // 3 × 1,99: net unit mirror 1,60 × 3 = 4,80 < line net 4,81 — rounding, not a discount.
        $invoice = $this->invoice([[1.99, 3]]);
        $invoice->forceFill(['code' => 8])->save();
        $doc = new AadeInvoiceDocument($this->tenant);
        $xml = InvoSignDocument::augment($doc->toXml($doc->build($invoice->fresh())), $invoice->fresh(['lines']));

        $this->assertStringContainsString('<api_DiscountValue>0.00</api_DiscountValue>', $xml);
        $this->assertStringNotContainsString('<api_DiscountValue>-', $xml);
    }

    public function test_the_invoice_form_shows_and_keeps_the_shelf_price(): void
    {
        // A re-issued shelf-priced draft opened in the invoice form: «Τιμή (με ΦΠΑ)»
        // shows the exact 10,00 (not 8,06 × 1,24 = 9,99) and a save keeps the anchor.
        $this->panelOperator();
        $customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Πελάτης']);
        $invoice = $this->invoice([[10.00, 1]]);
        $invoice->update(['customer_id' => $customer->id]);

        $page = Livewire::test(EditInvoice::class, ['record' => $invoice->getKey()])->assertOk();
        $item = array_values($page->get('data.lines'))[0];
        $this->assertSame(10.0, (float) $item['price_per_item_wvat']);

        $page->call('save')->assertHasNoFormErrors();
        $line = $invoice->lines()->first();
        $this->assertSame(['10.00', '10.00'], [$line->gross_unit_price, $line->gross_price]);
    }

    public function test_the_invoice_form_manages_the_anchor_explicitly(): void
    {
        $this->panelOperator();
        $customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Πελάτης']);
        $invoice = $this->invoice([[10.00, 1]]);
        $invoice->update(['customer_id' => $customer->id]);
        $key = fn ($page) => array_key_first($page->get('data.lines'));

        // A typed gross on a shelf-priced line moves the shelf price — even one whose
        // 2dp net is the same (9,99 and 10,00 both → 8,06): exact, not ignored.
        $page = Livewire::test(EditInvoice::class, ['record' => $invoice->getKey()]);
        $page->set('data.lines.'.$key($page).'.price_per_item_wvat', '9.99')->call('save')->assertHasNoFormErrors();
        $line = $invoice->lines()->first();
        $this->assertSame(['9.99', '9.99'], [$line->gross_unit_price, $line->gross_price]);

        // A VAT-rate change keeps the NET (e.g. reverse charge at 0% must not turn the
        // shelf price into the net) — the anchor is dropped, screen == saved.
        $page = Livewire::test(EditInvoice::class, ['record' => $invoice->getKey()]);
        $page->set('data.lines.'.$key($page).'.vat_percent', VatRateOptions::normalize(13))->call('save')->assertHasNoFormErrors();
        $line = $invoice->lines()->first();
        $this->assertNull($line->gross_unit_price);
        $this->assertSame(['8.06', '9.11'], [$line->net_price, $line->gross_price]);
    }

    public function test_a_net_edit_in_the_form_drops_the_anchor(): void
    {
        $this->panelOperator();
        $customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Πελάτης']);
        $invoice = $this->invoice([[10.00, 1]]);
        $invoice->update(['customer_id' => $customer->id]);

        $page = Livewire::test(EditInvoice::class, ['record' => $invoice->getKey()]);
        $page->set('data.lines.'.array_key_first($page->get('data.lines')).'.price_per_item', '9')->call('save')->assertHasNoFormErrors();
        $line = $invoice->lines()->first();
        $this->assertNull($line->gross_unit_price);
        $this->assertSame(['9.00', '11.16'], [$line->net_price, $line->gross_price]);
    }

    public function test_invosign_gets_the_line_own_unit_on_a_multi_qty_shelf_line(): void
    {
        // 5 × 0,99: net mirror 0,80 × 5 = 4,00 vs line net 3,99 — must not print a «discount».
        $invoice = $this->invoice([[0.99, 5]]);
        $invoice->forceFill(['code' => 9])->save();
        $doc = new AadeInvoiceDocument($this->tenant);
        $xml = InvoSignDocument::augment($doc->toXml($doc->build($invoice->fresh())), $invoice->fresh(['lines']));

        $this->assertStringContainsString('<api_DiscountValue>0.00</api_DiscountValue>', $xml);
        $this->assertStringContainsString('<api_UnitPrice>0.80</api_UnitPrice>', $xml);
    }

    public function test_an_imported_till_document_is_rebuilt_gross_anchored(): void
    {
        $anchor = new ReflectionMethod(OrphanImporter::class, 'grossAnchor');

        $this->assertSame(10.00, $anchor->invoke(null, 8.06, 1.94, 24.0), 'VAT extracted from the gross');
        $this->assertNull($anchor->invoke(null, 8.06, 1.93, 24.0), 'classic net × rate line');
        $this->assertNull($anchor->invoke(null, 24.11, 5.79, 24.0), 'both formulas agree → net-anchored');
        $this->assertNull($anchor->invoke(null, 8.06, 2.50, 24.0), 'neither formula → left to the exact-cent check');
        $this->assertNull($anchor->invoke(null, -8.06, -1.94, 24.0), 'a negative line stays net-anchored (friendly refusal path)');
    }

    private function panelOperator(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@e.test', 'password' => bcrypt('x')]));
        Filament::setTenant($this->tenant);
    }

    /** @param  list<array{0: float, 1: float}>  $lines  [unit gross, qty] */
    private function invoice(array $lines): Invoice
    {
        $invoice = Invoice::create([
            'company_id' => $this->tenant->id, 'invoice_type_id' => $this->receipt->id, 'issued_at' => now(),
            'local_status' => 'draft', 'header_discount_percent' => 0, 'payment_method_id' => $this->cash->id, 'country' => 'GR',
        ]);
        foreach ($lines as [$unitGross, $qty]) {
            InvoiceLine::create([
                'company_id' => $this->tenant->id, 'invoice_id' => $invoice->id, 'product_descr' => 'Είδος',
                'qty' => $qty, 'gross_unit_price' => $unitGross, 'vat_percent' => 24,
            ]);
        }

        return app(RecomputeInvoiceTotals::class)($invoice);
    }
}
