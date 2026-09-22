<?php

namespace Tests\Feature\Customers;

use App\DTOs\AadeRegistryRecord;
use App\Exceptions\Aade\AadeAfmNotFound;
use App\Exceptions\Aade\AadeUnreachable;
use App\Models\Company;
use App\Models\Customer;
use App\Services\AadeRegistryLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * #8: customers:refresh-aade-status re-checks the AADE/GSIS registry status of
 * customers' ΑΦΜ and persists it (Customer::aade_*). GSIS is mocked.
 */
class RefreshCustomerAadeStatusTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(array $over = []): Company
    {
        $t = Company::create(array_merge([
            'name' => 'T', 'slug' => 'aq-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ], $over));
        $t->forceFill(['gsis_username' => 'u', 'gsis_password' => 'p', 'aade_status_auto_refresh' => true])->save();

        return $t->fresh();
    }

    private function record(string $afm, bool $active, string $descr): AadeRegistryRecord
    {
        return new AadeRegistryRecord(
            afm: $afm, name: 'X', doy: 'Δ', doyCode: '1', active: $active,
            statusDescr: $descr, address: '', city: '', postcode: '', activities: [],
        );
    }

    /** @param array<string, AadeRegistryRecord> $map @param list<string> $notFound */
    private function mockGsis(array $map, array $notFound = []): void
    {
        $mock = Mockery::mock(AadeRegistryLookup::class);
        $mock->shouldReceive('findByAfm')->andReturnUsing(function (string $afm) use ($map, $notFound) {
            if (in_array($afm, $notFound, true)) {
                throw new AadeAfmNotFound('not found');
            }
            if (isset($map[$afm])) {
                return $map[$afm];
            }
            throw new AadeAfmNotFound('not found');
        });
        $this->app->bind(AadeRegistryLookup::class, fn () => $mock);
    }

    public function test_persists_active_and_inactive_status(): void
    {
        $t = $this->tenant();
        $c1 = Customer::create(['company_id' => $t->id, 'name' => 'A', 'afm' => '090000045']);
        $c2 = Customer::create(['company_id' => $t->id, 'name' => 'B', 'afm' => '094277965']);
        $this->mockGsis([
            '090000045' => $this->record('090000045', true, 'ΕΝΕΡΓΟΣ ΑΦΜ'),
            '094277965' => $this->record('094277965', false, 'ΑΝΕΝΕΡΓΟΣ ΑΦΜ'),
        ]);

        $this->artisan('customers:refresh-aade-status --throttle-ms=0')->assertExitCode(0);

        $c1->refresh();
        $c2->refresh();
        $this->assertTrue($c1->aade_active);
        $this->assertNotNull($c1->aade_status_checked_at);
        $this->assertSame('ΕΝΕΡΓΟΣ ΑΦΜ', $c1->aade_status_descr);
        $this->assertFalse($c2->aade_active);
        $this->assertSame('ΑΝΕΝΕΡΓΟΣ ΑΦΜ', $c2->aade_status_descr);
    }

    public function test_afm_not_found_marks_inactive(): void
    {
        $t = $this->tenant();
        $c = Customer::create(['company_id' => $t->id, 'name' => 'Closed', 'afm' => '090000045']);
        $this->mockGsis([], notFound: ['090000045']);

        $this->artisan('customers:refresh-aade-status --throttle-ms=0')->assertExitCode(0);

        $c->refresh();
        $this->assertFalse($c->aade_active);
        $this->assertNotNull($c->aade_status_checked_at);
    }

    public function test_skips_foreign_vat_and_placeholder_afm(): void
    {
        $t = $this->tenant();
        $foreign = Customer::create(['company_id' => $t->id, 'name' => 'Foreign', 'afm' => 'DE811128135']);
        $none = Customer::create(['company_id' => $t->id, 'name' => 'NoAfm', 'afm' => null]);
        // A mock that would throw if ever called with these — but it must NOT be called.
        $this->mockGsis(['090000045' => $this->record('090000045', true, 'ΕΝΕΡΓΟΣ')]);

        $this->artisan('customers:refresh-aade-status --throttle-ms=0')->assertExitCode(0);

        $foreign->refresh();
        $none->refresh();
        $this->assertNull($foreign->aade_status_checked_at);   // foreign VAT → GSIS can't answer
        $this->assertNull($none->aade_status_checked_at);      // no ΑΦΜ at all
    }

    public function test_respects_stale_days_and_force(): void
    {
        $t = $this->tenant();
        // Checked yesterday, currently marked active.
        $fresh = Customer::create(['company_id' => $t->id, 'name' => 'Fresh', 'afm' => '090000045']);
        $fresh->recordAadeStatus(true, 'ΕΝΕΡΓΟΣ');
        // The mock would flip it to inactive IF re-checked.
        $this->mockGsis(['090000045' => $this->record('090000045', false, 'ΑΝΕΝΕΡΓΟΣ')]);

        // Default stale-days=90 → a just-checked customer is skipped (stays active).
        $this->artisan('customers:refresh-aade-status --throttle-ms=0')->assertExitCode(0);
        $fresh->refresh();
        $this->assertTrue($fresh->aade_active, 'recently-checked row is not re-checked');

        // --force re-checks it → now inactive.
        $this->artisan('customers:refresh-aade-status --force --throttle-ms=0')->assertExitCode(0);
        $fresh->refresh();
        $this->assertFalse($fresh->aade_active, '--force re-checks even fresh rows');
    }

    public function test_stops_the_tenant_on_gsis_unreachable_or_quota(): void
    {
        // GSIS unreachable / daily-quota-exhausted must ABORT the tenant, not keep
        // hammering an already-blocked account. Two customers, but findByAfm is
        // expected exactly ONCE (the run breaks after the first fault).
        $t = $this->tenant();
        Customer::create(['company_id' => $t->id, 'name' => 'A', 'afm' => '090000045']);
        Customer::create(['company_id' => $t->id, 'name' => 'B', 'afm' => '094277965']);

        $mock = Mockery::mock(AadeRegistryLookup::class);
        $mock->shouldReceive('findByAfm')->once()->andThrow(new AadeUnreachable('quota exceeded'));
        $this->app->bind(AadeRegistryLookup::class, fn () => $mock);

        // exit 7 = at least one error; Mockery ->once() verifies we stopped after the first.
        $this->artisan('customers:refresh-aade-status --throttle-ms=0')->assertExitCode(7);
    }

    public function test_unknown_tenant_slug_exits_6(): void
    {
        $this->artisan('customers:refresh-aade-status --tenant=nope --throttle-ms=0')->assertExitCode(6);
    }

    public function test_sweep_only_touches_opted_in_tenants_but_manual_runs_regardless(): void
    {
        $t = $this->tenant();
        $t->forceFill(['aade_status_auto_refresh' => false])->save();   // opted OUT
        $c = Customer::create(['company_id' => $t->id, 'name' => 'A', 'afm' => '090000045']);
        $this->mockGsis(['090000045' => $this->record('090000045', true, 'ΕΝΕΡΓΟΣ')]);

        // Scheduled sweep (no --tenant): the opted-out tenant is skipped.
        $this->artisan('customers:refresh-aade-status --throttle-ms=0')->assertExitCode(0);
        $c->refresh();
        $this->assertNull($c->aade_status_checked_at, 'opted-out tenant is not swept');

        // Manual --tenant: runs regardless of the opt-in toggle.
        $this->artisan('customers:refresh-aade-status --tenant='.$t->slug.' --throttle-ms=0')->assertExitCode(0);
        $c->refresh();
        $this->assertTrue($c->aade_active, 'explicit --tenant runs even when opt-in is off');
    }
}
