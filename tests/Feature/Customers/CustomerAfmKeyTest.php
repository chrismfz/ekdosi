<?php

namespace Tests\Feature\Customers;

use App\Actions\ConvertLeadToCustomer;
use App\Enums\LeadStatus;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use App\Services\Customers\CustomerAfmDuplicates;
use App\Services\Leads\LeadMatcher;
use App\Services\Portability\CompanyExporter;
use App\Services\Portability\CompanyImporter;
use App\Support\Afm;
use Filament\Facades\Filament;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Ένας πελάτης ανά ΑΦΜ ανά εταιρεία» — the key rule, the derived column, the
 * DB unique (soft-deleted included), the friendly form message, the audit
 * service/command, and the importer merging by ΑΦΜ identity.
 */
class CustomerAfmKeyTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $slug = 'ak'): Company
    {
        return Company::create([
            'name' => 'T '.$slug, 'slug' => $slug.'-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'afm' => '800561849',
        ]);
    }

    public function test_unique_key_rule(): void
    {
        $this->assertSame('123456789', Afm::uniqueKey('123456789'));
        $this->assertSame('123456789', Afm::uniqueKey('EL 123-456-789'));
        $this->assertSame('123456789', Afm::uniqueKey('gr123456789'));
        $this->assertSame('123456789', Afm::uniqueKey('ΑΦΜ: 123 456 789'));
        $this->assertSame('CY10259033P', Afm::uniqueKey('cy 10259033 p'), 'Foreign VAT keeps its letters.');
        $this->assertSame('123456789', Afm::uniqueKey('ΕL123456789'), 'Greek Ε look-alike does not mint a new identity.');
        $this->assertSame('123456789', Afm::uniqueKey('ΕΛ 123456789'), 'Greek-keyboard «ΕΛ» prefix folds to EL.');
        $this->assertSame('123456789', Afm::uniqueKey('ΑΦΜ ΕL123456789'), 'a Greek label is dropped');
        $this->assertSame('EE123456789', Afm::uniqueKey('ee 123456789'), 'Estonian EE+9 digits stays a foreign VAT');
        $this->assertNull(Afm::uniqueKey('N/A'), 'letters-only text is a free-text placeholder');
        $this->assertNull(Afm::uniqueKey('NONE'));
        $this->assertNull(Afm::uniqueKey('EL'));
        $this->assertNull(Afm::uniqueKey('ΑΦΜ'));
        $this->assertSame('EE123456789', Afm::uniqueKey('EE123456789'), 'Only EL/GR collapse to digits.');
        $this->assertNull(Afm::uniqueKey('000000000'), 'Placeholder is not an identity.');
        $this->assertNull(Afm::uniqueKey('999 999 999'));
        $this->assertNull(Afm::uniqueKey(''));
        $this->assertNull(Afm::uniqueKey(null));
        $this->assertNull(Afm::uniqueKey('  -  '));
    }

    public function test_model_derives_the_key_on_every_save(): void
    {
        $t = $this->tenant();
        $c = Customer::create(['company_id' => $t->id, 'name' => 'Α', 'afm' => 'EL 123456789']);
        $this->assertSame('123456789', $c->fresh()->afm_key);

        $c->update(['afm' => '000000000']);
        $this->assertNull($c->fresh()->afm_key);

        $c->update(['afm' => 'CY10259033P']);
        $this->assertSame('CY10259033P', $c->fresh()->afm_key);

        $this->assertSame([$c->id], Customer::query()->whereAfmKeyOf(' cy-10259033-p ')->pluck('id')->all());
        $this->assertSame([], Customer::query()->whereAfmKeyOf('000000000')->pluck('id')->all(), 'A placeholder matches nobody.');

        // …not even a customer that literally carries the placeholder text.
        Customer::create(['company_id' => $t->id, 'name' => 'Λιανική', 'afm' => '000000000']);
        $this->assertSame([], Customer::query()->whereAfmKeyOf('000000000')->pluck('id')->all(), 'no raw-text fallback');
        $this->assertSame([], Customer::query()->whereAfmKeyOf('')->pluck('id')->all());
    }

    public function test_database_refuses_a_second_customer_with_the_same_identity_even_soft_deleted(): void
    {
        $t = $this->tenant();
        $first = Customer::create(['company_id' => $t->id, 'name' => 'Πρώτος', 'afm' => '123456789']);

        try {
            Customer::create(['company_id' => $t->id, 'name' => 'Δεύτερος', 'afm' => 'EL 123-456-789']);
            $this->fail('Expected the unique index to reject the duplicate.');
        } catch (UniqueConstraintViolationException) {
        }

        $first->delete();
        try {
            Customer::create(['company_id' => $t->id, 'name' => 'Τρίτος', 'afm' => '123456789']);
            $this->fail('A soft-deleted owner still holds the identity.');
        } catch (UniqueConstraintViolationException) {
        }

        // Placeholders and blanks never collide; other tenants are independent.
        Customer::create(['company_id' => $t->id, 'name' => 'Λιανική 1', 'afm' => '000000000']);
        Customer::create(['company_id' => $t->id, 'name' => 'Λιανική 2', 'afm' => '000000000']);
        Customer::create(['company_id' => $t->id, 'name' => 'Χωρίς ΑΦΜ 1']);
        Customer::create(['company_id' => $t->id, 'name' => 'Χωρίς ΑΦΜ 2']);
        Customer::create(['company_id' => $this->tenant('b')->id, 'name' => 'Άλλη εταιρεία', 'afm' => '123456789']);
        $this->assertSame(5, Customer::withTrashed()->where('company_id', $t->id)->count());
    }

    public function test_form_shows_a_friendly_message_for_a_duplicate_and_a_deleted_owner(): void
    {
        $t = $this->tenant();
        $user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.l', 'password' => bcrypt('x')]);
        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($t);

        $owner = Customer::create(['company_id' => $t->id, 'name' => 'Κάτοχος ΑΕ', 'afm' => '123456789']);

        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'Διπλός', 'afm' => 'EL 123 456 789'])
            ->call('create')
            ->assertHasFormErrors(['afm'])
            ->assertSee('Κάτοχος ΑΕ');

        $owner->delete();
        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'Διπλός', 'afm' => '123456789'])
            ->call('create')
            ->assertHasFormErrors(['afm'])
            ->assertSee('ΔΙΑΓΡΑΜΜΕΝΟΣ');

        // A different ΑΦΜ (or a placeholder) goes through.
        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'Άλλος', 'afm' => '987654321'])
            ->call('create')
            ->assertHasNoFormErrors();
        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'Λιανική', 'afm' => '000000000'])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_duplicates_audit_works_before_the_column_exists(): void
    {
        $t = $this->tenant();
        Customer::create(['company_id' => $t->id, 'name' => 'Α', 'afm' => '123456789']);

        // Simulate the pre-migration world: the column (and its index) are gone,
        // and a twin with another spelling exists.
        Schema::table('customers', fn ($table) => $table->dropUnique('customers_company_afm_key_unique'));
        Schema::table('customers', fn ($table) => $table->dropColumn('afm_key'));
        DB::table('customers')->insert(['company_id' => $t->id, 'name' => 'Β', 'afm' => 'EL 123-456-789', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customers')->insert(['company_id' => $t->id, 'name' => 'Λιανική', 'afm' => '000000000', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customers')->insert(['company_id' => $t->id, 'name' => 'Λιανική 2', 'afm' => '000000000', 'created_at' => now(), 'updated_at' => now()]);

        $groups = app(CustomerAfmDuplicates::class)->find();
        $this->assertCount(1, $groups, 'placeholders never group');
        $this->assertSame('123456789', $groups[0]['afm_key']);
        $this->assertSame(['Α', 'Β'], $groups[0]['customers']->pluck('name')->all());

        $this->artisan('customers:afm-duplicates')->assertExitCode(1);
    }

    public function test_duplicates_audit_service_and_command(): void
    {
        $t = $this->tenant();
        $other = $this->tenant('o');
        Customer::create(['company_id' => $t->id, 'name' => 'Α', 'afm' => '123456789']);
        Customer::create(['company_id' => $t->id, 'name' => 'Μοναδικός', 'afm' => '111111112']);
        Customer::create(['company_id' => $other->id, 'name' => 'Άλλης', 'afm' => '123456789']);

        $this->assertTrue(app(CustomerAfmDuplicates::class)->find()->isEmpty());
        $this->artisan('customers:afm-duplicates')->assertExitCode(0);

        // Simulate the pre-constraint world: drop the index, insert a twin (one deleted).
        Schema::table('customers', fn ($table) => $table->dropUnique('customers_company_afm_key_unique'));
        DB::table('customers')->insert(['company_id' => $t->id, 'name' => 'Β (διπλός)', 'afm' => 'EL123456789', 'afm_key' => '123456789', 'deleted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $groups = app(CustomerAfmDuplicates::class)->find();
        $this->assertCount(1, $groups);
        $this->assertSame('123456789', $groups[0]['afm_key']);
        $this->assertSame(['Α', 'Β (διπλός)'], $groups[0]['customers']->pluck('name')->all(), 'soft-deleted twins count');
        $this->assertStringContainsString('ΔΙΑΓΡΑΜΜΕΝΟΣ', app(CustomerAfmDuplicates::class)->describe($groups));
        $this->assertTrue(app(CustomerAfmDuplicates::class)->find($other->id)->isEmpty(), 'per-tenant filter');

        $this->artisan('customers:afm-duplicates', ['--tenant' => $t->slug])
            ->expectsOutputToContain('Β (διπλός)')
            ->assertExitCode(1);
        $this->artisan('customers:afm-duplicates', ['--tenant' => $other->slug])->assertExitCode(0);
        $this->artisan('customers:afm-duplicates', ['--tenant' => 'nope'])->assertExitCode(2);
    }

    public function test_importer_dry_run_counts_an_afm_merge_as_an_update_and_keeps_the_local_legacy_id(): void
    {
        $src = Company::create(['name' => 'Src', 'slug' => 'src2', 'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'afm' => '800561849']);
        // Bundle row: created in the panel on the source (no legacy_id).
        $local = Customer::create(['company_id' => $src->id, 'name' => 'Από bundle', 'afm' => '123456789']);
        $bundle = app(CompanyExporter::class)->build($src, 'passphrase', 'p@ss', true);

        // Locally the same party is the ETL-imported row (legacy_id=7, other spelling).
        $local->forceFill(['legacy_id' => 7, 'name' => 'Τοπικός', 'afm' => 'EL123456789'])->save();

        $dry = app(CompanyImporter::class)->run($bundle, ['into' => 'src2', 'execute' => false, 'passphrase' => 'p@ss']);
        $this->assertSame(['insert' => 0, 'update' => 1], $dry['tables']['customers'], 'the dry-run says MERGE, not insert');

        app(CompanyImporter::class)->run($bundle, ['into' => 'src2', 'execute' => true, 'passphrase' => 'p@ss']);

        $merged = $local->fresh();
        $this->assertSame(1, Customer::withTrashed()->where('company_id', $src->id)->count());
        $this->assertSame('Από bundle', $merged->name);
        $this->assertSame(7, (int) $merged->legacy_id, 'the ETL re-run key survives an ΑΦΜ-merge with a legacy_id-less bundle row');
    }

    public function test_importer_fails_closed_when_the_natural_twin_would_take_another_rows_afm(): void
    {
        $src = Company::create(['name' => 'Src', 'slug' => 'src3', 'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'afm' => '800561849']);
        $a = Customer::create(['company_id' => $src->id, 'name' => 'A', 'afm' => '123456789']);
        $a->forceFill(['legacy_id' => 7])->save();
        $bundle = app(CompanyExporter::class)->build($src, 'passphrase', 'p@ss', true);

        // Locally: A's ΑΦΜ was corrected, and a DIFFERENT customer B now owns 123456789.
        $a->update(['afm' => '999999991']);
        Customer::create(['company_id' => $src->id, 'name' => 'B', 'afm' => '123456789']);

        try {
            app(CompanyImporter::class)->run($bundle, ['into' => 'src3', 'execute' => true, 'passphrase' => 'p@ss']);
            $this->fail('Expected a RuntimeException.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Σύγκρουση ΑΦΜ', $e->getMessage());
            $this->assertStringContainsString('afm-duplicates', $e->getMessage());
        }

        // Nothing was written (transaction rolled back): A keeps its corrected ΑΦΜ.
        $this->assertSame('999999991', $a->fresh()->afm);
        $this->assertSame(2, Customer::withTrashed()->where('company_id', $src->id)->count());
    }

    public function test_importer_releases_an_afm_when_the_twin_changes_it_and_refuses_a_second_legacy_identity(): void
    {
        // Source: A (legacy 7) had K2 at export time… no — build the bundle so A carries K1
        // and a NEW customer B carries K2, while LOCALLY A still holds K2.
        $src = Company::create(['name' => 'Src', 'slug' => 'src4', 'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'afm' => '800561849']);
        $a = Customer::create(['company_id' => $src->id, 'name' => 'A', 'afm' => '111111112']); // K1
        $a->forceFill(['legacy_id' => 7])->save();
        $b = Customer::create(['company_id' => $src->id, 'name' => 'B', 'afm' => '222222223']); // K2, panel-created
        $bundle = app(CompanyExporter::class)->build($src, 'passphrase', 'p@ss', true);

        // Locally: B never existed and A (legacy 7) still carries K2.
        $b->forceDelete();
        $a->update(['afm' => '222222223']);

        app(CompanyImporter::class)->run($bundle, ['into' => 'src4', 'execute' => true, 'passphrase' => 'p@ss']);

        $rows = Customer::withTrashed()->where('company_id', $src->id)->orderBy('id')->get();
        $this->assertCount(2, $rows, 'A updated to K1 released K2, so B is a NEW row — not merged into A');
        $this->assertSame(['A', 'B'], $rows->pluck('name')->all());
        $this->assertSame(['111111112', '222222223'], $rows->pluck('afm_key')->all());

        // A bundle row with a DIFFERENT non-null legacy_id but the same ΑΦΜ as a
        // local legacy-keyed row is a real conflict → fail closed.
        $src2 = Company::create(['name' => 'Src', 'slug' => 'src5', 'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'afm' => '800561849']);
        $c = Customer::create(['company_id' => $src2->id, 'name' => 'C', 'afm' => '333333334']);
        $c->forceFill(['legacy_id' => 9])->save();
        $bundle2 = app(CompanyExporter::class)->build($src2, 'passphrase', 'p@ss', true);
        $c->forceFill(['legacy_id' => 8])->save(); // locally the same party is legacy 8

        try {
            app(CompanyImporter::class)->run($bundle2, ['into' => 'src5', 'execute' => true, 'passphrase' => 'p@ss']);
            $this->fail('Expected a RuntimeException.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('legacy', $e->getMessage());
        }
        $this->assertSame(8, (int) $c->fresh()->legacy_id, 'nothing overwritten');
    }

    public function test_importer_handles_an_afm_swap_between_two_twins_and_adopts_a_missing_legacy_id(): void
    {
        // Bundle (the corrected source): A(legacy 7)=K1, B(legacy 8)=K2. Locally
        // the two are SWAPPED — a move/swap between twins must just work.
        $src = Company::create(['name' => 'Src', 'slug' => 'src8', 'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'afm' => '800561849']);
        $a = Customer::create(['company_id' => $src->id, 'name' => 'A', 'afm' => '111111112']);
        $a->forceFill(['legacy_id' => 7])->save();
        $b = Customer::create(['company_id' => $src->id, 'name' => 'B', 'afm' => '222222223']);
        $b->forceFill(['legacy_id' => 8])->save();
        // A third row that the bundle carries WITH a legacy_id.
        $c = Customer::create(['company_id' => $src->id, 'name' => 'C', 'afm' => '333333334']);
        $c->forceFill(['legacy_id' => 9])->save();
        $bundle = app(CompanyExporter::class)->build($src, 'passphrase', 'p@ss', true);

        // Locally: swap A/B, and C has no legacy_id (made in the panel here).
        $a->update(['afm' => '000000000']);
        $b->update(['afm' => '111111112']);
        $a->update(['afm' => '222222223']);
        $c->forceFill(['legacy_id' => null])->save();

        $dry = app(CompanyImporter::class)->run($bundle, ['into' => 'src8', 'execute' => false, 'passphrase' => 'p@ss']);
        $this->assertSame(['insert' => 0, 'update' => 3], $dry['tables']['customers'], 'dry-run: no conflict, three merges');

        app(CompanyImporter::class)->run($bundle, ['into' => 'src8', 'execute' => true, 'passphrase' => 'p@ss']);

        $this->assertSame('111111112', $a->fresh()->afm_key, 'A took K1 back');
        $this->assertSame('222222223', $b->fresh()->afm_key, 'B took K2 back');
        $this->assertSame(9, (int) $c->fresh()->legacy_id, 'a legacy_id-less local row adopts the bundle legacy_id on an ΑΦΜ-merge');
        $this->assertSame(3, Customer::withTrashed()->where('company_id', $src->id)->count());
    }

    public function test_importer_dry_run_refuses_the_same_conflict_execute_would(): void
    {
        $src = Company::create(['name' => 'Src', 'slug' => 'src9', 'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'afm' => '800561849']);
        $a = Customer::create(['company_id' => $src->id, 'name' => 'A', 'afm' => '123456789']);
        $a->forceFill(['legacy_id' => 7])->save();
        $bundle = app(CompanyExporter::class)->build($src, 'passphrase', 'p@ss', true);

        // Locally A's ΑΦΜ was corrected and a NON-twin row B now owns 123456789.
        $a->update(['afm' => '999999991']);
        Customer::create(['company_id' => $src->id, 'name' => 'B', 'afm' => '123456789']);

        try {
            app(CompanyImporter::class)->run($bundle, ['into' => 'src9', 'execute' => false, 'passphrase' => 'p@ss']);
            $this->fail('The dry-run must refuse what execute refuses.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Σύγκρουση ΑΦΜ', $e->getMessage());
        }
    }

    public function test_edit_page_survives_the_unique_race_with_a_friendly_message(): void
    {
        $t = $this->tenant();
        $user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.l', 'password' => bcrypt('x')]);
        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($t);

        $mine = Customer::create(['company_id' => $t->id, 'name' => 'Δικός μου', 'afm' => '999999991']);

        // The twin lands AFTER validation, right before our UPDATE.
        $fired = false;
        Customer::updating(function (Customer $c) use (&$fired, $t): void {
            if (! $fired) {
                $fired = true;
                DB::table('customers')->insert(['company_id' => $t->id, 'name' => 'Νικητής', 'afm' => '123456789', 'afm_key' => '123456789', 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        Livewire::test(EditCustomer::class, ['record' => $mine->getRouteKey()])
            ->fillForm(['afm' => '123456789'])
            ->call('save')
            ->assertNotified('Υπάρχει ήδη πελάτης με αυτό το ΑΦΜ');

        $this->assertSame('999999991', $mine->fresh()->afm, 'the edit was not applied');
    }

    public function test_importer_result_does_not_depend_on_bundle_order(): void
    {
        // Bundle: B (no legacy_id, ΑΦΜ K2, created FIRST → lower id, earlier in
        // the dump) and A (legacy 7, ΑΦΜ K1). Locally A still holds K2.
        $src = Company::create(['name' => 'Src', 'slug' => 'src6', 'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'afm' => '800561849']);
        $b = Customer::create(['company_id' => $src->id, 'name' => 'B', 'afm' => '222222223']);
        $a = Customer::create(['company_id' => $src->id, 'name' => 'A', 'afm' => '111111112']);
        $a->forceFill(['legacy_id' => 7])->save();
        $bundle = app(CompanyExporter::class)->build($src, 'passphrase', 'p@ss', true);
        // Force the adverse order (the dump's order is not a contract).
        usort($bundle['data']['customers'], fn (array $x, array $y): int => strcmp($y['name'], $x['name']));
        $this->assertSame(['B', 'A'], array_column($bundle['data']['customers'], 'name'), 'B precedes A in the dump');

        $b->forceDelete();
        $a->update(['afm' => '222222223']);

        app(CompanyImporter::class)->run($bundle, ['into' => 'src6', 'execute' => true, 'passphrase' => 'p@ss']);

        $rows = Customer::withTrashed()->where('company_id', $src->id)->orderBy('id')->get();
        $this->assertSame(['A', 'B'], $rows->pluck('name')->all(), 'B is a new row; A kept its identity and got K1');
        $this->assertSame(['111111112', '222222223'], $rows->pluck('afm_key')->all());
        $this->assertSame(7, (int) $rows[0]->legacy_id);
    }

    public function test_migration_backfills_lead_afm_to_the_identity_form(): void
    {
        $t = $this->tenant();
        $lead = Lead::create(['company_id' => $t->id, 'name' => 'Παλιό', 'afm' => '10259033']);
        DB::table('leads')->where('id', $lead->id)->update(['afm' => 'EL 123-456-789']); // pre-release form

        $migration = require base_path('database/migrations/2026_09_03_000001_add_afm_key_unique_to_customers.php');
        $migration->up(); // idempotent re-run

        $this->assertSame('123456789', $lead->fresh()->afm);
    }

    public function test_importer_normalises_lead_afm_so_a_restored_dnc_lead_still_blocks(): void
    {
        $src = Company::create(['name' => 'Src', 'slug' => 'src7', 'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'afm' => '800561849']);
        $lead = Lead::create(['company_id' => $src->id, 'name' => 'Ενοχλημένος', 'afm' => '123456789', 'status' => LeadStatus::DoNotContact, 'lost_reason' => 'x']);
        $bundle = app(CompanyExporter::class)->build($src, 'passphrase', 'p@ss', true);

        // A pre-release bundle carried the ΑΦΜ as typed.
        foreach ($bundle['data']['leads'] as &$row) {
            $row['afm'] = 'EL 123-456-789';
        }
        unset($row);
        $lead->forceDelete();

        app(CompanyImporter::class)->run($bundle, ['into' => 'src7', 'execute' => true, 'passphrase' => 'p@ss']);

        $restored = Lead::withTrashed()->where('company_id', $src->id)->firstOrFail();
        $this->assertSame('123456789', $restored->afm, 'identity form on import');
        $this->assertTrue(app(LeadMatcher::class)->find($src->id, 'el123456789', null)->hasDoNotContact(), 'DNC survives the restore');
    }

    public function test_create_page_survives_the_unique_race_with_a_friendly_message(): void
    {
        $t = $this->tenant();
        $user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.l', 'password' => bcrypt('x')]);
        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($t);

        // The twin lands AFTER validation, right before our INSERT.
        $fired = false;
        Customer::creating(function (Customer $c) use (&$fired, $t): void {
            if (! $fired && $c->name === 'Χαμένος') {
                $fired = true;
                DB::table('customers')->insert(['company_id' => $t->id, 'name' => 'Νικητής', 'afm' => '123456789', 'afm_key' => '123456789', 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'Χαμένος', 'afm' => '123456789'])
            ->call('create')
            ->assertNotified('Υπάρχει ήδη πελάτης με αυτό το ΑΦΜ');

        $this->assertSame(['Νικητής'], Customer::where('company_id', $t->id)->pluck('name')->all(), 'one customer, no 500');
    }

    public function test_convert_lead_survives_the_unique_race_with_guidance(): void
    {
        $t = $this->tenant();
        $lead = Lead::create(['company_id' => $t->id, 'name' => 'Lead', 'afm' => '123456789']);
        $fired = false;
        Customer::creating(function (Customer $c) use (&$fired, $t): void {
            if (! $fired) {
                $fired = true;
                DB::table('customers')->insert(['company_id' => $t->id, 'name' => 'Νικητής', 'afm' => '123456789', 'afm_key' => '123456789', 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        try {
            app(ConvertLeadToCustomer::class)($lead);
            $this->fail('Expected the guided RuntimeException.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Σύνδεση', $e->getMessage());
        }
        $this->assertNull($lead->fresh()->converted_customer_id);
    }

    public function test_importer_merges_a_bundle_customer_into_the_local_owner_of_the_same_afm(): void
    {
        $src = Company::create(['name' => 'Src', 'slug' => 'src', 'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'afm' => '800561849']);
        $local = Customer::create(['company_id' => $src->id, 'name' => 'Από bundle', 'afm' => 'EL 123456789', 'city' => 'Αθήνα']);
        $bundle = app(CompanyExporter::class)->build($src, 'passphrase', 'p@ss', true);

        // Locally the row was edited since (no legacy_id → its content signature no
        // longer matches the bundle twin); only the ΑΦΜ identity ties them.
        $local->update(['name' => 'Τοπικός (ξαναγραμμένος)', 'afm' => '123456789', 'city' => 'Πάτρα']);

        app(CompanyImporter::class)->run($bundle, ['into' => 'src', 'execute' => true, 'passphrase' => 'p@ss']);

        $this->assertSame(1, Customer::withTrashed()->where('company_id', $src->id)->count(), 'merged, not duplicated (and no UNIQUE error)');
        $merged = $local->fresh();
        $this->assertSame('Από bundle', $merged->name, 'bundle values applied onto the local owner');
        $this->assertSame('123456789', $merged->afm_key);
    }
}
