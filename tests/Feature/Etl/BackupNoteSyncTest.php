<?php

namespace Tests\Feature\Etl;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Note;
use App\Services\Etl\BackupNoteSync;
use App\Services\Etl\EpsilonImporter;
use App\Services\MyData\MyDataLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BackupNoteSyncTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'T', 'slug' => 'bn-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create(['company_id' => $this->tenant->id, 'name' => 'K']);
    }

    public function test_phase3_dropped_the_details_column(): void
    {
        $this->assertFalse(Schema::hasColumn('customers', 'details'));
    }

    public function test_sync_is_idempotent_and_tracks_the_remark(): void
    {
        $c = $this->customer();

        BackupNoteSync::sync($this->tenant->id, $c->id, 'Παλιό σχόλιο');
        BackupNoteSync::sync($this->tenant->id, $c->id, 'Παλιό σχόλιο');   // re-run: no duplicate

        $notes = Note::where('notable_type', Customer::class)->where('notable_id', $c->id)->get();
        $this->assertCount(1, $notes);
        $this->assertSame('backup', $notes[0]->source);
        $this->assertSame('Παλιό σχόλιο', $notes[0]->body);
        $this->assertNull($notes[0]->author_user_id);

        // Source changes → the same note updates in place.
        BackupNoteSync::sync($this->tenant->id, $c->id, 'Νέο σχόλιο');
        $this->assertSame(1, Note::where('notable_id', $c->id)->count());
        $this->assertSame('Νέο σχόλιο', Note::where('notable_id', $c->id)->first()->body);

        // Empty remark in a (later/other) source → NO-OP, the note is kept
        // (a missing remark in one source must not wipe one from another).
        BackupNoteSync::sync($this->tenant->id, $c->id, '   ');
        $this->assertSame(1, Note::where('notable_id', $c->id)->count());
        $this->assertSame('Νέο σχόλιο', Note::where('notable_id', $c->id)->first()->body);
    }

    public function test_resyncs_a_soft_deleted_backup_note_instead_of_duplicating(): void
    {
        $c = $this->customer();

        BackupNoteSync::sync($this->tenant->id, $c->id, 'αρχικό');
        $note = Note::where('notable_id', $c->id)->first();
        $note->delete();   // operator soft-deletes it in the UI

        // Re-import: must restore + update the SAME row, not create a 2nd one.
        BackupNoteSync::sync($this->tenant->id, $c->id, 'ενημερωμένο');

        $all = Note::withTrashed()->where('notable_id', $c->id)->get();
        $this->assertCount(1, $all, 'A soft-deleted backup note must be reused, not duplicated.');
        $this->assertFalse($all[0]->trashed());
        $this->assertSame('ενημερωμένο', $all[0]->body);
    }

    public function test_sync_only_manages_its_own_backup_note(): void
    {
        $c = $this->customer();
        $operatorNote = Note::create([
            'company_id' => $this->tenant->id, 'notable_type' => Customer::class,
            'notable_id' => $c->id, 'body' => 'χειροκίνητη', 'source' => null,
        ]);

        BackupNoteSync::sync($this->tenant->id, $c->id, 'εισαγόμενη');

        // The operator note is untouched; a separate backup note is added.
        $this->assertDatabaseHas('notes', ['id' => $operatorNote->id, 'body' => 'χειροκίνητη', 'source' => null]);
        $this->assertSame(1, Note::where('notable_id', $c->id)->where('source', 'backup')->count());
        $this->assertSame(2, Note::where('notable_id', $c->id)->count());
    }

    public function test_epsilon_import_creates_one_backup_note_and_re_import_does_not_duplicate(): void
    {
        $seeder = app(MyDataLookupSeeder::class);
        $seeder->seedVatCategories($this->tenant);
        $seeder->seedPaymentMethods($this->tenant);

        $rows = [[
            'Name' => 'ΜΕ ΣΧΟΛΙΟ ΑΕ', 'TIN' => '800561849', 'CountryISO2' => 'GR',
            'Remarks' => 'Από Epsilon: προτιμά τιμολόγιο',
        ]];

        (new EpsilonImporter($this->tenant))->importCustomers($rows);
        (new EpsilonImporter($this->tenant))->importCustomers($rows);   // re-run

        $customer = Customer::where('company_id', $this->tenant->id)->where('afm', '800561849')->first();
        $this->assertNotNull($customer);

        $notes = $customer->internalNotes()->get();
        $this->assertCount(1, $notes);
        $this->assertSame('backup', $notes[0]->source);
        $this->assertSame('Από Epsilon: προτιμά τιμολόγιο', $notes[0]->body);
    }

    /**
     * Phase 2 data migration: existing customers.details → backup notes,
     * idempotent. Re-creates the pre-drop state (the migration chain already
     * dropped the column in setUp) and runs the migration's up() directly.
     */
    public function test_phase2_migration_moves_existing_details_into_notes(): void
    {
        Schema::table('customers', fn ($t) => $t->text('details')->nullable());

        $withRemark = $this->customer();
        $blank = $this->customer();
        DB::table('customers')->where('id', $withRemark->id)->update(['details' => 'Legacy: κακοπληρωτής']);
        DB::table('customers')->where('id', $blank->id)->update(['details' => '   ']);

        $migration = require database_path('migrations/2026_06_03_000002_migrate_customer_details_to_notes.php');
        $migration->up();
        $migration->up();   // idempotent re-run

        $notes = Note::where('notable_id', $withRemark->id)->get();
        $this->assertCount(1, $notes);
        $this->assertSame('backup', $notes[0]->source);
        $this->assertSame('Legacy: κακοπληρωτής', $notes[0]->body);

        // Blank/whitespace details produce no note.
        $this->assertSame(0, Note::where('notable_id', $blank->id)->count());
    }

    /**
     * Phase 3 must refuse to drop the column while a remark has no backup note
     * (guards against a partial Phase 2 / a hand-marked migration), and its
     * down() must restore the remarks into a re-added column (non-destructive).
     */
    public function test_phase3_guard_refuses_drop_when_unmigrated_and_down_restores(): void
    {
        Schema::table('customers', fn ($t) => $t->text('details')->nullable());
        $c = $this->customer();
        DB::table('customers')->where('id', $c->id)->update(['details' => 'Δεν μεταφέρθηκε']);

        $drop = require database_path('migrations/2026_06_03_000003_drop_details_from_customers_table.php');

        // Un-migrated remark → guard throws, column stays.
        try {
            $drop->up();
            $this->fail('Expected the drop guard to throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Refusing to drop', $e->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('customers', 'details'));

        // Migrate, then the drop succeeds.
        BackupNoteSync::sync($this->tenant->id, $c->id, 'Δεν μεταφέρθηκε');
        $drop->up();
        $this->assertFalse(Schema::hasColumn('customers', 'details'));

        // down() re-adds the column and restores the remark from the backup note.
        $drop->down();
        $this->assertTrue(Schema::hasColumn('customers', 'details'));
        $this->assertSame('Δεν μεταφέρθηκε', DB::table('customers')->where('id', $c->id)->value('details'));
    }

    /**
     * A soft-deleted backup note is NOT a safe home for the last copy: the
     * drop guard must still refuse (require a LIVE note), and a rollback must
     * restore even from the trashed note's body.
     */
    public function test_phase3_treats_trashed_backup_note_as_unsafe_but_rollback_still_restores_it(): void
    {
        Schema::table('customers', fn ($t) => $t->text('details')->nullable());
        $c = $this->customer();
        DB::table('customers')->where('id', $c->id)->update(['details' => 'Μόνο σε trashed']);

        BackupNoteSync::sync($this->tenant->id, $c->id, 'Μόνο σε trashed');
        Note::where('notable_id', $c->id)->first()->delete();   // soft-delete the only note

        $drop = require database_path('migrations/2026_06_03_000003_drop_details_from_customers_table.php');

        // Guard sees no LIVE backup note → refuses to drop.
        try {
            $drop->up();
            $this->fail('Guard should refuse to drop with only a trashed backup note.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Refusing to drop', $e->getMessage());
        }

        // Even so, down() restores the remark from the trashed note (no loss).
        Schema::table('customers', fn ($t) => $t->dropColumn('details'));   // simulate dropped state
        $drop->down();
        $this->assertSame('Μόνο σε trashed', DB::table('customers')->where('id', $c->id)->value('details'));
    }
}
