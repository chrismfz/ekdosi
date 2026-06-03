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
                foreach ($customers as $c) {
                    $body = trim((string) $c->details);
                    if ($body === '') {
                        continue;
                    }

                    $exists = DB::table('notes')
                        ->where('company_id', $c->company_id)
                        ->where('notable_type', self::CUSTOMER_TYPE)
                        ->where('notable_id', $c->id)
                        ->where('source', 'backup')
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    DB::table('notes')->insert([
                        'company_id'     => $c->company_id,
                        'notable_type'   => self::CUSTOMER_TYPE,
                        'notable_id'     => $c->id,
                        'body'           => $body,
                        'is_pinned'      => false,
                        'source'         => 'backup',
                        'author_user_id' => null,
                        'created_at'     => $now,
                        'updated_at'     => $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Roll back only the rows this migration could have created.
        DB::table('notes')
            ->where('notable_type', self::CUSTOMER_TYPE)
            ->where('source', 'backup')
            ->delete();
    }
};
