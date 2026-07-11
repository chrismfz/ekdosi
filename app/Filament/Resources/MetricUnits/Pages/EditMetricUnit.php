<?php

namespace App\Filament\Resources\MetricUnits\Pages;

use App\Filament\Resources\MetricUnits\MetricUnitResource;
use App\Filament\Support\GuardedDeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMetricUnit extends EditRecord
{
    protected static string $resource = MetricUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuardedDeleteAction::make(fn ($record): array => MetricUnitResource::dependents($record)),
        ];
    }
}
