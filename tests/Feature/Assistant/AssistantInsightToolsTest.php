<?php

namespace Tests\Feature\Assistant;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Assistant\ToolRegistry;
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

    public function test_registry_exposes_all_tools(): void
    {
        $names = array_column((new ToolRegistry)->definitionsFor(auth()->user()), 'name');
        $this->assertEqualsCanonicalizing(
            ['count_sales', 'outstanding_receivables', 'list_top_debtors', 'find_customer', 'recent_invoices', 'vat_summary', 'send_customer_statement', 'create_reminder'],
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
}
