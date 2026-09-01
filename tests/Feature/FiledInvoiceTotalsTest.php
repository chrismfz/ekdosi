<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Services\MyData\FiledInvoiceTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * FiledInvoiceTotals reconstructs the net/gross an invoice was FILED with. It must
 * FAIL CLOSED (both fields null = "unverified") on anything it can't faithfully
 * roll up, never a fabricated 0,00 and never a thrown exception that would abort a
 * whole reconciliation (MYD-017 review).
 */
class FiledInvoiceTotalsTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private InvoiceType $type;

    private int $code = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Filed', 'slug' => 'filed-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'afm' => '800561849',
        ]);
        $this->customer = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'Πελάτης', 'afm' => '123456789',
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΤΙΜ', 'name' => 'Τιμολόγιο',
            'invcount' => 1, 'mydata_type' => '1.1',
        ]);
    }

    private function invoice(array $override = []): Invoice
    {
        $this->code++;

        return Invoice::create(array_merge([
            'company_id' => $this->tenant->id,
            'invcode' => 'ΤΙΜ'.$this->code, 'code' => $this->code,
            'invoice_type_id' => $this->type->id, 'customer_id' => $this->customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0,
            'net_total' => 100.00, 'gross_total' => 124.00,
        ], $override));
    }

    private function line(Invoice $inv, array $override = []): void
    {
        InvoiceLine::create(array_merge([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'qty' => 1, 'price_per_item' => 100.00, 'vat_percent' => 24.00,
            'net_price' => 100.00, 'gross_price' => 124.00, 'product_descr' => 'Υπηρεσία',
        ], $override));
        $inv->load('lines');
    }

    private function rawLine(Invoice $inv, array $override = []): void
    {
        // DB::table insert, NOT InvoiceLine::create — the model's saving hook
        // backfills/guards amounts, but the Firebird ETL writes via the query
        // builder and CAN persist null net_price/gross_price/vat_percent. This is
        // the row FiledInvoiceTotals must treat as unverified.
        DB::table('invoice_lines')->insert(array_merge([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'qty' => 1, 'price_per_item' => 100.00, 'vat_percent' => 24.00,
            'net_price' => 100.00, 'gross_price' => 124.00, 'product_descr' => 'Legacy',
        ], $override));
        $inv->load('lines');
    }

    public function test_reconstructs_the_filed_net_and_gross(): void
    {
        $inv = $this->invoice();
        $this->line($inv);

        $filed = FiledInvoiceTotals::for($inv);
        $this->assertSame(100.00, $filed->net);
        $this->assertSame(124.00, $filed->gross);
    }

    public function test_no_lines_is_unverified(): void
    {
        $filed = FiledInvoiceTotals::for($this->invoice());
        $this->assertNull($filed->net);
        $this->assertNull($filed->gross);
    }

    public function test_a_line_with_a_null_amount_is_unverified_not_zero(): void
    {
        // A legacy line missing net_price: InvoiceVatBreakdown would cast it to 0.0
        // and produce a plausible-looking total. Must fail closed instead.
        $inv = $this->invoice();
        $this->rawLine($inv, ['net_price' => null]);

        $filed = FiledInvoiceTotals::for($inv);
        $this->assertNull($filed->net, 'null net_price → unverified, not a 0,00 roll-up');
        $this->assertNull($filed->gross);
    }

    public function test_a_line_with_a_null_vat_rate_is_unverified(): void
    {
        $inv = $this->invoice();
        $this->rawLine($inv, ['vat_percent' => null]);

        $this->assertNull(FiledInvoiceTotals::for($inv)->net);
        $this->assertNull(FiledInvoiceTotals::for($inv)->gross);
    }

    public function test_a_100_percent_header_discount_is_unverified_not_an_exception(): void
    {
        // InvoiceVatBreakdown throws for header_discount_percent >= 100 (the legacy
        // UI allowed it). FiledInvoiceTotals must catch that so one historical row
        // is reported unverified instead of aborting the whole reconciliation.
        $inv = $this->invoice(['header_discount_percent' => 100]);
        $this->line($inv);

        $filed = FiledInvoiceTotals::for($inv);   // must NOT throw
        $this->assertNull($filed->net);
        $this->assertNull($filed->gross);
    }

    public function test_withholding_without_a_category_keeps_net_but_drops_gross(): void
    {
        $inv = $this->invoice(['withhold_amount' => 20.00, 'withhold_category' => null]);
        $this->line($inv);

        $filed = FiledInvoiceTotals::for($inv);
        $this->assertSame(100.00, $filed->net, 'net is unaffected by withholding');
        $this->assertNull($filed->gross, 'gross is unknowable without the §8.4 category');
    }
}
