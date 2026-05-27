<?php

namespace App\Filament\Resources\FirebirdImportRuns\Pages;

use App\Filament\Resources\FirebirdImportRuns\FirebirdImportRunResource;
use Filament\Resources\Pages\ViewRecord;

class ViewFirebirdImportRun extends ViewRecord
{
    protected static string $resource = FirebirdImportRunResource::class;

    /**
     * Poll while the run is still in flight so the operator sees
     * status transitions live (uploaded → restoring → importing →
     * completed). Once the row reaches a terminal state, the
     * polling is wasteful but harmless — a future refinement could
     * disable it dynamically.
     */
    protected ?string $pollingInterval = '3s';

    public function getPollingInterval(): ?string
    {
        return $this->record->isTerminal() ? null : '3s';
    }
}
