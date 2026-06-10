<?php

namespace App\Filament\Resources\DistributionAims\Pages;

use App\Filament\Resources\DistributionAims\DistributionAimResource;
use App\Filament\Support\GuardedDeleteAction;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\InvoiceType;
use Filament\Resources\Pages\EditRecord;

class EditDistributionAim extends EditRecord
{
    protected static string $resource = DistributionAimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuardedDeleteAction::make(fn ($record): array => [
                'τιμολόγια' => GuardedDeleteAction::count(Invoice::class, 'distribution_aim_id', $record->id),
                'δελτία αποστολής' => GuardedDeleteAction::count(DeliveryNote::class, 'distribution_aim_id', $record->id),
                'τύποι παραστατικών (προεπιλογή)' => GuardedDeleteAction::count(InvoiceType::class, 'distribution_aim_id', $record->id),
            ]),
        ];
    }
}
