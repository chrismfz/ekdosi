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
 *   - REPLACE the dedup unique with `unique(movable_type, movable_id, dedup_key)` —
 *     the idempotency anchor for BOTH parents (the old `unique(delivery_note_id,
 *     dedup_key)` can't dedup an Invoice event whose delivery_note_id is NULL, and
 *     for a DeliveryNote it is redundant with the morph unique via the mirror hook,
 *     so it is dropped rather than kept as dead weight). The plain
 *     `(movable_type, movable_id)` index that `nullableMorphs` (3a) created is also
 *     dropped — the new unique shares that leading prefix and serves the lookups.
 *
 * `delivery_marks.delivery_note_id` is already nullable (3a) → no change there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_note_events', function (Blueprint $t) {
            $t->dropUnique(['delivery_note_id', 'dedup_key']);
            $t->dropIndex(['movable_type', 'movable_id']); // redundant with the unique below
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
            // delivery_note_id is left NULLABLE on rollback: restoring NOT NULL would
            // FAIL once a later slice (3c-2) has added Invoice-backed events (NULL FK),
            // and a nullable column is a safe superset of the original. Restore the FK
            // and the original indexes.
            $t->foreign('delivery_note_id')->references('id')->on('delivery_notes')->cascadeOnDelete();
            $t->index(['movable_type', 'movable_id']);
            $t->unique(['delivery_note_id', 'dedup_key']);
        });
    }
};
