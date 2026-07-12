<?php

namespace Tests\Feature\OperatorHealth;

use App\Models\Company;
use App\Support\OperatorHealth\HealthKeys;
use App\Support\OperatorHealth\OperatorHealthReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * OPS-13: a per-tenant scheduled sweep (myDATA reconcile / WHMCS fetch) that
 * silently stops recording leaves a cached «ok» that reads as healthy. The
 * report now flags such a tenant «stale» — but only while the task is ENABLED
 * (an opt-out task's silent key is expected), and only once its last run is
 * older than the stale window. A never-recorded key stays «missing».
 */
class TenantSweepStalenessTest extends TestCase
{
    use RefreshDatabase;

    private function greekTenant(): Company
    {
        return Company::create([
            'name' => 'GR AE', 'slug' => 'gr-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
        ]);
    }

    private function reconcileRow(Company $tenant): ?array
    {
        return collect(app(OperatorHealthReport::class)->build()['mydata'])
            ->firstWhere('tenant', $tenant->slug);
    }

    #[Test]
    public function an_old_recording_is_flagged_stale_when_the_task_is_enabled(): void
    {
        config()->set('ekdosi.schedule.mydata_reconcile_enabled', true);
        $tenant = $this->greekTenant();

        Cache::forever(HealthKeys::myDataReconcile((int) $tenant->id), [
            'company_id' => $tenant->id, 'tenant' => $tenant->slug,
            'status' => 'ok', 'checked_at' => now()->subDays(3)->toIso8601String(),
        ]);

        $row = $this->reconcileRow($tenant);
        $this->assertNotNull($row);
        $this->assertTrue($row['stale']);
    }

    #[Test]
    public function a_recent_recording_is_not_stale(): void
    {
        config()->set('ekdosi.schedule.mydata_reconcile_enabled', true);
        $tenant = $this->greekTenant();

        Cache::forever(HealthKeys::myDataReconcile((int) $tenant->id), [
            'company_id' => $tenant->id, 'tenant' => $tenant->slug,
            'status' => 'ok', 'checked_at' => now()->subHours(2)->toIso8601String(),
        ]);

        $this->assertFalse($this->reconcileRow($tenant)['stale']);
    }

    #[Test]
    public function an_old_recording_is_not_stale_when_the_task_is_disabled(): void
    {
        // Opt-out task → no runs expected → an old key is NOT a problem.
        config()->set('ekdosi.schedule.mydata_reconcile_enabled', false);
        $tenant = $this->greekTenant();

        Cache::forever(HealthKeys::myDataReconcile((int) $tenant->id), [
            'company_id' => $tenant->id, 'tenant' => $tenant->slug,
            'status' => 'ok', 'checked_at' => now()->subDays(3)->toIso8601String(),
        ]);

        $this->assertFalse($this->reconcileRow($tenant)['stale']);
    }

    #[Test]
    public function a_never_recorded_tenant_is_missing_not_stale(): void
    {
        config()->set('ekdosi.schedule.mydata_reconcile_enabled', true);
        $tenant = $this->greekTenant();

        $row = $this->reconcileRow($tenant);
        $this->assertSame('missing', $row['status']);
        $this->assertFalse($row['stale']);
    }
}
