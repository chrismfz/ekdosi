<?php

namespace App\Models\Concerns;

use App\Models\DeliveryNote;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Movement-audit rows (`delivery_marks`, `delivery_note_events`) carry BOTH the
 * legacy `delivery_note_id` FK AND the polymorphic `movable_*` columns (Combined
 * ΤΔΑ, Slice 3a — additive). Every existing writer still sets `delivery_note_id`
 * (the issue-path DeliveryNoteSubmitter, the lifecycle service, the portability
 * export, ~30 tests); this `creating` hook mirrors it into `movable_*` so a
 * DeliveryNote row is ALSO reachable through `movable()` — without touching any of
 * those write sites.
 *
 * Kept in lock-step in BOTH directions so a row written EITHER way stays reachable
 * through both the FK readers (DeliveryNoteSubmitter's `->where('delivery_note_id')`,
 * FiledSeriesBackfill) AND the morph relations (`DeliveryNote::marks()/events()`, now
 * morphMany in 3c):
 *   - `DeliveryMark::create(['delivery_note_id' => …])` → mirror FK → morph;
 *   - `$note->marks()->create([…])` (morphMany sets `movable_*` only) → mirror morph → FK.
 * An Invoice-backed ΤΔΑ row (3c) sets `movable_*` to the Invoice and leaves
 * `delivery_note_id` NULL → NEITHER branch touches the FK, so an explicit non-DN morph
 * is never given a bogus FK. On `saving` (create AND update) so a later reassignment of
 * either key re-syncs the other rather than leaving a stale pair.
 */
trait MirrorsMovableFromDeliveryNote
{
    public static function bootMirrorsMovableFromDeliveryNote(): void
    {
        static::saving(function ($model): void {
            if (! empty($model->delivery_note_id)) {
                $model->movable_type = DeliveryNote::class;
                $model->movable_id = $model->delivery_note_id;
            } elseif ($model->movable_type === DeliveryNote::class && ! empty($model->movable_id)) {
                $model->delivery_note_id = $model->movable_id;
            }
        });
    }

    public function movable(): MorphTo
    {
        return $this->morphTo();
    }
}
