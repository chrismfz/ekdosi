<?php

namespace App\Filament\Resources\MetricUnits\Pages;

use App\Filament\Resources\MetricUnits\MetricUnitResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMetricUnits extends ListRecords
{
    protected static string $resource = MetricUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
