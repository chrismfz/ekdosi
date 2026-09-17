<?php

namespace Tests\Feature\Accounting;

use App\Actions\IssueCreditNote;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VatCategory;
use App\Services\Accounting\RevenueByCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Έσοδα ανά κατηγορία» (#2): each line resolves to a category via its stamp
 * (WHMCS) → its product → «Αταξινόμητα»; credit notes net negative; YoY + %.
 */
class RevenueByCategoryTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'Rev', 'slug' => 'rev-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    private function category(string $name): ProductCategory
    {
        return ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => $name]);
    }

    private function type(bool $credit = false): InvoiceType
    {
        return InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'T'.(++self::$seq), 'name' => 'T',
            'invcount' => 0, 'is_credit' => $credit,
        ]);
    }

    /** @param list<array{cat_id?:?int,product_id?:?int,price:float}> $lines */
    private function invoice(string $issuedAt, array $lines, bool $credit = false): Invoice
    {
        $customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'C'.(++self::$seq)]);
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'customer_id' => $customer->id,
            'invoice_type_id' => $this->type($credit)->id,
            'code' => ++self::$seq, 'invcode' => 'I'.self::$seq, 'issued_at' => $issuedAt,
            'local_status' => 'active',
        ]);
        foreach ($lines as $l) {
            InvoiceLine::create([
                'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
                'product_id' => $l['product_id'] ?? null,
                'product_category_id' => $l['cat_id'] ?? null,
                'qty' => 1, 'price_per_item' => $l['price'], 'vat_percent' => 24,
                'product_descr' => 'x',
            ]);
        }

        return $inv;
    }

    public function test_groups_revenue_by_resolved_category_with_credit_notes_and_yoy(): void
    {
        $hosting = $this->category('Web Hosting');
        $servers = $this->category('Servers');
        $vat = VatCategory::create(['company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24]);
        $product = Product::create([
            'company_id' => $this->tenant->id, 'description_short' => 'Dedicated', 'sell_price' => 200,
            'product_category_id' => $servers->id, 'vat_category_id' => $vat->id,
        ]);

        // Current year (2026):
        $this->invoice('2026-03-01', [['cat_id' => $hosting->id, 'price' => 100]]);   // WHMCS-stamped → Hosting 100
        $this->invoice('2026-04-01', [['product_id' => $product->id, 'price' => 200]]); // product → Servers 200
        $this->invoice('2026-05-01', [['price' => 50]]);                                // neither → Αταξινόμητα 50
        $this->invoice('2026-06-01', [['cat_id' => $hosting->id, 'price' => 40]], credit: true); // credit note → Hosting −40

        // Prior year (2025) for YoY: Hosting 60.
        $this->invoice('2025-03-01', [['cat_id' => $hosting->id, 'price' => 60]]);

        $r = app(RevenueByCategory::class)->build($this->tenant, 2026);

        $this->assertSame(2026, $r['year']);
        $byName = collect($r['rows'])->keyBy('name');

        // Hosting: 100 − 40 (credit) = 60 net.
        $this->assertEqualsWithDelta(60.0, $byName['Web Hosting']['net'], 0.01);
        $this->assertEqualsWithDelta(60.0, $byName['Web Hosting']['prior_net'], 0.01); // YoY prior
        $this->assertEqualsWithDelta(0.0, $byName['Web Hosting']['delta'], 0.01);       // 60 − 60
        // Servers: 200 (product-linked).
        $this->assertEqualsWithDelta(200.0, $byName['Servers']['net'], 0.01);
        // Uncategorised bucket.
        $this->assertNull($byName['Αταξινόμητα']['category_id']);
        $this->assertEqualsWithDelta(50.0, $byName['Αταξινόμητα']['net'], 0.01);

        // Totals: 60 + 200 + 50 = 310 net; VAT 24% → 74.40.
        $this->assertEqualsWithDelta(310.0, $r['total_net'], 0.01);
        $this->assertEqualsWithDelta(74.40, $r['total_vat'], 0.02);

        // «Αταξινόμητα» always sinks to the bottom of the rows.
        $this->assertSame('Αταξινόμητα', end($r['rows'])['name']);
    }

    public function test_credit_note_via_action_reduces_the_original_category_not_uncategorised(): void
    {
        // The primary WHMCS case: a line with NO product but a stamped category.
        // IssueCreditNote must carry the stamp so the reduction nets the RIGHT
        // category (else it lands in «Αταξινόμητα» and makes it negative).
        $hosting = $this->category('Web Hosting');
        $inv = $this->invoice('2026-03-01', [['cat_id' => $hosting->id, 'price' => 100]]);
        $line = $inv->lines()->first();

        app(IssueCreditNote::class)($inv->fresh('lines'), $this->type(credit: true), [
            ['line_id' => $line->id, 'qty' => 1],
        ]);

        $byName = collect(app(RevenueByCategory::class)->build($this->tenant, 2026)['rows'])->keyBy('name');

        $this->assertEqualsWithDelta(0.0, $byName['Web Hosting']['net'], 0.01); // 100 − 100
        $this->assertFalse($byName->has('Αταξινόμητα'), 'the credit must not land in «Αταξινόμητα»');
    }

    public function test_category_with_only_prior_year_revenue_still_appears(): void
    {
        $legacy = $this->category('Legacy');
        $this->invoice('2025-01-01', [['cat_id' => $legacy->id, 'price' => 80]]); // prior year only
        $this->invoice('2026-01-01', [['cat_id' => $this->category('Now')->id, 'price' => 10]]);

        $byName = collect(app(RevenueByCategory::class)->build($this->tenant, 2026)['rows'])->keyBy('name');

        $this->assertTrue($byName->has('Legacy'), 'a prior-only category must still show a row (YoY reconciliation)');
        $this->assertEqualsWithDelta(0.0, $byName['Legacy']['net'], 0.01);
        $this->assertEqualsWithDelta(80.0, $byName['Legacy']['prior_net'], 0.01);
        $this->assertEqualsWithDelta(-80.0, $byName['Legacy']['delta'], 0.01);
    }
}
