<?php

namespace App\Filament;

use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

/**
 * Shared base for every resource list page.
 *
 * Filament caps page content at Width::SevenExtraLarge (1280px) by
 * default. On wide monitors (e.g. 2560px) that left a large empty
 * margin to the right while data tables still showed a horizontal
 * scrollbar. List/table pages extend this base so the table can use the
 * full available width instead.
 *
 * Scope is deliberately list pages only: create/edit FORM pages keep
 * the default ~1280px reading width (full-width form inputs on a 30"
 * screen read poorly). Smaller screens are unaffected — the 1280px cap
 * was never binding below it, and the table keeps its own horizontal
 * overflow for the rare case it's still needed.
 */
abstract class BaseListRecords extends ListRecords
{
    public function getMaxContentWidth(): Width | string | null
    {
        return Width::Full;
    }
}
