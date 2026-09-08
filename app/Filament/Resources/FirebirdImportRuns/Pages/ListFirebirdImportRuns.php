<?php

namespace App\Filament\Resources\FirebirdImportRuns\Pages;

use App\Filament\Resources\FirebirdImportRuns\FirebirdImportRunResource;
use Filament\Actions\CreateAction;
use App\Filament\BaseListRecords;

class ListFirebirdImportRuns extends BaseListRecords
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
