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
 * An Invoice-backed ΤΔΑ row (3c) sets `movable_*` directly and leaves
 * `delivery_note_id` NULL; the `empty(movable_type)` guard makes the hook a no-op
 * there, so it never overwrites an explicit morph. Audit rows are append-only, so
 * a `creating` hook is enough — `delivery_note_id` never changes after insert.
 */
trait MirrorsMovableFromDeliveryNote
{
    public static function bootMirrorsMovableFromDeliveryNote(): void
    {
        static::creating(function ($model): void {
            if (empty($model->movable_type) && ! empty($model->delivery_note_id)) {
                $model->movable_type = DeliveryNote::class;
                $model->movable_id = $model->delivery_note_id;
            }
        });
    }

    public function movable(): MorphTo
    {
        return $this->morphTo();
    }
}
