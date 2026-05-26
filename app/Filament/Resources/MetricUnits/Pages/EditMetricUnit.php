<?php

namespace App\Filament\Resources\MetricUnits\Pages;

use App\Filament\Resources\MetricUnits\MetricUnitResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMetricUnit extends EditRecord
{
    protected static string $resource = MetricUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
