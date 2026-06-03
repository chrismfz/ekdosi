<?php

namespace App\Models\Concerns;

use App\Models\Note;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Adds polymorphic internal (operator-only) notes to a model. Pinned first,
 * then newest. Named `internalNotes` (not `notes`) so it never collides with
 * the printed `invoices.notes` column.
 */
trait HasInternalNotes
{
    public function internalNotes(): MorphMany
    {
        return $this->morphMany(Note::class, 'notable')
            ->orderByDesc('is_pinned')
            ->latest();
    }
}
