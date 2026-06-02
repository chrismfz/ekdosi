<?php

namespace App\Models\Concerns;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Adds tenant-scoped tags to a model via the `taggables` morph pivot.
 * Used by Customer / Supplier / Product / Invoice. The available-tag
 * vocabulary is managed in the TagResource; attach/detach happens through
 * the Filament relationship Select (TagControls::field()).
 */
trait HasTags
{
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }
}
