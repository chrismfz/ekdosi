<?php

namespace Tests\Feature\Customers;

use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Services\Customers\CustomerAfmDuplicates;
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
