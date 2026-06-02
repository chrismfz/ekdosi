<?php

namespace Tests\Feature\Etl;

use App\Console\Commands\MigrateFromFirebird;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\VatCategory;
use App\Services\MyData\MyDataLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression for the pre-seed-then-import duplication bug: the Firebird ETL
 * pre-stamps a pre-seeded lookup row's legacy_id (by natural key) so a legacy
 * row with the same description/name/rate ADOPTS the seeded row instead of
 * creating a duplicate. Tested through the static helper the ETL delegates to,
 * so no Firebird source is needed.
 */
class SeededLookupAdoptionTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'T', 'slug' => 't-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    public function test_legacy_row_adopts_a_pre_seeded_row_instead_of_duplicating(): void
    {
        // Fresh install seeded the lookups (legacy_id NULL).
        app(MyDataLookupSeeder::class)->seedPaymentMethods($this->tenant);
        $before = PaymentMethod::where('company_id', $this->tenant->id)->where('description', 'Μετρητά')->count();
        $this->assertSame(1, $before);

        // Import brings legacy "Μετρητά" with legacy_id = 3 → adopt the seeded row.
        MigrateFromFirebird::adoptSeededLookupRow('payment_methods', $this->tenant->id, 'description', 'Μετρητά', 3);

        // Now the seeded row carries legacy_id 3, so the upsert-by-legacy_id
        // (simulated here) UPDATES it — exactly ONE Μετρητά row.
        DB::table('payment_methods')->updateOrInsert(
            ['company_id' => $this->tenant->id, 'legacy_id' => 3],
            ['description' => 'Μετρητά', 'updated_at' => now()],
        );

        $rows = PaymentMethod::withoutGlobalScopes()
            ->where('company_id', $this->tenant->id)->where('description', 'Μετρητά')->get();
        $this->assertCount(1, $rows, 'seeded + imported Μετρητά must converge on one row');
        $this->assertSame(3, (int) $rows->first()->legacy_id);
    }

    public function test_adoption_is_rerun_safe(): void
    {
        app(MyDataLookupSeeder::class)->seedPaymentMethods($this->tenant);

        // First import adopts.
        MigrateFromFirebird::adoptSeededLookupRow('payment_methods', $this->tenant->id, 'description', 'Μετρητά', 3);
        DB::table('payment_methods')->updateOrInsert(
            ['company_id' => $this->tenant->id, 'legacy_id' => 3],
            ['description' => 'Μετρητά', 'updated_at' => now()],
        );
        // Second run: legacy_id 3 already imported → no-op, no second row.
        MigrateFromFirebird::adoptSeededLookupRow('payment_methods', $this->tenant->id, 'description', 'Μετρητά', 3);

        $this->assertSame(1, PaymentMethod::withoutGlobalScopes()
            ->where('company_id', $this->tenant->id)->where('description', 'Μετρητά')->count());
    }

    public function test_vat_category_adopts_by_rate_despite_int_vs_decimal(): void
    {
        // Seeded 24% is stored as decimal 24.00; a legacy VALUE of 24 (int) must
        // still match by rate so the seeded 24% is adopted, not duplicated.
        app(MyDataLookupSeeder::class)->seedVatCategories($this->tenant);

        MigrateFromFirebird::adoptSeededLookupRow('vat_categories', $this->tenant->id, 'rate', 24, 7);
        DB::table('vat_categories')->updateOrInsert(
            ['company_id' => $this->tenant->id, 'legacy_id' => 7],
            ['rate' => 24, 'description' => '24%', 'updated_at' => now()],
        );

        $this->assertSame(1, VatCategory::withoutGlobalScopes()
            ->where('company_id', $this->tenant->id)->where('rate', 24)->count(),
            'seeded + imported 24% must converge on one row');
    }

    public function test_a_genuinely_new_legacy_value_is_not_adopted(): void
    {
        app(MyDataLookupSeeder::class)->seedPaymentMethods($this->tenant);

        // Legacy value the seed doesn't have → no seeded row to adopt → the
        // helper is a no-op and the caller inserts it fresh.
        MigrateFromFirebird::adoptSeededLookupRow('payment_methods', $this->tenant->id, 'description', 'Παράδοση μετρητοίς στον οδηγό', 9);

        $this->assertNull(PaymentMethod::withoutGlobalScopes()
            ->where('company_id', $this->tenant->id)->where('legacy_id', 9)->first());
    }
}
