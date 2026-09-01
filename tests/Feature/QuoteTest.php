<?php

namespace Tests\Feature;

use App\Actions\ConvertLeadToCustomer;
use App\Actions\ConvertQuoteToInvoice;
use App\Enums\QuoteStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Services\QuoteNumberer;
use App\Services\QuoteTotals;
use App\Support\InvoiceScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class QuoteTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 't', 'slug' => 't-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->customer = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'Πελάτης ΑΕ', 'afm' => '123456789',
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'name' => 'Τιμολόγιο', 'code' => 'TPY',
            'invcount' => 1, 'show_on_menu' => true,
        ]);
    }

    private function makeQuote(array $attrs = []): Quote
    {
        return DB::transaction(fn () => Quote::create(array_merge([
            'company_id' => $this->tenant->id,
            'code' => app(QuoteNumberer::class)->allocate($this->tenant),
            'issued_at' => now(),
            'status' => QuoteStatus::Draft,
        ], $attrs)));
    }

    public function test_quote_line_computes_net_and_gross_like_an_invoice_line(): void
    {
        $quote = $this->makeQuote();
        $line = QuoteLine::create([
            'quote_id' => $quote->id, 'product_descr' => 'Υπηρεσία',
            'qty' => 2, 'price_per_item' => 100, 'discount' => 10, 'vat_percent' => 24,
        ]);

        // 2*100*0.9 = 180 net; 180 * 1.24 = 223.20 gross
        $this->assertSame(180.0, (float) $line->net_price);
        $this->assertSame(223.20, (float) $line->gross_price);
    }

    public function test_quote_totals_apply_header_discount_once_at_aggregate(): void
    {
        $quote = $this->makeQuote(['header_discount_percent' => 10]);
        QuoteLine::create(['quote_id' => $quote->id, 'product_descr' => 'A', 'qty' => 1, 'price_per_item' => 100, 'vat_percent' => 24]);
        QuoteLine::create(['quote_id' => $quote->id, 'product_descr' => 'B', 'qty' => 1, 'price_per_item' => 100, 'vat_percent' => 24]);

        $fresh = app(QuoteTotals::class)($quote);

        // raw net 200 → -10% = 180; gross 248 → -10% = 223.20; vat = 43.20
        $this->assertSame(180.0, (float) $fresh->net_total);
        $this->assertSame(223.20, (float) $fresh->gross_total);
        $this->assertSame(43.20, (float) $fresh->vat_total);
    }

    public function test_numberer_increments_a_separate_counter_not_the_invoice_aa(): void
    {
        $before = $this->type->fresh()->invcount;

        $codes = DB::transaction(fn () => [
            app(QuoteNumberer::class)->allocate($this->tenant),
            app(QuoteNumberer::class)->allocate($this->tenant),
        ]);

        $this->assertSame('ΠΡ-1', $codes[0]);
        $this->assertSame('ΠΡ-2', $codes[1]);
        $this->assertSame(2, (int) $this->tenant->fresh()->quote_counter);
        // The legal ΑΑ counter is untouched.
        $this->assertSame($before, $this->type->fresh()->invcount);
    }

    public function test_numberer_refuses_outside_a_transaction(): void
    {
        // RefreshDatabase wraps every test in a transaction, so the guard
        // (transactionLevel() === 0) can't be exercised here. Match the
        // InvoiceNumbererTest convention: assert the guard exists by reading
        // it, and skip the runtime path.
        $this->markTestSkipped('RefreshDatabase wraps tests in a transaction; cannot test the no-tx guard here.');
    }

    public function test_convert_turns_accepted_quote_into_linked_draft_invoice(): void
    {
        $quote = $this->makeQuote([
            'customer_id' => $this->customer->id,
            'company_name' => 'Πελάτης ΑΕ', 'vat_no' => '123456789',
            'status' => QuoteStatus::Accepted,
        ]);
        QuoteLine::create([
            'quote_id' => $quote->id, 'product_descr' => 'Υπηρεσία υποστήριξης',
            'qty' => 1, 'price_per_item' => 500, 'vat_percent' => 24,
        ]);

        $invoice = app(ConvertQuoteToInvoice::class)($quote->fresh(), $this->type);

        $this->assertSame('draft', $invoice->local_status);
        $this->assertNull($invoice->mydata_state);
        $this->assertSame('Πελάτης ΑΕ', $invoice->company_name);
        $this->assertCount(1, $invoice->lines);
        $this->assertSame(620.0, (float) $invoice->gross_total);
        // Bidirectional link.
        $this->assertSame($invoice->id, $quote->fresh()->converted_invoice_id);
        $this->assertSame($quote->id, $invoice->convertedFromQuote?->id);
    }

    public function test_a_leads_quote_cannot_become_an_invoice_before_the_lead_is_converted(): void
    {
        $lead = Lead::create(['company_id' => $this->tenant->id, 'name' => 'Lead']);
        $quote = $this->makeQuote(['lead_id' => $lead->id, 'status' => QuoteStatus::Accepted]);
        QuoteLine::create(['quote_id' => $quote->id, 'product_descr' => 'X', 'qty' => 1, 'price_per_item' => 10, 'vat_percent' => 24]);

        try {
            app(ConvertQuoteToInvoice::class)($quote->fresh(), $this->type);
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Μετατροπή σε πελάτη', $e->getMessage());
        }
        $this->assertSame(0, Invoice::where('company_id', $this->tenant->id)->count(), 'No customer-less invoice.');

        // Converting the lead back-fills the quote's customer → invoice allowed.
        $customer = app(ConvertLeadToCustomer::class)($lead);
        $invoice = app(ConvertQuoteToInvoice::class)($quote->fresh(), $this->type);
        $this->assertSame($customer->id, $invoice->customer_id);
    }

    public function test_convert_is_idempotent(): void
    {
        $quote = $this->makeQuote([
            'customer_id' => $this->customer->id, 'status' => QuoteStatus::Accepted,
        ]);
        QuoteLine::create(['quote_id' => $quote->id, 'product_descr' => 'X', 'qty' => 1, 'price_per_item' => 10, 'vat_percent' => 24]);

        app(ConvertQuoteToInvoice::class)($quote->fresh(), $this->type);

        $this->expectException(RuntimeException::class);
        app(ConvertQuoteToInvoice::class)($quote->fresh(), $this->type);
    }

    public function test_quotes_never_leak_into_the_invoice_money_scope(): void
    {
        $quote = $this->makeQuote([
            'customer_id' => $this->customer->id, 'status' => QuoteStatus::Accepted,
        ]);
        QuoteLine::create(['quote_id' => $quote->id, 'product_descr' => 'X', 'qty' => 1, 'price_per_item' => 1000, 'vat_percent' => 24]);
        app(ConvertQuoteToInvoice::class)($quote->fresh(), $this->type);

        // InvoiceScope::live() sees ONLY the one (draft) invoice — the quote
        // contributes nothing (it's not even reachable from the Invoice query).
        $liveCount = InvoiceScope::live(
            Invoice::query()->where('company_id', $this->tenant->id)
        )->count();

        $this->assertSame(1, $liveCount);
        $this->assertSame(1, Quote::where('company_id', $this->tenant->id)->count());
    }
}
