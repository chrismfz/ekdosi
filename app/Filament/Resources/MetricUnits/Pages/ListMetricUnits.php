<?php

namespace App\Filament\Resources\MetricUnits\Pages;

use App\Filament\Resources\MetricUnits\MetricUnitResource;
use Filament\Actions\CreateAction;
use App\Filament\BaseListRecords;

class ListMetricUnits extends BaseListRecords
{
    protected static string $resource = MetricUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
