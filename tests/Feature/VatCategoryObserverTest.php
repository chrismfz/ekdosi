<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\VatCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lock in the single-default-VAT invariant the VatCategoryObserver
 * exists to enforce — the steady-state replacement for the legacy
 * VAT_CATEGORY_AU0 Firebird AFTER UPDATE trigger.
 */
class VatCategoryObserverTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenantA;

    private Company $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Company::create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
        ]);

        $this->tenantB = Company::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
        ]);
    }

    public function test_promoting_one_demotes_others_in_the_same_tenant(): void
    {
        $vat24 = VatCategory::create([
            'company_id' => $this->tenantA->id,
            'description' => '24% standard',
            'rate' => 24,
            'is_default' => true,
        ]);

        $vat13 = VatCategory::create([
            'company_id' => $this->tenantA->id,
            'description' => '13% reduced',
            'rate' => 13,
            'is_default' => false,
        ]);

        $vat13->update(['is_default' => true]);

        $this->assertTrue($vat13->fresh()->is_default);
        $this->assertFalse($vat24->fresh()->is_default, 'The previously-default row should have been demoted.');
    }

    public function test_creating_with_is_default_true_demotes_existing(): void
    {
        $existing = VatCategory::create([
            'company_id' => $this->tenantA->id,
            'description' => '24% standard',
            'rate' => 24,
            'is_default' => true,
        ]);

        VatCategory::create([
            'company_id' => $this->tenantA->id,
            'description' => '13% reduced',
            'rate' => 13,
            'is_default' => true,
        ]);

        $this->assertFalse($existing->fresh()->is_default);
        $this->assertSame(
            1,
            VatCategory::where('company_id', $this->tenantA->id)
                ->where('is_default', true)
                ->count(),
        );
    }

    public function test_observer_is_scoped_per_tenant(): void
    {
        $aDefault = VatCategory::create([
            'company_id' => $this->tenantA->id,
            'description' => 'A 24%',
            'rate' => 24,
            'is_default' => true,
        ]);

        $bDefault = VatCategory::create([
            'company_id' => $this->tenantB->id,
            'description' => 'B 24%',
            'rate' => 24,
            'is_default' => true,
        ]);

        $this->assertTrue($aDefault->fresh()->is_default);
        $this->assertTrue(
            $bDefault->fresh()->is_default,
            'Promoting a default in tenant B must not demote tenant A.',
        );
    }

    public function test_saving_a_non_default_does_not_touch_others(): void
    {
        $a = VatCategory::create([
            'company_id' => $this->tenantA->id,
            'description' => 'A 24%',
            'rate' => 24,
            'is_default' => true,
        ]);

        VatCategory::create([
            'company_id' => $this->tenantA->id,
            'description' => 'A 13%',
            'rate' => 13,
            'is_default' => false,
        ]);

        $this->assertTrue($a->fresh()->is_default);
    }

    public function test_no_op_save_of_default_row_does_not_issue_demote_query(): void
    {
        // Two siblings, one is the current default.
        $default = VatCategory::create([
            'company_id' => $this->tenantA->id,
            'description' => 'A 24%',
            'rate' => 24,
            'is_default' => true,
        ]);

        $other = VatCategory::create([
            'company_id' => $this->tenantA->id,
            'description' => 'A 13%',
            'rate' => 13,
            'is_default' => false,
        ]);

        // Forcibly flip $other to default through a raw query so it
        // bypasses the observer — we're testing that re-saving $default
        // with no is_default change does NOT re-demote $other.
        VatCategory::query()
            ->whereKey($other->id)
            ->update(['is_default' => true]);

        $default->update(['description' => 'A 24% (renamed)']);

        $this->assertTrue(
            $other->fresh()->is_default,
            'Saving the default row with no is_default change should leave sibling rows untouched.',
        );
    }
}
