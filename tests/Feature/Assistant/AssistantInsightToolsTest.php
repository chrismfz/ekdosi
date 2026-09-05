<?php

namespace Tests\Feature\Assistant;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Assistant\ToolRegistry;
use App\Services\Assistant\Tools\CountSalesTool;
use App\Services\Assistant\Tools\FindCustomerTool;
use App\Services\Assistant\Tools\ListTopDebtorsTool;
use App\Services\Assistant\Tools\RecentInvoicesTool;
use App\Services\Assistant\Tools\VatSummaryTool;
use App\Support\Assistant\ChatMarkup;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AssistantInsightToolsTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private PaymentMethod $credit;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->tenant = Company::create([
            'name' => 'Insight OE', 'slug' => 'ins-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->actingAs(User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]));
        Filament::setTenant($this->tenant);

        $this->credit = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Πίστωση', 'due_days' => 30]);
        $this->type = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1, 'mydata_type' => '2.1']);
    }

    private function debtor(string $name, float $gross): Customer
    {
        $c = Customer::create(['company_id' => $this->tenant->id, 'name' => $name, 'afm' => (string) random_int(100000000, 999999999)]);
        Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'I'.uniqid(), 'code' => 1,
            'invoice_type_id' => $this->type->id, 'payment_method_id' => $this->credit->id, 'customer_id' => $c->id,
            'issued_at' => now(), 'local_status' => 'active',
            'net_total' => round($gross / 1.24, 2), 'gross_total' => $gross, 'header_discount_percent' => 0,
        ]);

        return $c;
    }

    public function test_list_top_debtors_orders_by_balance_with_kartela_links(): void
    {
        $this->debtor('Μικρός', 100);
        $big = $this->debtor('Μεγάλος', 500);

        $res = (new ListTopDebtorsTool)->run($this->tenant, ['limit' => 5]);

        $this->assertSame(2, $res['count']);
        $this->assertSame('Μεγάλος', $res['debtors'][0]['name']); // biggest first
        $this->assertEqualsWithDelta(500.0, $res['debtors'][0]['balance'], 0.01);
        $this->assertStringContainsString((string) $big->id, $res['debtors'][0]['kartela_url']);
    }

    public function test_find_customer_matches_and_returns_links(): void
    {
        $c = $this->debtor('Παπαδόπουλος ΑΕ', 240);

        $res = (new FindCustomerTool)->run($this->tenant, ['query' => 'Παπαδ']);

        $this->assertSame(1, $res['count']);
        $this->assertSame('Παπαδόπουλος ΑΕ', $res['matches'][0]['name']);
        $this->assertEqualsWithDelta(240.0, $res['matches'][0]['balance'], 0.01);
        $this->assertNotEmpty($res['matches'][0]['kartela_url']);
        $this->assertNotEmpty($res['matches'][0]['new_invoice_url']);
    }

    public function test_recent_invoices_returns_latest_with_view_links(): void
    {
        $this->debtor('Πελ', 124);

        $res = (new RecentInvoicesTool)->run($this->tenant, []);

        $this->assertSame(1, $res['count']);
        $this->assertSame('Πελ', $res['invoices'][0]['customer']);
        $this->assertNotEmpty($res['invoices'][0]['url']);
    }

    public function test_vat_summary_computes_net_vat_gross(): void
    {
        $this->debtor('X', 124); // net 100, vat 24

        $res = (new VatSummaryTool)->run($this->tenant, []);

        $this->assertSame(1, $res['invoice_count']);
        $this->assertEqualsWithDelta(100.0, $res['net'], 0.02);
        $this->assertEqualsWithDelta(24.0, $res['vat_output'], 0.02);
        $this->assertEqualsWithDelta(124.0, $res['gross'], 0.01);
    }

    /** Issue a live credit note (positive gross) against the tenant's one sale. */
    private function fullCreditNote(float $gross): void
    {
        $original = Invoice::where('company_id', $this->tenant->id)->whereNull('credited_invoice_id')->firstOrFail();
        Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΠΙΣ'.uniqid(), 'code' => 2,
            'invoice_type_id' => $this->type->id, 'payment_method_id' => $this->credit->id, 'customer_id' => $original->customer_id,
            'issued_at' => now(), 'local_status' => 'active',
            'net_total' => round($gross / 1.24, 2), 'gross_total' => $gross,
            'credited_invoice_id' => $original->id,
        ]);
    }

    public function test_count_sales_excludes_credit_notes(): void
    {
        // MON-6: a credit note carries POSITIVE gross — it must NOT be counted as
        // a sale nor added to the turnover.
        $this->debtor('X', 124);
        $this->fullCreditNote(124);

        $res = (new CountSalesTool)->run($this->tenant, []);

        $this->assertSame(1, $res['invoice_count']);                   // credit note excluded
        $this->assertEqualsWithDelta(124.0, $res['gross_total'], 0.01);
    }

    public function test_vat_summary_subtracts_credit_notes(): void
    {
        // MON-6: output VAT nets credit notes — a €124 sale fully credited is €0
        // output VAT, not €24 (the over-declaration MON-2 documented).
        $this->debtor('X', 124); // sale: net 100, vat 24
        $this->fullCreditNote(124);

        $res = (new VatSummaryTool)->run($this->tenant, []);

        $this->assertSame(2, $res['invoice_count']);                 // both are issued output docs
        $this->assertEqualsWithDelta(0.0, $res['net'], 0.02);
        $this->assertEqualsWithDelta(0.0, $res['vat_output'], 0.02);
        $this->assertEqualsWithDelta(0.0, $res['gross'], 0.01);
    }

    /** A standalone / ETL-imported legacy credit note: is_credit TYPE, NO credited_invoice_id. */
    private function legacyCreditNote(float $gross): void
    {
        $creditType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΠΙΣ', 'name' => 'ΠΙΣΤΩΤΙΚΟ', 'invcount' => 1,
            'mydata_type' => '5.1', 'is_credit' => true,
        ]);
        $c = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Cr', 'afm' => (string) random_int(100000000, 999999999)]);
        Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΠΙΣ'.uniqid(), 'code' => 1,
            'invoice_type_id' => $creditType->id, 'payment_method_id' => $this->credit->id, 'customer_id' => $c->id,
            'issued_at' => now(), 'local_status' => 'active',
            'net_total' => round($gross / 1.24, 2), 'gross_total' => $gross,
            // NB: NO credited_invoice_id — exactly how the ETL imports legacy ΠΙΣ.
        ]);
    }

    public function test_tools_exclude_standalone_legacy_credit_notes(): void
    {
        // MON-6 (review): a credit-TYPE invoice with NO credited_invoice_id (ETL
        // legacy import) must also be recognised as a credit note — not counted
        // as a sale, and subtracted from output VAT.
        $this->debtor('X', 124); // one real sale
        $this->legacyCreditNote(124);

        $count = (new CountSalesTool)->run($this->tenant, []);
        $this->assertSame(1, $count['invoice_count']);                   // legacy credit excluded
        $this->assertEqualsWithDelta(124.0, $count['gross_total'], 0.01);

        $vat = (new VatSummaryTool)->run($this->tenant, []);
        $this->assertEqualsWithDelta(0.0, $vat['vat_output'], 0.02);     // sale VAT netted by the credit
        $this->assertEqualsWithDelta(0.0, $vat['gross'], 0.01);
    }

    public function test_registry_exposes_all_tools(): void
    {
        $names = array_column((new ToolRegistry)->definitionsFor(auth()->user()), 'name');
        $this->assertEqualsCanonicalizing(
            ['count_sales', 'outstanding_receivables', 'list_top_debtors', 'find_customer', 'recent_invoices', 'vat_summary', 'recent_activity', 'leads_pulse', 'income_vs_expense', 'top_products', 'whmcs_inbox', 'ai_usage', 'app_version', 'send_customer_statement', 'create_reminder', 'record_payment'],
            $names,
        );
    }

    public function test_chat_markup_is_safe_and_links_only_internal_urls(): void
    {
        // Bold + an internal link → rendered; an external link → inert text; raw
        // HTML → escaped (no XSS).
        $html = (string) ChatMarkup::render('**Σύνολο** [Καρτέλα](/admin/customers/5/ledger) και [κακό](https://evil.example/x) <script>alert(1)</script>');

        $this->assertStringContainsString('<strong>Σύνολο</strong>', $html);
        $this->assertStringContainsString('<a href="/admin/customers/5/ledger" class="ai-link">Καρτέλα</a>', $html);
        $this->assertStringContainsString('[κακό](https://evil.example/x)', $html); // external left inert
        $this->assertStringNotContainsString('<script>', $html);                    // escaped
    }

    public function test_chat_markup_blocks_authority_confusion_and_protocol_relative_urls(): void
    {
        config(['app.url' => 'https://app.example.com']);

        // Backslash/userinfo authority: parse_url says host=app.example.com but a
        // browser navigates to evil.com — must NOT become a clickable anchor.
        $a = (string) ChatMarkup::render('[x](https://evil.com\@app.example.com/p)');
        $this->assertStringNotContainsString('<a ', $a);

        // Protocol-relative → external authority, not a path.
        $b = (string) ChatMarkup::render('[x](//evil.com/p)');
        $this->assertStringNotContainsString('<a ', $b);

        // A genuine same-host absolute URL (any case) DOES render.
        $c = (string) ChatMarkup::render('[ok](https://APP.example.com/admin/x)');
        $this->assertStringContainsString('class="ai-link"', $c);

        // A relative path still renders.
        $d = (string) ChatMarkup::render('[ok](/admin/x)');
        $this->assertStringContainsString('<a href="/admin/x" class="ai-link">ok</a>', $d);
    }
}
