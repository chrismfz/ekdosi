<?php

use App\Models\DeliveryNote;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Combined ΤΔΑ — Slice 3a (`docs/combined-tda-design.md` §4-A1 / §5).
 *
 * Make the movement audit tables able to parent EITHER a 9.x `DeliveryNote` OR a
 * ΤΔΑ `Invoice` (1.1 with `isDeliveryNote`), by adding `nullableMorphs('movable')`
 * ALONGSIDE the existing `delivery_note_id` and backfilling every current row to
 * `movable_type = App\Models\DeliveryNote` (the FQCN — the app stores morph types
 * via `getMorphClass()`, no morph map; see StockService:316).
 *
 * ADDITIVE on purpose (design §4-A1 «keep delivery_note_id additively»): the FK is
 * read/written by many sites the morph must not disturb in a schema slice — the
 * issue-path `DeliveryNoteSubmitter`, `FiledSeriesBackfill` (the series ETL), the
 * portability export, and ~30 test assertions. Those all keep working unchanged;
 * `DeliveryMark`/`DeliveryNoteEvent` mirror the morph from `delivery_note_id` on
 * write (a `creating` hook) so the two never drift and a DeliveryNote row is
 * reachable through the morph too.
 *
 * DEFERRED to 3c (when Invoice-backed events first exist): make
 * `delivery_note_events.delivery_note_id` NULLABLE, move the dedup unique to
 * `(movable_type, movable_id, dedup_key)`, teach `FiledSeriesBackfill` the morph,
 * and flip the read relations to the morph. Nothing here needs them yet (3a
 * creates no Invoice-backed audit rows), so the schema stays fully backward-
 * compatible and the whole existing delivery suite is green.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_marks', function (Blueprint $t) {
            $t->nullableMorphs('movable'); // movable_type + movable_id + composite index
        });
        DB::table('delivery_marks')
            ->whereNotNull('delivery_note_id')
            ->update(['movable_type' => DeliveryNote::class, 'movable_id' => DB::raw('delivery_note_id')]);

        Schema::table('delivery_note_events', function (Blueprint $t) {
            $t->nullableMorphs('movable');
        });
        DB::table('delivery_note_events')
            ->whereNotNull('delivery_note_id')
            ->update(['movable_type' => DeliveryNote::class, 'movable_id' => DB::raw('delivery_note_id')]);
    }

    public function down(): void
    {
        Schema::table('delivery_marks', function (Blueprint $t) {
            $t->dropMorphs('movable');
        });
        Schema::table('delivery_note_events', function (Blueprint $t) {
            $t->dropMorphs('movable');
        });
    }
};
