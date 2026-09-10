<?php

namespace Tests\Feature\Etl;

use App\Console\Commands\MigrateFromFirebird;
use App\Models\Company;
use App\Models\Customer;
use App\Services\Etl\LegacyAfmConflicts;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * The ΑΦΜ guard of the Firebird ETL — «τι γίνεται αν η legacy βάση έχει ήδη δύο
 * πελάτες με το ίδιο ΑΦΜ;».
 *
 * The legacy application had no branch field, so an υποκατάστημα was a SECOND
 * customer row with the same ΑΦΜ. The target enforces UNIQUE(company_id, afm_key),
 * so the run stops — unless the operator names the keeper with `--afm-keep`, which
 * resolves it WITHOUT touching the legacy database (it stays a read-only archive).
 */
class LegacyAfmConflictsTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'T', 'slug' => 'lac-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'afm' => '800561849',
        ]);
    }

    /** @return list<array{id:int, afm:?string, name:?string}> */
    private function rows(array ...$rows): array
    {
        return array_map(fn (array $r): array => [
            'id' => $r[0], 'afm' => $r[1] ?? null, 'name' => $r[2] ?? null,
        ], $rows);
    }

    private function service(): LegacyAfmConflicts
    {
        return app(LegacyAfmConflicts::class);
    }

    public function test_two_legacy_customers_with_one_afm_are_a_blocker(): void
    {
        $report = $this->service()->find($this->rows(
            [41, '123456789', 'ΕΤΑΙΡΕΙΑ ΑΕ'],
            [87, 'EL 123-456-789', 'ΕΤΑΙΡΕΙΑ ΑΕ ΥΠΟΚ'],
            [90, '094123456', 'ΑΛΛΟΣ'],
        ));

        $this->assertTrue($report->hasBlockers());
        $this->assertCount(1, $report->groups);
        $this->assertSame('123456789', $report->groups[0]['key']);
        $this->assertSame([41, 87], array_column($report->groups[0]['entries'], 'id'));
        $this->assertSame([], $report->parked());
        // Both options are offered by number — the ETL never picks a winner itself.
        $this->assertStringContainsString('--afm-keep=41', $report->describe());
        $this->assertStringContainsString('--afm-keep=87', $report->describe());
    }

    public function test_placeholders_and_free_text_never_collide(): void
    {
        $report = $this->service()->find($this->rows(
            [1, '000000000', 'ΛΙΑΝΙΚΗ Α'],
            [2, '000000000', 'ΛΙΑΝΙΚΗ Β'],
            [3, 'ΔΕΝ ΕΧΕΙ', 'ΛΙΑΝΙΚΗ Γ'],
            [4, '', 'ΛΙΑΝΙΚΗ Δ'],
            [5, null, 'ΛΙΑΝΙΚΗ Ε'],
        ));

        $this->assertFalse($report->hasBlockers());
        $this->assertTrue($report->isEmpty());
    }

    public function test_afm_keep_resolves_the_group_and_parks_the_twin(): void
    {
        $report = $this->service()->find($this->rows(
            [41, '123456789', 'ΕΤΑΙΡΕΙΑ ΑΕ'],
            [87, '123456789', 'ΕΤΑΙΡΕΙΑ ΑΕ ΥΠΟΚ'],
        ), null, [41]);

        $this->assertFalse($report->hasBlockers());
        $this->assertSame([87], array_keys($report->parked()));
        $this->assertSame(['key' => '123456789', 'keeper' => 41, 'name' => 'ΕΤΑΙΡΕΙΑ ΑΕ ΥΠΟΚ'], $report->parked()[87]);
        $this->assertStringContainsString('✓ κρατά το 41', $report->describe());
    }

    public function test_two_keepers_for_one_afm_is_refused_not_silently_resolved(): void
    {
        $report = $this->service()->find($this->rows(
            [41, '123456789', 'A'],
            [87, '123456789', 'B'],
        ), null, [41, 87]);

        $this->assertTrue($report->hasBlockers());
        $this->assertSame([], $report->parked());
        $this->assertStringContainsString('δύο keepers', $report->describe());
    }

    public function test_a_keeper_that_matches_no_group_is_reported_but_not_fatal(): void
    {
        $report = $this->service()->find($this->rows([1, '123456789', 'A']), null, [999]);

        $this->assertFalse($report->hasBlockers());
        $this->assertSame([999], $report->unusedKeepers);
        $this->assertStringContainsString('--afm-keep=999', $report->describe());
    }

    public function test_a_local_customer_holding_the_afm_blocks_when_this_run_would_not_release_it(): void
    {
        $company = $this->tenant();
        // Made in the panel — no legacy_id, so no upsert of this run rewrites it.
        Customer::create(['company_id' => $company->id, 'name' => 'ΧΕΙΡΟΚΙΝΗΤΟΣ', 'afm' => '123456789']);

        $report = $this->service()->find($this->rows([55, '123456789', 'ΠΗΓΗ']), $company->id);

        $this->assertTrue($report->hasBlockers());
        $this->assertCount(1, $report->localOwners);
        $this->assertSame(55, $report->localOwners[0]['claimant']);
        $this->assertStringContainsString('φτιάχτηκε στο panel', $report->describe());
        $this->assertStringContainsString('customers:afm-duplicates', $report->howTo());
    }

    public function test_a_local_row_this_run_rewrites_is_not_a_conflict(): void
    {
        $company = $this->tenant();
        $c = Customer::create(['company_id' => $company->id, 'legacy_id' => 55, 'name' => 'ΠΗΓΗ', 'afm' => '123456789']);
        $this->assertSame('123456789', $c->fresh()->afm_key);

        // The ΑΦΜ moved to another CUST_ID in the source: legal, because copyCustomers
        // releases the key of every row it rewrites before the upserts.
        $report = $this->service()->find($this->rows(
            [55, '094123456', 'ΠΗΓΗ'],
            [56, '123456789', 'ΝΕΟΣ ΚΑΤΟΧΟΣ'],
        ), $company->id);

        $this->assertFalse($report->hasBlockers());
    }

    public function test_a_soft_deleted_local_owner_still_blocks(): void
    {
        $company = $this->tenant();
        $c = Customer::create(['company_id' => $company->id, 'name' => 'ΔΙΑΓΡΑΜΜΕΝΟΣ', 'afm' => '123456789']);
        $c->delete();

        $report = $this->service()->find($this->rows([55, '123456789', 'ΠΗΓΗ']), $company->id);

        $this->assertTrue($report->hasBlockers());
        $this->assertStringContainsString('[ΔΙΑΓΡΑΜΜΕΝΟΣ]', $report->describe());
    }

    public function test_without_a_tenant_only_the_legacy_side_is_checked(): void
    {
        $company = $this->tenant();
        Customer::create(['company_id' => $company->id, 'name' => 'ΧΕΙΡΟΚΙΝΗΤΟΣ', 'afm' => '123456789']);

        $report = $this->service()->find($this->rows([55, '123456789', 'ΠΗΓΗ']));

        $this->assertFalse($report->tenantChecked);
        $this->assertSame([], $report->localOwners);
        $this->assertFalse($report->hasBlockers());
        // …and it says so, instead of a clean bill of health it never established.
        $this->assertStringContainsString('δεν ελέγχθηκε η πλευρά του ekdosi', $report->summary());
    }

    public function test_map_legacy_rows_uses_the_callers_charset_cleaner(): void
    {
        $mapped = $this->service()->mapLegacyRows(
            [['CUST_ID' => '7', 'AFM' => ' 123456789 ', 'NAME' => 'X']],
            fn (array $r, string $k): ?string => trim((string) ($r[$k] ?? '')) ?: null,
        );

        $this->assertSame([['id' => 7, 'afm' => '123456789', 'name' => 'X']], $mapped);
    }

    // ------------------------------------------------------------------ the ETL wiring

    /**
     * Drive the command's private guard directly (no pdo_firebird needed — it
     * takes plain rows): the option plumbing, the refusal message and the parked
     * set it hands back to copyCustomers.
     */
    private function guard(array $legacyRows, array $afmKeep, int $companyId, ?BufferedOutput $buffer = null): array
    {
        $command = new MigrateFromFirebird;
        $command->setLaravel($this->app);

        $ref = new ReflectionClass($command);
        foreach ([
            'input' => new ArrayInput($afmKeep === [] ? [] : ['--afm-keep' => array_map('strval', $afmKeep)], $command->getDefinition()),
            'output' => new OutputStyle(new ArrayInput([]), $buffer ?? new BufferedOutput),
            'companyId' => $companyId,
        ] as $property => $value) {
            $ref->getProperty($property)->setValue($command, $value);
        }

        return $ref->getMethod('assertNoDuplicateLegacyAfm')->invoke($command, $legacyRows);
    }

    public function test_the_etl_guard_refuses_a_duplicate_afm_in_the_source(): void
    {
        $company = $this->tenant();

        try {
            $this->guard([
                ['CUST_ID' => 41, 'AFM' => '123456789', 'NAME' => 'ΕΤΑΙΡΕΙΑ ΑΕ'],
                ['CUST_ID' => 87, 'AFM' => '123456789', 'NAME' => 'ΥΠΟΚΑΤΑΣΤΗΜΑ'],
            ], [], (int) $company->id);
            $this->fail('Expected the guard to refuse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Σύγκρουση ΑΦΜ', $e->getMessage());
            $this->assertStringContainsString('τίποτα δεν γράφτηκε', $e->getMessage());
            $this->assertStringContainsString('--afm-keep=41', $e->getMessage());
        }

        $this->assertSame(0, Customer::withTrashed()->count(), 'the guard must run before any write');
    }

    public function test_the_etl_guard_passes_with_afm_keep_and_warns_about_the_parked_twin(): void
    {
        $company = $this->tenant();
        $buffer = new BufferedOutput;

        $parked = $this->guard([
            ['CUST_ID' => 41, 'AFM' => '123456789', 'NAME' => 'ΕΤΑΙΡΕΙΑ ΑΕ'],
            ['CUST_ID' => 87, 'AFM' => '123456789', 'NAME' => 'ΥΠΟΚΑΤΑΣΤΗΜΑ'],
        ], [41], (int) $company->id, $buffer);

        $this->assertSame([87], array_keys($parked));
        $this->assertStringContainsString('ΧΩΡΙΣ ταυτότητα ΑΦΜ', $buffer->fetch());
    }

    public function test_the_etl_guard_is_quiet_when_there_is_nothing_to_say(): void
    {
        $company = $this->tenant();
        $buffer = new BufferedOutput;

        $parked = $this->guard([
            ['CUST_ID' => 41, 'AFM' => '123456789', 'NAME' => 'A'],
            ['CUST_ID' => 87, 'AFM' => '094123456', 'NAME' => 'B'],
        ], [], (int) $company->id, $buffer);

        $this->assertSame([], $parked);
        $this->assertSame('', trim($buffer->fetch()));
    }
}
