<?php

namespace App\Filament\Resources\Domains\Pages;

use App\Filament\Resources\Domains\DomainResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * The per-domain view (A1b: identity/dates/flags via the disabled form +
 * contacts/NS/notes/attachments/activity relation tabs). The registrar command
 * actions (availability/renew/transfer/EPP-code/sync) attach here at A2/A3 —
 * this page is their designated home. docs/domains/README.md §8.2.
 */
class ViewDomain extends ViewRecord
{
    protected static string $resource = DomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
