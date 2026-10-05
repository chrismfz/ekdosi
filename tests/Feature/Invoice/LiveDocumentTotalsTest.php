<?php

namespace Tests\Feature\Invoice;

use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Quotes\Pages\CreateQuote;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\User;
use App\Services\RecomputeInvoiceTotals;
use App\Support\DocumentTotals;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The «Σύνολα» strip under the invoice / quote lines: computed from the UNSAVED
 * form state with the SAME math the save path uses, so the operator sees the
 * final amount while typing instead of reaching for a calculator.
 */
class LiveDocumentTotalsTest extends TestCase
{
    use RefreshDatabase;

    /** The operator's real case: three lines, one at −22%, all 24%. */
    private const LINES = [
        ['qty' => '1', 'price_per_item' => '48.39', 'discount' => '22', 'vat_percent' => '24'],
        ['qty' => '1', 'price_per_item' => '41.6', 'discount' => '0', 'vat_percent' => '24'],
        ['qty' => '1', 'price_per_item' => '0.84', 'discount' => '0', 'vat_percent' => '24'],
    ];

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Totals', 'slug' => 'totals-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'afm' => '800000000',
        ]);
    }

    public function test_form_lines_roll_up_like_the_save_path(): void
    {
        $t = DocumentTotals::fromFormLines(self::LINES, '0');

        // 48,39 −22% = 37,74 (→ 46,80) + 41,60 (→ 51,58) + 0,84 (→ 1,04)
        $this->assertSame(3, $t['lines']);
        $this->assertSame(90.83, $t['list_net']);
        $this->assertSame(10.65, $t['line_discount']);
        $this->assertSame(80.18, $t['net']);
        $this->assertSame(19.24, $t['vat']);
        $this->assertSame(99.42, $t['gross']);
    }

    public function test_header_discount_applies_once_at_the_aggregate(): void
    {
        $t = DocumentTotals::fromFormLines(self::LINES, '10');

        $this->assertSame(8.02, $t['header_discount']);
        $this->assertSame(72.16, $t['net']);
        $this->assertSame(89.48, $t['gross']);
        $this->assertSame(17.32, $t['vat']);
    }

    public function test_a_shelf_priced_line_keeps_its_exact_gross(): void
    {
        // POS-2: a gross-anchored line prices from its anchor (10,00 → 8,06 + 1,94).
        $t = DocumentTotals::fromFormLines([
            ['qty' => 1, 'gross_unit_price' => '10', 'price_per_item' => '8.06', 'vat_percent' => 24, 'discount' => 0],
        ], null);

        $this->assertSame(10.0, $t['gross']);
        $this->assertSame(8.06, $t['net']);
    }

    public function test_half_typed_lines_are_skipped_not_fatal(): void
    {
        $t = DocumentTotals::fromFormLines([
            ['qty' => '', 'price_per_item' => '99', 'vat_percent' => '24'],   // qty not typed yet
            ['qty' => '2', 'price_per_item' => 'abc', 'vat_percent' => null], // garbage price
            'not-a-line',
        ], 'x');

        $this->assertSame(1, $t['lines']);
        $this->assertSame(0.0, $t['gross']);
    }

    public function test_the_preview_matches_what_the_invoice_saves(): void
    {
        $customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'C']);
        $type = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'Τ', 'invcount' => 1, 'mydata_type' => '1.1']);
        $invoice = Invoice::create([
            'company_id' => $this->tenant->id, 'invoice_type_id' => $type->id, 'customer_id' => $customer->id,
            'local_status' => 'draft', 'issued_at' => now(), 'header_discount_percent' => 10,
        ]);
        foreach (self::LINES as $line) {
            $invoice->lines()->create(['company_id' => $this->tenant->id, 'product_descr' => 'x'] + $line);
        }

        $saved = app(RecomputeInvoiceTotals::class)($invoice);
        $preview = DocumentTotals::fromFormLines(self::LINES, '10');

        $this->assertSame($preview['net'], (float) $saved->net_total);
        $this->assertSame($preview['gross'], (float) $saved->gross_total);
    }

    public function test_the_invoice_and_quote_forms_show_the_live_totals(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('x')]));
        Filament::setTenant($this->tenant);
        $lines = array_combine(['a', 'b', 'c'], self::LINES);

        foreach ([CreateInvoice::class => true, CreateQuote::class => false] as $page => $hasTaxes) {
            $form = Livewire::test($page)
                ->set('data.lines', $lines)
                ->assertSee('Καθαρή αξία')
                ->assertSee('80,18 €')
                ->assertSee('99,42 €')
                ->set('data.header_discount_percent', '10')
                ->assertSee('89,48 €');

            // Only an invoice carries withholding / stamp duty — a quote has no such note.
            $hasTaxes
                ? $form->assertSee('Πριν από παρακρατήσεις')
                : $form->assertDontSee('Πριν από παρακρατήσεις');
        }
    }
}
