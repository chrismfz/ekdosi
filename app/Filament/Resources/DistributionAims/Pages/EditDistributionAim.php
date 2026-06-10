<?php

namespace App\Filament\Resources\DistributionAims\Pages;

use App\Filament\Resources\DistributionAims\DistributionAimResource;
use App\Filament\Support\GuardedDeleteAction;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use Filament\Resources\Pages\EditRecord;

class EditDistributionAim extends EditRecord
{
    protected static string $resource = DistributionAimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuardedDeleteAction::make(fn ($record): array => [
                'τιμολόγια' => Invoice::where('distribution_aim_id', $record->id)->count(),
                'δελτία αποστολής' => DeliveryNote::where('distribution_aim_id', $record->id)->count(),
            ]),
        ];
    }
}
