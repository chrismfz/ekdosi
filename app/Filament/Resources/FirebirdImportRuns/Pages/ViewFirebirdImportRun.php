<?php

namespace App\Filament\Resources\FirebirdImportRuns\Pages;

use App\Filament\Resources\FirebirdImportRuns\FirebirdImportRunResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Read-only details page for one Firebird import run.
 *
 * Polling lives on the Status Section inside the Infolist
 * (`->poll('3s')`), NOT on this page. The blind review of PR #30
 * verified that Filament 5's ViewRecord has no native polling —
 * `pollingInterval` is dead code on the page class. Schema
 * Components do have `CanPoll`, so the operator-visible status
 * badge auto-refreshes from there.
 */
class ViewFirebirdImportRun extends ViewRecord
{
    protected static string $resource = FirebirdImportRunResource::class;
}
