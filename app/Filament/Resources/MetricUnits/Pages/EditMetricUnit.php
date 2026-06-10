<?php

namespace App\Filament\Resources\MetricUnits\Pages;

use App\Filament\Resources\MetricUnits\MetricUnitResource;
use App\Filament\Support\GuardedDeleteAction;
use App\Models\Product;
use Filament\Resources\Pages\EditRecord;

class EditMetricUnit extends EditRecord
{
    protected static string $resource = MetricUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuardedDeleteAction::make(fn ($record): array => [
                'προϊόντα' => Product::where('metric_unit_id', $record->id)->count(),
            ]),
        ];
    }
}
