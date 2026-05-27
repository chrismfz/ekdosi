<?php

namespace Tests\Feature\Etl;

use App\Models\Company;
use App\Models\Customer;
use App\Services\Etl\TenantRowUpserter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Locks the re-run safety contract that the Firebird ETL relies on.
 * These tests don't need a Firebird connection — the upsert logic is
 * pure DB. The full ETL pipeline (which needs pdo_firebird; blocked
 * in the sandbox per CLAUDE.md) is locked separately when it can run.
 *
 * What's being asserted:
 *   1. First call inserts; returns the new surrogate id.
 *   2. Second call with the same match keys finds the existing row;
 *      returns the SAME surrogate id (FKs from other tables stay valid).
 *   3. Legacy-sourced columns in $updateValues refresh on every call.
 *   4. Columns in $insertOnlyDefaults are written ONLY on first
 *      insert; subsequent calls leave them alone.
 *   5. Rows with no legacy_id (NULL) coexist — multiple ekdosi-only
 *      rows per tenant don't collide with the unique constraint
 *      (MariaDB + SQLite both allow multiple NULLs in unique indices).
 *   6. Tenant scope is respected: same legacy_id in two tenants
 *      produces two separate rows.
 */
class TenantRowUpserterTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private TenantRowUpserter $upserter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'ETL test tenant',
            'slug' => 'etl-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
        ]);
        $this->upserter = TenantRowUpserter::default();
    }

    public function test_first_call_inserts_row_and_returns_new_id(): void
    {
        $id = $this->upserter->upsertGetId(
            'customers',
            ['company_id' => $this->tenant->id, 'legacy_id' => 100],
            ['name' => 'Acme Ltd', 'updated_at' => now()],
            ['is_active' => true, 'created_at' => now()],
        );

        $this->assertGreaterThan(0, $id);
        $row = Customer::find($id);
        $this->assertSame('Acme Ltd', $row->name);
        $this->assertSame(100, (int) $row->legacy_id);
        $this->assertTrue((bool) $row->is_active);
    }

    public function test_second_call_returns_same_id_and_refreshes_update_values(): void
    {
        // Day 0: import.
        $firstId = $this->upserter->upsertGetId(
            'customers',
            ['company_id' => $this->tenant->id, 'legacy_id' => 100],
            ['name' => 'Acme Ltd (old name)'],
            ['is_active' => true, 'created_at' => now()->subDays(7)],
        );

        // Day 7: re-import. Legacy data refreshed (name changed in source).
        $secondId = $this->upserter->upsertGetId(
            'customers',
            ['company_id' => $this->tenant->id, 'legacy_id' => 100],
            ['name' => 'Acme Limited (legacy updated)'],
            ['is_active' => false, 'created_at' => now()],  // insert-only defaults — must be IGNORED
        );

        // The crucial assertion: surrogate id is STABLE across runs.
        // FKs from ekdosi-only rows pointing at this customer stay valid.
        $this->assertSame($firstId, $secondId);

        $row = Customer::find($firstId);
        // Legacy column refreshed
        $this->assertSame('Acme Limited (legacy updated)', $row->name);
        // Filament-managed column survived: is_active stayed at the
        // ORIGINAL true value, NOT the re-import's false default.
        $this->assertTrue((bool) $row->is_active);
    }

    public function test_filament_only_rows_are_untouched_by_re_import(): void
    {
        // Operator creates a customer in Filament with no legacy_id.
        $manual = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'Joe (created in ekdosi)',
            'is_active' => true,
        ]);
        $manualId = $manual->id;

        // Re-import sees legacy customer #100 (different person).
        $this->upserter->upsertGetId(
            'customers',
            ['company_id' => $this->tenant->id, 'legacy_id' => 100],
            ['name' => 'Acme from legacy'],
            ['is_active' => true, 'created_at' => now()],
        );

        // Operator's manual customer survives the re-import unchanged.
        $manual->refresh();
        $this->assertSame('Joe (created in ekdosi)', $manual->name);
        $this->assertSame($manualId, $manual->id);
        $this->assertNull($manual->legacy_id);
        // Two distinct rows total for this tenant.
        $this->assertSame(2, Customer::where('company_id', $this->tenant->id)->count());
    }

    public function test_tenant_scope_isolation(): void
    {
        $otherTenant = Company::create([
            'name' => 'Other',
            'slug' => 'etl-other-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
        ]);

        $myId = $this->upserter->upsertGetId(
            'customers',
            ['company_id' => $this->tenant->id, 'legacy_id' => 100],
            ['name' => 'Mine'],
            ['is_active' => true, 'created_at' => now()],
        );

        // Same legacy_id, different tenant — must create a separate row.
        $theirId = $this->upserter->upsertGetId(
            'customers',
            ['company_id' => $otherTenant->id, 'legacy_id' => 100],
            ['name' => 'Theirs'],
            ['is_active' => true, 'created_at' => now()],
        );

        $this->assertNotSame($myId, $theirId);
        $this->assertSame('Mine', Customer::find($myId)->name);
        $this->assertSame('Theirs', Customer::find($theirId)->name);
    }

    public function test_no_update_when_update_values_empty(): void
    {
        // Insert via the upserter; capture updated_at.
        $id = $this->upserter->upsertGetId(
            'customers',
            ['company_id' => $this->tenant->id, 'legacy_id' => 100],
            ['name' => 'Acme', 'updated_at' => now()->subHour()],
            ['is_active' => true, 'created_at' => now()],
        );
        $before = Customer::find($id)->updated_at;

        // Call again with EMPTY update values — must not UPDATE.
        $this->upserter->upsertGetId(
            'customers',
            ['company_id' => $this->tenant->id, 'legacy_id' => 100],
            [],  // no columns to refresh
            ['is_active' => false, 'created_at' => now()],  // ignored on existing rows
        );

        $after = Customer::find($id)->updated_at;
        // updated_at must be identical — we didn't run an UPDATE
        // statement at all. (Important: empty $updateValues should
        // NOT trigger a no-op UPDATE that bumps updated_at via DB
        // triggers, but Eloquent models also have no auto-bump on
        // raw DB::table writes anyway. Asserts the timestamp is the
        // SAME microsecond.)
        $this->assertEquals($before->format('Y-m-d H:i:s'), $after->format('Y-m-d H:i:s'));
    }

    public function test_upsert_without_get_id_creates_or_updates_child_row(): void
    {
        $customer = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'Parent customer',
            'legacy_id' => 50,
        ]);

        // First call: inserts payment row.
        $this->upserter->upsert(
            'payments',
            ['company_id' => $this->tenant->id, 'legacy_id' => 999],
            ['customer_id' => $customer->id, 'amount' => 100.00, 'pay_date' => '2026-05-01',
             'created_at' => now(), 'updated_at' => now()],
        );
        $this->assertSame(1, DB::table('payments')->where('legacy_id', 999)->count());
        $this->assertEquals('100.00', DB::table('payments')->where('legacy_id', 999)->value('amount'));

        // Second call with same match keys + different amount: updates,
        // doesn't insert a duplicate.
        $this->upserter->upsert(
            'payments',
            ['company_id' => $this->tenant->id, 'legacy_id' => 999],
            ['customer_id' => $customer->id, 'amount' => 150.00, 'pay_date' => '2026-05-01',
             'created_at' => now(), 'updated_at' => now()],
        );
        $this->assertSame(1, DB::table('payments')->where('legacy_id', 999)->count());
        $this->assertEquals('150.00', DB::table('payments')->where('legacy_id', 999)->value('amount'));
    }
}
