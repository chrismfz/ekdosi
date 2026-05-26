<?php

namespace App\Filament\Resources\DistributionAims\Pages;

use App\Filament\Resources\DistributionAims\DistributionAimResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDistributionAim extends EditRecord
{
    protected static string $resource = DistributionAimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
