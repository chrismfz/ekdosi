<?php

namespace App\Filament\Resources\FirebirdImportRuns\Pages;

use App\Filament\Resources\FirebirdImportRuns\FirebirdImportRunResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFirebirdImportRuns extends ListRecords
{
    protected static string $resource = FirebirdImportRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New import')
                ->icon('heroicon-o-arrow-up-tray'),
        ];
    }
}
