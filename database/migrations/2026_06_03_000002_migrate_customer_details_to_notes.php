<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2: move existing `customers.details` (imported Epsilon Remarks / legacy
 * DETAILS) into the richer `notes` table as a 'backup'-sourced note, so the
 * column can be dropped (Phase 3) without losing the imported remarks.
 *
 * Idempotent: skips a customer that already has a 'backup' note (so it co-exists
 * with the importer's own upsert and is safe to re-run). Uses the literal morph
 * type string (not `Customer::class`) to stay stable if the model is ever moved.
 */
return new class extends Migration
{
    private const CUSTOMER_TYPE = 'App\\Models\\Customer';

    public function up(): void
    {
        // The column may already be gone on a fresh install where Phase 3 ran in
        // the same batch ordering — guard so the migration is always safe.
        if (! DB::getSchemaBuilder()->hasColumn('customers', 'details')) {
            return;
        }

        $now = now();

        DB::table('customers')
            ->whereNotNull('details')
            ->where('details', '!=', '')
            ->orderBy('id')
            ->chunkById(500, function ($customers) use ($now): void {
                // One query for the already-migrated set in this chunk (incl.
                // trashed — a soft-deleted backup note still counts as "present",
                // matching BackupNoteSync's withTrashed lookup), then a single
                // bulk insert for the rest. Avoids a SELECT+INSERT per row.
                $ids = $customers->pluck('id')->all();

                $already = DB::table('notes')
                    ->where('notable_type', self::CUSTOMER_TYPE)
                    ->where('source', 'backup')
                    ->whereIn('notable_id', $ids)
                    ->pluck('notable_id')
                    ->all();
                $already = array_flip($already);

                $insert = [];
                foreach ($customers as $c) {
                    $body = trim((string) $c->details);
                    if ($body === '' || isset($already[$c->id])) {
                        continue;
                    }

                    $insert[] = [
                        'company_id'     => $c->company_id,
                        'notable_type'   => self::CUSTOMER_TYPE,
                        'notable_id'     => $c->id,
                        'body'           => $body,
                        'is_pinned'      => false,
                        'source'         => 'backup',
                        'author_user_id' => null,
                        'created_at'     => $now,
                        'updated_at'     => $now,
                    ];
                }

                // Insert in sub-batches so the bind-param count stays well under
                // even an ancient sqlite's SQLITE_MAX_VARIABLE_NUMBER (999).
                foreach (array_chunk($insert, 100) as $batch) {
                    DB::table('notes')->insert($batch);
                }
            });
    }

    public function down(): void
    {
        // On a full rollback this runs AFTER 000003.down(), which has already
        // re-added `customers.details` and copied every backup note's body back
        // into it — so removing the backup notes here is non-destructive (the
        // remarks live in `details` again). This deletes ALL customer backup
        // notes (incl. importer-created ones), which is correct: the column is
        // the source of truth once restored.
        DB::table('notes')
            ->where('notable_type', self::CUSTOMER_TYPE)
            ->where('source', 'backup')
            ->delete();
    }
};
