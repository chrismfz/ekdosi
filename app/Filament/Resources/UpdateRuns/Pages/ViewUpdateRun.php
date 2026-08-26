<?php

namespace App\Filament\Resources\UpdateRuns\Pages;

use App\Filament\Resources\UpdateRuns\UpdateRunResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Read-only detail + live progress of one update run. Polling lives on the
 * Infolist sections (`->poll('3s')`), not this page — Filament 5's ViewRecord
 * has no native polling.
 */
class ViewUpdateRun extends ViewRecord
{
    protected static string $resource = UpdateRunResource::class;
}
