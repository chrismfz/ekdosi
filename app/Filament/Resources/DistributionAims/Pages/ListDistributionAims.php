<?php

namespace App\Filament\Resources\DistributionAims\Pages;

use App\Filament\Resources\DistributionAims\DistributionAimResource;
use Filament\Actions\CreateAction;
use App\Filament\BaseListRecords;

class ListDistributionAims extends BaseListRecords
{
    protected static string $resource = DistributionAimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
