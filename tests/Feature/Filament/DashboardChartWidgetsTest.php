<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\RevenueByCategoryChart;
use App\Filament\Widgets\TopProductsChart;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\ProductCategory;
use App\Models\User;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * #8 dashboard widgets: «Κορυφαία είδη/υπηρεσίες» + «Έσοδα ανά κατηγορία».
 * Both reuse existing tenant-scoped services; the widgets add only the chart
 * shaping (top-N-by-net + revenue-first ordering + label truncation) and the
 * no-tenant guard — which is what these assert.
 */
class DashboardChartWidgetsTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-17 12:00:00'));

        $this->tenant = Company::create([
            'name' => 'W', 'slug' => 'w-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'ΠΕΛΑΤΗΣ ΑΕ']);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΤΠΥ', 'name' => 'ΤΠΥ', 'invcount' => 1,
        ]);

        // Filament::setTenant fires TenantSet, which needs an authenticated user.
        $operator = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $this->tenant->users()->attach($operator->id);
        Gate::before(fn () => true);
        $this->actingAs($operator);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Active invoice with one line (net = qty×price) carrying a descr + optional category. */
    private function lineInvoice(string $descr, float $net, ?int $categoryId = null): void
    {
        static $n = 0;
        $n++;

        $invoice = Invoice::create([
            'company_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'invoice_type_id' => $this->type->id,
            'code' => $n, 'invcode' => 'ΤΠΥ'.$n,
            'issued_at' => '2026-05-01 10:00:00',
            'local_status' => 'active',
        ]);
        InvoiceLine::create([
            'company_id' => $this->tenant->id,
            'invoice_id' => $invoice->id,
            'qty' => 1,
            'price_per_item' => $net, // qty 1, no discount → net_price = $net
            'discount' => 0,
            'vat_percent' => 24,
            'product_descr' => $descr,
            'product_category_id' => $categoryId,
        ]);
    }

    public function test_top_products_chart_ranks_by_net_descending(): void
    {
        $this->lineInvoice('Alpha', 100);
        $this->lineInvoice('Bravo', 300);
        $this->lineInvoice('Charlie', 200);

        Filament::setTenant($this->tenant);
        $data = (new TopProductsChart)->getData();

        $this->assertSame([300.0, 200.0, 100.0], $data['datasets'][0]['data']);
        $this->assertSame(['Bravo', 'Charlie', 'Alpha'], $data['labels']);
    }

    public function test_revenue_by_category_chart_is_revenue_first(): void
    {
        $small = ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'Μικρή', 'markup' => 0]);
        $big = ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'Μεγάλη', 'markup' => 0]);
        $this->lineInvoice('a', 200, $small->id);
        $this->lineInvoice('b', 500, $big->id);

        Filament::setTenant($this->tenant);
        $data = (new RevenueByCategoryChart)->getData();

        $this->assertSame([500.0, 200.0], $data['datasets'][0]['data']);
        $this->assertSame(['Μεγάλη', 'Μικρή'], $data['labels']);
    }

    public function test_revenue_by_category_chart_drops_zero_net_categories(): void
    {
        $real = ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'Πραγματική', 'markup' => 0]);
        $zero = ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'Μηδενική', 'markup' => 0]);
        $this->lineInvoice('a', 100, $real->id);
        $this->lineInvoice('b', 0, $zero->id); // a line with net 0 → category nets to 0

        Filament::setTenant($this->tenant);
        $data = (new RevenueByCategoryChart)->getData();

        $this->assertSame([100.0], $data['datasets'][0]['data']);
        $this->assertSame(['Πραγματική'], $data['labels']);
    }

    public function test_widgets_are_empty_without_a_tenant(): void
    {
        // No tenant set on this request → the guard returns an empty chart.
        $this->assertSame(['datasets' => [], 'labels' => []], (new TopProductsChart)->getData());
        $this->assertSame(['datasets' => [], 'labels' => []], (new RevenueByCategoryChart)->getData());
    }
}
