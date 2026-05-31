<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\InvoiceType;
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
        $goods = InvoiceType::where('company_id', $tenant->id)->where('mydata_type', '1.1')->first();
        $this->assertNotNull($goods);
        $this->assertTrue((bool) $goods->mydata_requires_quantity);

        // Credit type flagged; service type present.
        $this->assertTrue((bool) InvoiceType::where('company_id', $tenant->id)->where('mydata_type', '5.1')->value('is_credit'));
        $this->assertNotNull(InvoiceType::where('company_id', $tenant->id)->where('mydata_type', '2.1')->first());
    }

    public function test_invoice_type_seed_is_idempotent_by_code(): void
    {
        $tenant = $this->tenant();
        InvoiceType::create(['company_id' => $tenant->id, 'code' => 'ΤΠΥ', 'name' => 'Δικό μου', 'invcount' => 50, 'mydata_type' => '2.1']);

        $r = $this->svc()->seedInvoiceTypes($tenant);
        $this->assertSame(7, $r['created']);    // all but ΤΠΥ
        $this->assertSame(1, $r['skipped']);    // ΤΠΥ already has a mydata_type → skipped, not filled
        $this->assertSame(0, $r['filled']);

        // Existing ΤΠΥ kept (invcount + mydata_type untouched).
        $row = InvoiceType::where('company_id', $tenant->id)->where('code', 'ΤΠΥ')->first();
        $this->assertSame(50, (int) $row->invcount);
        $this->assertSame('2.1', $row->mydata_type);
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
