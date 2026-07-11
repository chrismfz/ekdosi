<?php

namespace App\Filament\Resources\DistributionAims\Pages;

use App\Filament\Resources\DistributionAims\DistributionAimResource;
use App\Filament\Support\GuardedDeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDistributionAim extends EditRecord
{
    protected static string $resource = DistributionAimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuardedDeleteAction::make(fn ($record): array => DistributionAimResource::dependents($record)),
        ];
    }
}
