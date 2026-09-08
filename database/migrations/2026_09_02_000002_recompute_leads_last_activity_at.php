<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `leads.last_activity_at` now means «last REAL contact» (call / email /
 * meeting / quote — LeadActivityType::isContact), not «last timeline row».
 * Recompute every cached value once so leads whose newest row is a note or a
 * status change surface in «Αδρανή» as they should. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE leads SET last_activity_at = (
                SELECT MAX(la.happened_at) FROM lead_activities la
                WHERE la.lead_id = leads.id
                  AND la.type IN ('call', 'email', 'meeting', 'quote')
            )
        SQL);
    }

    public function down(): void
    {
        // Nothing to restore — the column is a derived cache.
    }
};
