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
 *     morph `movable_*` carries the parent). The FK is dropped and re-added around
 *     the nullability change (the textbook order — MariaDB won't MODIFY a column
 *     the FK depends on). The column + FK stay for the DeliveryNote path
 *     (kept in lock-step by MirrorsMovableFromDeliveryNote).
 *   - ADD `unique(movable_type, movable_id, dedup_key)` — the idempotency anchor
 *     for BOTH parents (the old `unique(delivery_note_id, dedup_key)` can't dedup
 *     an Invoice event whose delivery_note_id is NULL).
 *
 * The old `unique(delivery_note_id, dedup_key)` is KEPT: on MariaDB it doubles as
 * the index the re-added FK requires (leading column `delivery_note_id`), so
 * dropping it fails with error 1553 «needed in a foreign key constraint». It is
 * redundant for dedup (the morph unique + the mirror hook already cover DeliveryNote
 * rows) but load-bearing as the FK index, so it stays. The plain `(movable_type,
 * movable_id)` index nullableMorphs (3a) created is likewise kept — trivial
 * redundancy, not worth the extra index churn.
 *
 * `delivery_marks.delivery_note_id` is already nullable (3a) → no change there.
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
            // delivery_note_id is left NULLABLE on rollback: restoring NOT NULL would
            // FAIL once a later slice (3c-2) has added Invoice-backed events (NULL FK),
            // and a nullable column is a safe superset of the original. The FK + the
            // old unique + the plain morph index were never dropped by up().
            $t->dropUnique(['movable_type', 'movable_id', 'dedup_key']);
        });
    }
};
