<?php

namespace Tests\Feature\Accounting;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VatCategory;
use App\Services\Accounting\RevenueByCategory;
use App\Services\Accounting\RevenueByItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Ισοζύγιο Ειδών/Υπηρεσιών» (#4): turnover grouped at the item grain — product
 * lines by product, free-text lines by their normalised description (renewals of
 * the same package fold together); credit notes net negative (value + qty); YoY;
 * and Σ(items) reconciles with «Έσοδα ανά κατηγορία» (#2).
 */
class RevenueByItemTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'Item', 'slug' => 'item-'.uniqid(), 'country_code' => 'GR',
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

    /**
     * @param  list<array{descr?:string,product_id?:?int,cat_id?:?int,price:float,qty?:float,unit?:?string}>  $lines
     */
    private function invoice(string $issuedAt, array $lines, bool $credit = false, int $headerDiscount = 0): Invoice
    {
        $customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'C'.(++self::$seq)]);
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'customer_id' => $customer->id,
            'invoice_type_id' => $this->type($credit)->id,
            'code' => ++self::$seq, 'invcode' => 'I'.self::$seq, 'issued_at' => $issuedAt,
            'local_status' => 'active', 'header_discount_percent' => $headerDiscount,
        ]);
        foreach ($lines as $l) {
            InvoiceLine::create([
                'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
                'product_id' => $l['product_id'] ?? null,
                'product_category_id' => $l['cat_id'] ?? null,
                'qty' => $l['qty'] ?? 1, 'price_per_item' => $l['price'], 'vat_percent' => 24,
                'product_descr' => $l['descr'] ?? 'x',
                'metric_unit' => $l['unit'] ?? null,
            ]);
        }

        return $inv;
    }

    public function test_folds_dated_renewals_of_the_same_package_into_one_row(): void
    {
        // Two yearly renewals: same package, different embedded periods → ONE row.
        $this->invoice('2026-02-01', [['descr' => 'Cloud VPS 4GB (1/1/2026-31/12/2026)', 'price' => 120, 'unit' => 'ΕΤΟΣ']]);
        $this->invoice('2026-08-01', [['descr' => 'Cloud VPS 4GB (1/1/2027-31/12/2027)', 'price' => 130, 'unit' => 'ΕΤΟΣ']]);

        $rows = app(RevenueByItem::class)->build($this->tenant, 2026)['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('Cloud VPS 4GB', $rows[0]['label']);
        $this->assertEqualsWithDelta(250.0, $rows[0]['net'], 0.01); // 120 + 130
        $this->assertSame(2, $rows[0]['lines']);
        $this->assertEqualsWithDelta(2.0, $rows[0]['qty'], 0.001);
        $this->assertSame('ΕΤΟΣ', $rows[0]['unit']);
    }

    public function test_product_lines_group_by_product_regardless_of_description(): void
    {
        $vat = VatCategory::create(['company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24]);
        $servers = $this->category('Servers');
        $product = Product::create([
            'company_id' => $this->tenant->id, 'description_short' => 'Dedicated Server', 'sell_price' => 200,
            'product_category_id' => $servers->id, 'vat_category_id' => $vat->id,
        ]);

        // Same product, two DIFFERENT free-text descriptions → still one row (product identity wins).
        $this->invoice('2026-03-01', [['product_id' => $product->id, 'descr' => 'Setup A', 'price' => 200, 'cat_id' => $servers->id]]);
        $this->invoice('2026-04-01', [['product_id' => $product->id, 'descr' => 'Setup B', 'price' => 300, 'cat_id' => $servers->id]]);

        $rows = app(RevenueByItem::class)->build($this->tenant, 2026)['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('Dedicated Server', $rows[0]['label']);       // product name, not the line descr
        $this->assertEqualsWithDelta(500.0, $rows[0]['net'], 0.01);
        $this->assertSame('Servers', $rows[0]['category']);
    }

    public function test_mixed_unit_item_blanks_the_quantity(): void
    {
        // Same item billed once in ΤΕΜ, once in ΩΡΑ → a summed qty under one unit
        // would be misleading, so qty + unit are blanked (the value still nets).
        $this->invoice('2026-02-01', [['descr' => 'Onsite', 'price' => 100, 'qty' => 5, 'unit' => 'ΤΕΜ']]);
        $this->invoice('2026-03-01', [['descr' => 'Onsite', 'price' => 60, 'qty' => 3, 'unit' => 'ΩΡΑ']]);

        $rows = collect(app(RevenueByItem::class)->build($this->tenant, 2026)['rows'])->keyBy('label');

        $this->assertNull($rows['Onsite']['qty']);
        $this->assertNull($rows['Onsite']['unit']);
        // Value still sums: 5×100 + 3×60 = 680 (net_price = qty × price_per_item).
        $this->assertEqualsWithDelta(680.0, $rows['Onsite']['net'], 0.01);
    }

    public function test_unit_ed_mixed_with_unitless_also_blanks_the_quantity(): void
    {
        // One line has a unit, another has none → the summed qty can't be trusted
        // under that single unit, so it blanks too (not just the 2+-distinct-unit case).
        $this->invoice('2026-02-01', [['descr' => 'Retainer', 'price' => 100, 'qty' => 4, 'unit' => 'ΤΕΜ']]);
        $this->invoice('2026-03-01', [['descr' => 'Retainer', 'price' => 100, 'qty' => 2]]); // no unit

        $rows = collect(app(RevenueByItem::class)->build($this->tenant, 2026)['rows'])->keyBy('label');

        $this->assertNull($rows['Retainer']['qty']);
        $this->assertNull($rows['Retainer']['unit']);
    }

    public function test_all_unitless_item_still_reports_a_bare_quantity(): void
    {
        // Every line unit-less (a pure count) → qty shown, no unit label.
        $this->invoice('2026-02-01', [['descr' => 'Consulting', 'price' => 50, 'qty' => 3]]);
        $this->invoice('2026-03-01', [['descr' => 'Consulting', 'price' => 50, 'qty' => 2]]);

        $rows = collect(app(RevenueByItem::class)->build($this->tenant, 2026)['rows'])->keyBy('label');

        $this->assertEqualsWithDelta(5.0, $rows['Consulting']['qty'], 0.001);
        $this->assertNull($rows['Consulting']['unit']);
    }

    public function test_single_unit_item_reports_qty_and_unit(): void
    {
        $this->invoice('2026-02-01', [['descr' => 'Licenses', 'price' => 10, 'qty' => 4, 'unit' => 'Τεμάχιο']]);
        $this->invoice('2026-03-01', [['descr' => 'Licenses', 'price' => 10, 'qty' => 8, 'unit' => 'Τεμάχιο']]);

        $rows = collect(app(RevenueByItem::class)->build($this->tenant, 2026)['rows'])->keyBy('label');

        $this->assertEqualsWithDelta(12.0, $rows['Licenses']['qty'], 0.001);
        $this->assertSame('Τεμάχιο', $rows['Licenses']['unit']);
    }

    public function test_credit_note_nets_value_and_quantity_negative(): void
    {
        $this->invoice('2026-02-01', [['descr' => 'Support hours', 'price' => 50, 'qty' => 10]]);        // +500, +10
        $this->invoice('2026-03-01', [['descr' => 'Support hours', 'price' => 50, 'qty' => 2]], credit: true); // −100, −2

        $rows = app(RevenueByItem::class)->build($this->tenant, 2026)['rows'];

        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(400.0, $rows[0]['net'], 0.01);   // 500 − 100
        $this->assertEqualsWithDelta(8.0, $rows[0]['qty'], 0.001);    // 10 − 2
    }

    public function test_header_discount_reduces_item_turnover_to_the_document_value(): void
    {
        $this->invoice('2026-05-01', [['descr' => 'Managed Firewall', 'price' => 100]], headerDiscount: 10);

        $rows = app(RevenueByItem::class)->build($this->tenant, 2026)['rows'];

        $this->assertEqualsWithDelta(90.0, $rows[0]['net'], 0.01);    // 100 − 10%
        $this->assertEqualsWithDelta(21.60, $rows[0]['vat'], 0.01);   // 90 × 24%
        $this->assertEqualsWithDelta(111.60, $rows[0]['gross'], 0.01);
    }

    public function test_yoy_prior_and_delta(): void
    {
        $this->invoice('2025-04-01', [['descr' => 'Backup Service', 'price' => 60]]); // prior year
        $this->invoice('2026-04-01', [['descr' => 'Backup Service', 'price' => 100]]);

        $rows = collect(app(RevenueByItem::class)->build($this->tenant, 2026)['rows'])->keyBy('label');

        $this->assertEqualsWithDelta(100.0, $rows['Backup Service']['net'], 0.01);
        $this->assertEqualsWithDelta(60.0, $rows['Backup Service']['prior_net'], 0.01);
        $this->assertEqualsWithDelta(40.0, $rows['Backup Service']['delta'], 0.01);
    }

    public function test_prior_only_item_keeps_its_real_category(): void
    {
        $legacy = $this->category('Legacy Hosting');
        // Sold only last year, under a real category, nothing this year.
        $this->invoice('2025-04-01', [['descr' => 'Old Package', 'cat_id' => $legacy->id, 'price' => 80]]);
        // A current-year item so the report isn't on the empty path.
        $this->invoice('2026-04-01', [['descr' => 'New Package', 'price' => 10]]);

        $rows = collect(app(RevenueByItem::class)->build($this->tenant, 2026)['rows'])->keyBy('label');

        $this->assertTrue($rows->has('Old Package'), 'a prior-only item still shows a row (YoY)');
        $this->assertEqualsWithDelta(0.0, $rows['Old Package']['net'], 0.01);
        $this->assertEqualsWithDelta(80.0, $rows['Old Package']['prior_net'], 0.01);
        // The row must carry its real category name, NOT «Αταξινόμητα».
        $this->assertSame('Legacy Hosting', $rows['Old Package']['category']);
    }

    public function test_current_item_unclassified_this_year_does_not_inherit_last_years_category(): void
    {
        $cat = $this->category('Old Category');
        // Same item (by description) categorised LAST year, but sold this year with
        // NO category → this year's row must read «Αταξινόμητα», not «Old Category».
        $this->invoice('2025-05-01', [['descr' => 'Widget', 'cat_id' => $cat->id, 'price' => 70]]);
        $this->invoice('2026-05-01', [['descr' => 'Widget', 'price' => 90]]); // no cat_id this year

        $rows = collect(app(RevenueByItem::class)->build($this->tenant, 2026)['rows'])->keyBy('label');

        $this->assertEqualsWithDelta(90.0, $rows['Widget']['net'], 0.01);
        $this->assertSame('Αταξινόμητα', $rows['Widget']['category']);
        $this->assertNull($rows['Widget']['category_id']);
    }

    public function test_lines_without_any_identity_bucket_last(): void
    {
        $this->invoice('2026-06-01', [['descr' => 'Real Item', 'price' => 100]]);
        // A line with a blank description and no product → «(χωρίς περιγραφή)».
        $this->invoice('2026-06-02', [['descr' => '', 'price' => 30]]);

        $rows = app(RevenueByItem::class)->build($this->tenant, 2026)['rows'];

        $this->assertSame('(χωρίς περιγραφή)', end($rows)['label']);
        $this->assertEqualsWithDelta(30.0, end($rows)['net'], 0.01);
    }

    public function test_totals_reconcile_with_revenue_by_category(): void
    {
        $hosting = $this->category('Web Hosting');
        $vat = VatCategory::create(['company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24]);
        $product = Product::create([
            'company_id' => $this->tenant->id, 'description_short' => 'Dedicated', 'sell_price' => 200,
            'product_category_id' => $hosting->id, 'vat_category_id' => $vat->id,
        ]);

        $this->invoice('2026-03-01', [['descr' => 'Hosting Basic (1/1/2026-31/12/2026)', 'cat_id' => $hosting->id, 'price' => 100]]);
        $this->invoice('2026-04-01', [['product_id' => $product->id, 'cat_id' => $hosting->id, 'price' => 200]]);
        $this->invoice('2026-05-01', [['descr' => 'Ad-hoc consulting', 'price' => 50]]);            // Αταξινόμητα
        $this->invoice('2026-06-01', [['descr' => 'Hosting Basic (1/1/2027-31/12/2027)', 'cat_id' => $hosting->id, 'price' => 40]], credit: true); // −40

        $item = app(RevenueByItem::class)->build($this->tenant, 2026);
        $cat = app(RevenueByCategory::class)->build($this->tenant, 2026);

        // 100 + 200 + 50 − 40 = 310 — item and category totals must match exactly.
        $this->assertEqualsWithDelta(310.0, $item['total_net'], 0.01);
        $this->assertEqualsWithDelta($cat['total_net'], $item['total_net'], 0.01);
        $this->assertEqualsWithDelta($cat['total_vat'], $item['total_vat'], 0.02);
        $this->assertEqualsWithDelta($cat['total_gross'], $item['total_gross'], 0.02);

        // The two dated «Hosting Basic» lines (sale + credit) fold into one item row.
        $byLabel = collect($item['rows'])->keyBy('label');
        $this->assertEqualsWithDelta(60.0, $byLabel['Hosting Basic']['net'], 0.01); // 100 − 40
        $this->assertSame('Web Hosting', $byLabel['Hosting Basic']['category']);
    }
}
