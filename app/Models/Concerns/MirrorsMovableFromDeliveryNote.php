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
 * `delivery_note_id` NULL → this is a no-op there, so an explicit morph is never
 * overwritten. Mirrored on `saving` (create AND update), not just `creating`: the
 * rows are append-only today, but keying off `delivery_note_id` on every save means
 * even a later data-fix that reassigns it can't leave `movable_*` on the stale
 * parent — the morph always tracks the FK whenever the FK is set.
 */
trait MirrorsMovableFromDeliveryNote
{
    public static function bootMirrorsMovableFromDeliveryNote(): void
    {
        static::saving(function ($model): void {
            if (! empty($model->delivery_note_id)) {
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
