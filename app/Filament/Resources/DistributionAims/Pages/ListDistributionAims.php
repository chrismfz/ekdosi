<?php

namespace App\Filament\Resources\DistributionAims\Pages;

use App\Filament\Resources\DistributionAims\DistributionAimResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDistributionAims extends ListRecords
{
    protected static string $resource = DistributionAimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
