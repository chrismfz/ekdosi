<?php

namespace App\Filament\Resources\UpdateRuns\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\UpdateRuns\UpdateRunResource;

/**
 * History of application updates. No «New» action — an update is triggered from
 * «Υγεία συστήματος» (SystemHealth), which locks the target release and creates
 * the run; the cron scheduler applies it. This page is the audit + live view.
 */
class ListUpdateRuns extends BaseListRecords
{
    protected static string $resource = UpdateRunResource::class;
}
