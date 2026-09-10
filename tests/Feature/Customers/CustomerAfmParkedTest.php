<?php

namespace Tests\Feature\Customers;

use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Services\Customers\CustomerAfmDuplicates;
use App\Services\Customers\MergeCustomers;
use Filament\Facades\Filament;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Parked ΑΦΜ rows — the legacy υποκατάστημα twin the Firebird ETL imports with
 * `--afm-keep` (it keeps its ΑΦΜ text, documents and history, but not the
 * identity behind UNIQUE(company_id, afm_key)).
 *
 * What must hold: the operator can still EDIT such a row; it reclaims its
 * identity by itself once the ΑΦΜ stops colliding; the audit surfaces it so a
 * deliberate park never becomes forgotten state; and nothing an operator can type
 * in the panel turns parking into a way around the ΑΦΜ hardening.
 */
class CustomerAfmParkedTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'T', 'slug' => 'park-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'afm' => '800561849',
        ]);
    }

    /** Exactly what the ETL writes for a parked twin (query builder → no model hook). */
    private function parkedTwin(Company $company, string $afm = '123456789', int $legacyId = 87): Customer
    {
        $id = DB::table('customers')->insertGetId([
            'company_id' => $company->id,
            'legacy_id' => $legacyId,
            'name' => 'ΕΤΑΙΡΕΙΑ ΑΕ — ΥΠΟΚΑΤΑΣΤΗΜΑ',
            'afm' => $afm,
            'afm_key' => null,
            'afm_key_parked' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Customer::findOrFail($id);
    }

    public function test_a_parked_row_can_be_edited_without_hitting_the_unique_index(): void
    {
        $company = $this->tenant();
        $holder = Customer::create(['company_id' => $company->id, 'legacy_id' => 41, 'name' => 'ΕΤΑΙΡΕΙΑ ΑΕ', 'afm' => '123456789']);
        $twin = $this->parkedTwin($company);

        // The plain edit that would dead-end without the flag: the saving() hook
        // re-derives afm_key from afm on EVERY save.
        $twin->update(['phone1' => '2101234567']);

        $this->assertSame('2101234567', $twin->fresh()->phone1);
        $this->assertNull($twin->fresh()->afm_key);
        $this->assertTrue($twin->fresh()->afm_key_parked);
        $this->assertSame('123456789', $holder->fresh()->afm_key);
        // The ΑΦΜ itself is untouched — it still prints on the customer's documents.
        $this->assertSame('123456789', $twin->fresh()->afm);
    }

    public function test_the_edit_form_lets_a_parked_row_keep_its_own_afm(): void
    {
        $company = $this->tenant();
        $user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.l', 'password' => bcrypt('x')]);
        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($company);

        Customer::create(['company_id' => $company->id, 'name' => 'ΕΤΑΙΡΕΙΑ ΑΕ', 'afm' => '123456789']);
        $twin = $this->parkedTwin($company);

        // The whole point of the flag: the ΑΦΜ uniqueness rule must not refuse a
        // parked row its OWN ΑΦΜ back, or the row can never be saved from the panel.
        Livewire::test(EditCustomer::class, ['record' => $twin->getKey()])
            ->fillForm(['phone1' => '2101234567'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('2101234567', $twin->fresh()->phone1);
        $this->assertNull($twin->fresh()->afm_key);

        // …but a parked row is no licence to take a THIRD customer's ΑΦΜ.
        Customer::create(['company_id' => $company->id, 'name' => 'ΤΡΙΤΟΣ', 'afm' => '094123456']);
        Livewire::test(EditCustomer::class, ['record' => $twin->getKey()])
            ->fillForm(['afm' => 'EL 094 123 456'])
            ->call('save')
            ->assertHasFormErrors(['afm']);
    }

    public function test_a_parked_row_reclaims_its_identity_once_the_afm_stops_colliding(): void
    {
        $company = $this->tenant();
        $holder = Customer::create(['company_id' => $company->id, 'name' => 'ΕΤΑΙΡΕΙΑ ΑΕ', 'afm' => '123456789']);
        $twin = $this->parkedTwin($company);

        // The operator resolved it the other way: the holder's ΑΦΜ was the wrong one.
        $holder->update(['afm' => '094123456']);
        $twin->touch();

        $this->assertSame('123456789', $twin->fresh()->afm_key);
        $this->assertFalse($twin->fresh()->afm_key_parked);
    }

    public function test_a_parked_row_stays_parked_while_a_soft_deleted_holder_still_owns_the_key(): void
    {
        $company = $this->tenant();
        $holder = Customer::create(['company_id' => $company->id, 'name' => 'ΕΤΑΙΡΕΙΑ ΑΕ', 'afm' => '123456789']);
        $twin = $this->parkedTwin($company);

        // The UNIQUE index covers soft-deleted rows, so a merged-away holder is
        // still the holder — un-parking here would blow up on the next save.
        $holder->delete();
        $twin->touch();

        $this->assertNull($twin->fresh()->afm_key);
        $this->assertTrue($twin->fresh()->afm_key_parked);
    }

    public function test_the_parked_flag_does_not_outlive_the_afm_that_caused_it(): void
    {
        $company = $this->tenant();
        Customer::create(['company_id' => $company->id, 'name' => 'ΕΤΑΙΡΕΙΑ ΑΕ', 'afm' => '123456789']);
        $twin = $this->parkedTwin($company);

        // The operator's other legitimate resolution: this row never had that ΑΦΜ.
        $twin->update(['afm' => '000000000']);

        $this->assertNull($twin->fresh()->afm_key);
        $this->assertFalse((bool) $twin->fresh()->afm_key_parked, 'a flag nothing can surface any more');
        $this->assertTrue(app(CustomerAfmDuplicates::class)->findParked((int) $company->id)->isEmpty());
    }

    public function test_parking_is_not_something_an_operator_can_ask_for(): void
    {
        $company = $this->tenant();
        Customer::create(['company_id' => $company->id, 'name' => 'ΕΤΑΙΡΕΙΑ ΑΕ', 'afm' => '123456789']);

        // `afm_key_parked` is not fillable: a duplicate typed in the panel is still
        // refused by the database, flag or no flag in the payload.
        $this->expectException(UniqueConstraintViolationException::class);
        Customer::create([
            'company_id' => $company->id,
            'name' => 'ΔΕΥΤΕΡΟΣ',
            'afm' => 'EL123456789',
            'afm_key_parked' => true,
        ]);
    }

    public function test_the_audit_reports_a_parked_row_with_its_holder(): void
    {
        $company = $this->tenant();
        $holder = Customer::create(['company_id' => $company->id, 'name' => 'ΕΤΑΙΡΕΙΑ ΑΕ', 'afm' => '123456789']);
        $twin = $this->parkedTwin($company);

        $groups = app(CustomerAfmDuplicates::class)->findParked((int) $company->id);

        $this->assertCount(1, $groups);
        $this->assertSame('123456789', $groups[0]['afm_key']);
        $this->assertSame($holder->id, $groups[0]['holder']?->id);
        $this->assertSame([$twin->id], $groups[0]['parked']->pluck('id')->all());

        // `find()` — what the migration gates on — must NOT see it: the UNIQUE
        // index permits a parked row, so it is not a constraint violation.
        $this->assertTrue(app(CustomerAfmDuplicates::class)->find((int) $company->id)->isEmpty());
    }

    public function test_the_command_lists_parked_rows_without_failing(): void
    {
        $company = $this->tenant();
        Customer::create(['company_id' => $company->id, 'name' => 'ΕΤΑΙΡΕΙΑ ΑΕ', 'afm' => '123456789']);
        $this->parkedTwin($company);

        $this->artisan('customers:afm-duplicates', ['--tenant' => $company->slug])
            ->expectsOutputToContain('Κανένας διπλός ΑΦΜ.')
            ->expectsOutputToContain('ΧΩΡΙΣ ταυτότητα ΑΦΜ')
            ->expectsOutputToContain('Εγκατάσταση πελάτη (myDATA)')
            ->assertExitCode(0);
    }

    public function test_merging_the_holder_into_a_parked_survivor_hands_the_identity_over(): void
    {
        $company = $this->tenant();
        $holder = Customer::create(['company_id' => $company->id, 'legacy_id' => 41, 'name' => 'ΕΤΑΙΡΕΙΑ ΑΕ', 'afm' => '123456789']);
        $twin = $this->parkedTwin($company);

        // The survivor is the PARKED row (the panel's «Συγχώνευση» always keeps
        // the open record, and suggestKeeper picks the fullest one — either can
        // land here). The merge force-deletes the holder, so without the reclaim
        // NOBODY would hold this ΑΦΜ afterwards.
        app(MergeCustomers::class)($twin, $holder);

        $this->assertNull(Customer::withTrashed()->find($holder->id), 'the holder is force-deleted by the merge');
        $this->assertSame('123456789', $twin->fresh()->afm_key);
        $this->assertFalse((bool) $twin->fresh()->afm_key_parked);
        // (The caller's instance stays stale — the merge re-reads both rows under
        // a lock and works on those, exactly as it already does for legacy_id.)
    }

    public function test_a_merge_never_steals_an_afm_another_customer_still_holds(): void
    {
        $company = $this->tenant();
        Customer::create(['company_id' => $company->id, 'name' => 'ΕΤΑΙΡΕΙΑ ΑΕ', 'afm' => '123456789']);
        $twin = $this->parkedTwin($company);
        // A third, unrelated row is what gets merged away.
        $other = Customer::create(['company_id' => $company->id, 'name' => 'ΑΣΧΕΤΟΣ', 'afm' => '094123456']);

        app(MergeCustomers::class)($twin, $other);

        $this->assertNull($twin->fresh()->afm_key, 'the ΑΦΜ is still held by the original holder');
        $this->assertTrue((bool) $twin->fresh()->afm_key_parked);
    }

    public function test_a_placeholder_afm_is_never_reported_as_parked(): void
    {
        $company = $this->tenant();
        Customer::create(['company_id' => $company->id, 'name' => 'ΛΙΑΝΙΚΗ Α', 'afm' => '000000000']);
        Customer::create(['company_id' => $company->id, 'name' => 'ΛΙΑΝΙΚΗ Β', 'afm' => 'ΔΕΝ ΕΧΕΙ']);

        $this->assertTrue(app(CustomerAfmDuplicates::class)->findParked((int) $company->id)->isEmpty());
    }
}
