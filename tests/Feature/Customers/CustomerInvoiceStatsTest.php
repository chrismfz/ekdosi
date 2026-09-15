<?php

namespace Tests\Feature\Customers;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Services\RecomputeInvoiceTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The sales-activity columns on the Πελάτες list (Customer::scopeWithInvoiceStats):
 *   «Αρ. Παρ/ων» (invoice_count), «Τζίρος» (turnover = net), «Τελ. παρ/κό»
 *   (last_invoiced_at) — all sortable SQL aliases, plus the «Δραστηριότητα»
 *   (has_invoices) filter that reads the SAME invoice_count alias so column and
 *   filter can't disagree.
 *
 * The contract: "real issued παραστατικά" — LIVE, NOT credit notes, NOT unissued
 * sale drafts (προτιμολόγια). Both cash- and credit-term count (activity, not a
 * receivable). Mirrors the production query (withOutstandingBalance chained first,
 * so withInvoiceStats' addSelect appends without clobbering outstanding_balance).
 */
class CustomerInvoiceStatsTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private PaymentMethod $credit;

    private InvoiceType $saleType;

    private InvoiceType $creditType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 't', 'slug' => 't-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->credit = PaymentMethod::create([
            'company_id' => $this->tenant->id, 'description' => 'Πίστωση', 'due_days' => 30,
        ]);
        $this->saleType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1,
        ]);
        $this->creditType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'name' => 'Πιστωτικό', 'code' => 'ΠΤ',
            'invcount' => 1, 'is_credit' => true,
        ]);
    }

    /** An issued credit-term sale (line net = $net) on $issuedAt. */
    private function makeSale(Customer $c, int $net, string $issuedAt): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΤΠΥ'.uniqid(), 'code' => 1,
            'invoice_type_id' => $this->saleType->id, 'customer_id' => $c->id,
            'payment_method_id' => $this->credit->id, 'issued_at' => $issuedAt,
            'local_status' => 'active',
        ]);
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'qty' => 1, 'price_per_item' => $net, 'vat_percent' => 24, 'product_descr' => 'W',
        ]);

        return app(RecomputeInvoiceTotals::class)($inv);
    }

    /** An UNISSUED sale draft (new-app: local draft, legacy_id null, not credit). */
    private function makeDraft(Customer $c, string $issuedAt): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΤΠΥ'.uniqid(), 'code' => 1,
            'invoice_type_id' => $this->saleType->id, 'customer_id' => $c->id,
            'payment_method_id' => $this->credit->id, 'issued_at' => $issuedAt,
            'local_status' => 'draft',
        ]);
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'qty' => 1, 'price_per_item' => 500, 'vat_percent' => 24, 'product_descr' => 'D',
        ]);

        return app(RecomputeInvoiceTotals::class)($inv);
    }

    /** A standalone legacy credit note (is_credit type, no credited_invoice_id). */
    private function makeCreditNote(Customer $c, string $issuedAt): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΠΤ'.uniqid(), 'code' => 1,
            'invoice_type_id' => $this->creditType->id, 'customer_id' => $c->id,
            'payment_method_id' => $this->credit->id, 'issued_at' => $issuedAt,
        ]);
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'qty' => 1, 'price_per_item' => 70, 'vat_percent' => 24, 'product_descr' => 'ΠΙΣ',
        ]);

        return app(RecomputeInvoiceTotals::class)($inv);
    }

    private function stat(Customer $c): Customer
    {
        return Customer::query()
            ->where('customers.company_id', $this->tenant->id)
            ->withOutstandingBalance($this->tenant->id)
            ->withInvoiceStats($this->tenant->id)
            ->where('customers.id', $c->id)
            ->firstOrFail();
    }

    public function test_count_turnover_and_last_date_over_real_issued_invoices(): void
    {
        $c = Customer::create(['company_id' => $this->tenant->id, 'name' => 'A', 'afm' => '100000001']);
        $this->makeSale($c, 100, '2026-05-10 10:00:00');
        $this->makeSale($c, 100, '2026-06-15 10:00:00');   // later → drives MAX

        $row = $this->stat($c);

        $this->assertSame(2, (int) $row->invoice_count);
        $this->assertEqualsWithDelta(200.0, (float) $row->turnover, 0.001);
        $this->assertSame('2026-06-15', Carbon::parse($row->last_invoiced_at)->toDateString());

        // outstanding_balance alias still present (addSelect didn't clobber it).
        $this->assertNotNull($row->outstanding_balance);
    }

    public function test_drafts_are_excluded_and_credit_notes_net_the_turnover(): void
    {
        $c = Customer::create(['company_id' => $this->tenant->id, 'name' => 'C', 'afm' => '100000003']);
        $this->makeSale($c, 100, '2026-03-01 10:00:00');       // sale, net 100
        $this->makeDraft($c, '2026-09-01 10:00:00');           // later, but a draft → wholly excluded
        $this->makeCreditNote($c, '2026-08-01 10:00:00');      // return, net 70 → NETS the turnover

        $row = $this->stat($c);

        // Count = SALES only: the credit note and the draft are not τιμολόγια.
        $this->assertSame(1, (int) $row->invoice_count, 'only the issued sale counts');
        // Turnover = net κύκλος εργασιών = sale 100 − return 70 (LedgerBook parity),
        // and the €500 draft contributes nothing.
        $this->assertEqualsWithDelta(30.0, (float) $row->turnover, 0.001, 'credit note nets, draft excluded');
        // MAX(sale issued_at) ignores the later draft + credit note → the real sale.
        $this->assertSame('2026-03-01', Carbon::parse($row->last_invoiced_at)->toDateString());
    }

    public function test_dead_customer_reads_zero_and_null(): void
    {
        $c = Customer::create(['company_id' => $this->tenant->id, 'name' => 'B', 'afm' => '100000002']);

        $row = $this->stat($c);

        $this->assertSame(0, (int) $row->invoice_count);
        $this->assertEqualsWithDelta(0.0, (float) $row->turnover, 0.001);
        $this->assertNull($row->last_invoiced_at);
    }

    public function test_has_invoices_filter_splits_alive_from_dead(): void
    {
        $alive = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Alive', 'afm' => '100000004']);
        $dead = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Dead', 'afm' => '100000005']);
        $this->makeSale($alive, 100, '2026-05-10 10:00:00');

        // Mirror the production query: modifyQueryUsing chains both scopes, so
        // customers.* is selected (by withOutstandingBalance) before the filter's
        // whereRaw reads the cust_stats.invoice_count alias.
        $base = fn () => Customer::query()
            ->where('customers.company_id', $this->tenant->id)
            ->withOutstandingBalance($this->tenant->id)
            ->withInvoiceStats($this->tenant->id);

        $withInvoices = $base()->whereRaw(Customer::INVOICE_COUNT_SQL.' > 0')->pluck('customers.id');
        $withoutInvoices = $base()->whereRaw(Customer::INVOICE_COUNT_SQL.' = 0')->pluck('customers.id');

        $this->assertTrue($withInvoices->contains($alive->id));
        $this->assertFalse($withInvoices->contains($dead->id));
        $this->assertTrue($withoutInvoices->contains($dead->id));
        $this->assertFalse($withoutInvoices->contains($alive->id));
    }

    public function test_has_email_filter_treats_null_and_empty_as_missing(): void
    {
        $withEmail = Customer::create(['company_id' => $this->tenant->id, 'name' => 'HasMail', 'afm' => '100000006', 'email' => 'x@y.gr']);
        $nullEmail = Customer::create(['company_id' => $this->tenant->id, 'name' => 'NullMail', 'afm' => '100000007', 'email' => null]);
        $emptyEmail = Customer::create(['company_id' => $this->tenant->id, 'name' => 'EmptyMail', 'afm' => '100000008', 'email' => '']);

        $missing = Customer::query()
            ->where('company_id', $this->tenant->id)
            ->where(fn ($q) => $q->whereNull('email')->orWhere('email', '=', ''))
            ->pluck('id');
        $present = Customer::query()
            ->where('company_id', $this->tenant->id)
            ->whereNotNull('email')->where('email', '!=', '')
            ->pluck('id');

        $this->assertEqualsCanonicalizing([$nullEmail->id, $emptyEmail->id], $missing->all());
        $this->assertSame([$withEmail->id], $present->all());
    }
}
