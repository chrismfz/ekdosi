<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: drop `customers.details`. Its data now lives in `notes` (Phase 2),
 * the importers write there (BackupNoteSync), and nothing reads the column.
 *
 * Safety net: refuses to drop if any customer with a remark has no backup note
 * yet — so a partial/failed Phase-2 (or someone marking it run by hand) can't
 * silently take the column with un-migrated data. `down()` is restorative.
 */
return new class extends Migration
{
    private const CUSTOMER_TYPE = 'App\\Models\\Customer';

    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'details')) {
            return;
        }

        $unmigrated = DB::table('customers as c')
            ->whereNotNull('c.details')
            ->where('c.details', '!=', '')
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw(1))
                    ->from('notes')
                    ->whereColumn('notes.notable_id', 'c.id')
                    ->where('notes.notable_type', self::CUSTOMER_TYPE)
                    ->where('notes.source', 'backup')
                    // LIVE only: a soft-deleted note is NOT a safe home for the
                    // last copy, so a trashed-only note must block the drop.
                    ->whereNull('notes.deleted_at');
            })
            ->count();

        if ($unmigrated > 0) {
            throw new \RuntimeException(
                "Refusing to drop customers.details: {$unmigrated} customer(s) with a remark "
                .'have no backup note yet. Run the data migration (…migrate_customer_details_to_notes) first.'
            );
        }

        Schema::table('customers', function (Blueprint $t) {
            $t->dropColumn('details');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('customers', 'details')) {
            Schema::table('customers', function (Blueprint $t) {
                $t->text('details')->nullable();
            });
        }

        // Restore the remarks from the backup notes so the rollback is
        // non-destructive (the next migration down, 000002, then clears them).
        // Include TRASHED notes: 000002.down hard-deletes them too, so a
        // soft-deleted note's body must still come back into `details`.
        DB::table('notes')
            ->where('notable_type', self::CUSTOMER_TYPE)
            ->where('source', 'backup')
            ->orderBy('id')
            ->chunkById(500, function ($notes): void {
                foreach ($notes as $n) {
                    DB::table('customers')
                        ->where('id', $n->notable_id)
                        ->update(['details' => $n->body]);
                }
            });
    }
};
