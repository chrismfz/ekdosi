<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Combined ΤΔΑ — Slice 3c-1 (`docs/combined-tda-design.md` §4-A1).
 *
 * Complete the polymorphic audit on `delivery_note_events` so a ΤΔΑ `Invoice`
 * (not just a `DeliveryNote`) can parent lifecycle events:
 *   - `delivery_note_id` → NULLABLE (an Invoice-backed event has no DN id; the
 *     morph `movable_*` carries the parent). The column + FK stay for the
 *     DeliveryNote path (kept in lock-step by MirrorsMovableFromDeliveryNote).
 *   - ADD `unique(movable_type, movable_id, dedup_key)` — the idempotency anchor
 *     for BOTH parents (the old `unique(delivery_note_id, dedup_key)` can't dedup
 *     an Invoice event whose delivery_note_id is NULL). The old unique is KEPT
 *     (harmless for DeliveryNote rows; both are satisfied via the mirror hook)
 *     and retired in 3c-2 when syncLifecycleHistory goes morph-keyed.
 *
 * `delivery_marks.delivery_note_id` is already nullable (3a) → no change there.
 * Reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_note_events', function (Blueprint $t) {
            $t->dropForeign(['delivery_note_id']);
        });
        Schema::table('delivery_note_events', function (Blueprint $t) {
            $t->unsignedBigInteger('delivery_note_id')->nullable()->change();
            $t->foreign('delivery_note_id')->references('id')->on('delivery_notes')->cascadeOnDelete();
            $t->unique(['movable_type', 'movable_id', 'dedup_key']);
        });
    }

    public function down(): void
    {
        Schema::table('delivery_note_events', function (Blueprint $t) {
            $t->dropUnique(['movable_type', 'movable_id', 'dedup_key']);
            $t->dropForeign(['delivery_note_id']);
        });
        Schema::table('delivery_note_events', function (Blueprint $t) {
            // Restore NOT NULL — safe on rollback only if no Invoice-backed rows exist
            // yet (they arrive in 3c-2); acceptable for a schema-slice down().
            $t->unsignedBigInteger('delivery_note_id')->nullable(false)->change();
            $t->foreign('delivery_note_id')->references('id')->on('delivery_notes')->cascadeOnDelete();
        });
    }
};
