<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DeliveryMethod;
use App\Models\DistributionAim;
use App\Models\InvoiceType;
use App\Models\MetricUnit;
use App\Models\PaymentMethod;
use App\Models\ProductCategory;
use App\Models\VatCategory;
use App\Services\MyData\MyDataLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyDataLookupSeederTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): MyDataLookupSeeder
    {
        return app(MyDataLookupSeeder::class);
    }

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'T', 'slug' => 't-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
        ]);
    }

    public function test_seeds_standard_vat_categories_with_24_as_default(): void
    {
        $tenant = $this->tenant();

        $r = $this->svc()->seedVatCategories($tenant);

        $this->assertSame(7, $r['created']);   // codes 1–7
        $this->assertSame(0, $r['skipped']);

        $cats = VatCategory::where('company_id', $tenant->id)->get();
        $this->assertEqualsCanonicalizing(
            [0.0, 4.0, 6.0, 9.0, 13.0, 17.0, 24.0],
            $cats->pluck('rate')->map(fn ($r) => (float) $r)->all()
        );
        // 24% is the auto-default on first seed.
        $default = $cats->firstWhere('is_default', true);
        $this->assertNotNull($default);
        $this->assertSame(24.0, (float) $default->rate);
    }

    public function test_vat_seed_is_idempotent_and_keeps_existing(): void
    {
        $tenant = $this->tenant();
        // Pre-existing 24% with a custom description + default.
        VatCategory::create(['company_id' => $tenant->id, 'description' => 'Δικό μου 24', 'rate' => 24, 'is_default' => true]);

        $r1 = $this->svc()->seedVatCategories($tenant);
        $this->assertSame(6, $r1['created']);   // all but the existing 24%
        $this->assertSame(1, $r1['skipped']);

        // Existing 24% kept verbatim (not overwritten), still the only default.
        $this->assertSame('Δικό μου 24', VatCategory::where('company_id', $tenant->id)->where('rate', 24)->value('description'));
        $this->assertSame(1, VatCategory::where('company_id', $tenant->id)->where('is_default', true)->count());

        // Second run is a full no-op.
        $r2 = $this->svc()->seedVatCategories($tenant);
        $this->assertSame(0, $r2['created']);
        $this->assertSame(7, $r2['skipped']);
    }

    public function test_seeds_starter_invoice_types_including_goods_sale(): void
    {
        $tenant = $this->tenant();

        $r = $this->svc()->seedInvoiceTypes($tenant);
        $this->assertSame(8, $r['created']);

        // The "κόψε εμπόρευμα" case exists now: 1.1 Τιμολόγιο Πώλησης, goods.
        $goods = InvoiceType::where('company_id', $tenant->id)->where('code', 'ΤΙΜ')->first();
        $this->assertNotNull($goods);
        $this->assertTrue((bool) $goods->mydata_requires_quantity);

        // Credit type flagged; service type present.
        $this->assertTrue((bool) InvoiceType::where('company_id', $tenant->id)->where('mydata_type', '5.1')->value('is_credit'));
        $this->assertNotNull(InvoiceType::where('company_id', $tenant->id)->where('code', 'ΤΠΥ')->first());
    }

    public function test_seeds_invoice_types_pre_classified_by_the_book(): void
    {
        $tenant = $this->tenant();
        $this->svc()->seedInvoiceTypes($tenant);

        $by = fn (string $code) => InvoiceType::where('company_id', $tenant->id)->where('code', $code)->first();

        // Services B2B (ΤΠΥ): 2.1 → E3_561_001 / category1_3 (παροχή υπηρεσιών).
        $tpy = $by('ΤΠΥ');
        $this->assertSame('2.1', $tpy->mydata_type);
        $this->assertSame('E3_561_001', $tpy->mydata_income_class);
        $this->assertSame('category1_3', $tpy->mydata_income_class_category);

        // Goods B2B (ΤΙΜ): 1.1 → E3_561_001 / category1_1 (εμπορεύματα).
        $tim = $by('ΤΙΜ');
        $this->assertSame('1.1', $tim->mydata_type);
        $this->assertSame('E3_561_001', $tim->mydata_income_class);
        $this->assertSame('category1_1', $tim->mydata_income_class_category);

        // Retail services (ΑΠΥ): 11.2 → E3_561_003 (λιανικές) / category1_3.
        $apy = $by('ΑΠΥ');
        $this->assertSame('E3_561_003', $apy->mydata_income_class);
        $this->assertSame('category1_3', $apy->mydata_income_class_category);

        // Intra-community (ΕΝΔ): 1.2 → E3_561_005.
        $this->assertSame('E3_561_005', $by('ΕΝΔ')->mydata_income_class);

        // Delivery note (ΔΑΠ, 9.3): NO income classification.
        $dap = $by('ΔΑΠ');
        $this->assertNull($dap->mydata_income_class);
        $this->assertNull($dap->mydata_income_class_category);
    }

    public function test_invoice_type_seed_completes_income_chain_on_matching_type(): void
    {
        $tenant = $this->tenant();
        // Imported ΤΠΥ has the doc type but NO income classification.
        InvoiceType::create(['company_id' => $tenant->id, 'code' => 'ΤΠΥ', 'name' => 'Δικό μου', 'invcount' => 50, 'mydata_type' => '2.1']);

        $r = $this->svc()->seedInvoiceTypes($tenant);
        $this->assertSame(7, $r['created']);    // all but ΤΠΥ
        $this->assertSame(1, $r['filled']);     // ΤΠΥ income chain back-filled (type matches)
        $this->assertSame(0, $r['skipped']);

        // Existing ΤΠΥ kept (invcount + name + type untouched); income completed.
        $row = InvoiceType::where('company_id', $tenant->id)->where('code', 'ΤΠΥ')->first();
        $this->assertSame(50, (int) $row->invcount);
        $this->assertSame('Δικό μου', $row->name);
        $this->assertSame('2.1', $row->mydata_type);
        $this->assertSame('E3_561_001', $row->mydata_income_class);
        $this->assertSame('category1_3', $row->mydata_income_class_category);
    }

    public function test_seeds_payment_methods_with_mydata_types_and_zero_due_days(): void
    {
        $tenant = $this->tenant();

        $r = $this->svc()->seedPaymentMethods($tenant);
        $this->assertSame(8, $r['created']);

        $cash = PaymentMethod::where('company_id', $tenant->id)->where('description', 'Μετρητά')->first();
        $this->assertSame(3, (int) $cash->mydata_payment_type);   // §8.12 code 3 = Μετρητά
        $this->assertSame(0, (int) $cash->due_days);

        // Idempotent.
        $this->assertSame(0, $this->svc()->seedPaymentMethods($tenant)['created']);
    }

    public function test_seeds_distribution_aims_with_polisi_first(): void
    {
        $tenant = $this->tenant();

        $r = $this->svc()->seedDistributionAims($tenant);
        $this->assertSame(7, $r['created']);
        $this->assertNotNull(DistributionAim::where('company_id', $tenant->id)->where('description', 'Πώληση')->first());
    }

    public function test_seeds_metric_units_and_delivery_methods_and_product_categories(): void
    {
        $tenant = $this->tenant();

        $this->assertSame(10, $this->svc()->seedMetricUnits($tenant)['created']);
        $this->assertNotNull(MetricUnit::where('company_id', $tenant->id)->where('name', 'ΥΠΗΡΕΣΙΑ')->first());

        $this->assertSame(6, $this->svc()->seedDeliveryMethods($tenant)['created']);
        $this->assertNotNull(DeliveryMethod::where('company_id', $tenant->id)->where('description', 'Courier')->first());

        $this->assertSame(3, $this->svc()->seedProductCategories($tenant)['created']);
        $this->assertNotNull(ProductCategory::where('company_id', $tenant->id)->where('description_short', 'Υπηρεσίες')->first());

        // All three are idempotent on a second run.
        $this->assertSame(0, $this->svc()->seedMetricUnits($tenant)['created']);
        $this->assertSame(0, $this->svc()->seedDeliveryMethods($tenant)['created']);
        $this->assertSame(0, $this->svc()->seedProductCategories($tenant)['created']);
    }

    public function test_seed_does_not_impose_income_chain_when_operator_reclassified_the_type(): void
    {
        $tenant = $this->tenant();
        // Operator reclassified ΤΙΜ (seed = 1.1 goods) to 1.2 intra-community,
        // leaving income blank. The seed's 1.1 income chain must NOT be imposed
        // — it belongs to a different document kind.
        InvoiceType::create(['company_id' => $tenant->id, 'code' => 'ΤΙΜ', 'name' => 'ΤΙΜ', 'invcount' => 1, 'mydata_type' => '1.2']);

        $r = $this->svc()->seedInvoiceTypes($tenant);
        $this->assertSame(0, $r['filled']);
        $this->assertSame(1, $r['skipped']);

        $tim = InvoiceType::where('company_id', $tenant->id)->where('code', 'ΤΙΜ')->first();
        $this->assertSame('1.2', $tim->mydata_type);
        $this->assertNull($tim->mydata_income_class, 'seed must not impose its 1.1 income class on a 1.2 row');
    }

    public function test_seed_backfills_mydata_type_on_existing_row_without_one(): void
    {
        $tenant = $this->tenant();
        // Mirrors a legacy-imported ΤΙΜ with NO myDATA classification + a custom
        // series counter the operator must keep.
        InvoiceType::create(['company_id' => $tenant->id, 'code' => 'ΤΙΜ', 'name' => 'Τιμολόγιο πώλησης', 'invcount' => 3, 'mydata_type' => null]);

        $r = $this->svc()->seedInvoiceTypes($tenant);

        // ΤΙΜ was filled (not skipped); the other 7 are created.
        $this->assertSame(7, $r['created']);
        $this->assertSame(1, $r['filled']);
        $this->assertSame(0, $r['skipped']);

        $tim = InvoiceType::where('company_id', $tenant->id)->where('code', 'ΤΙΜ')->first();
        $this->assertSame('1.1', $tim->mydata_type, 'goods sale class back-filled');
        $this->assertTrue((bool) $tim->mydata_requires_quantity, 'goods → quantity required');
        $this->assertSame(3, (int) $tim->invcount, 'operator counter preserved');
        $this->assertSame('Τιμολόγιο πώλησης', $tim->name, 'operator name preserved');
    }

    public function test_seed_never_overwrites_an_operator_set_mydata_type(): void
    {
        $tenant = $this->tenant();
        // Operator deliberately classified ΤΙΜ as something else — must survive.
        InvoiceType::create(['company_id' => $tenant->id, 'code' => 'ΤΙΜ', 'name' => 'ΤΙΜ', 'invcount' => 1, 'mydata_type' => '1.2']);

        $r = $this->svc()->seedInvoiceTypes($tenant);

        $this->assertSame(0, $r['filled']);
        $this->assertSame(1, $r['skipped']);
        $this->assertSame('1.2', InvoiceType::where('company_id', $tenant->id)->where('code', 'ΤΙΜ')->value('mydata_type'));
    }
}
