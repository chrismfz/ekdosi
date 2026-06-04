<?php

namespace App\Filament\Resources\ServiceContracts\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\ServiceContracts\ServiceContractResource;
use Filament\Actions\CreateAction;

class ListServiceContracts extends BaseListRecords
{
    protected static string $resource = ServiceContractResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
