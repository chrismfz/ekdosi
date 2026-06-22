<?php

namespace App\Filament\Resources\Cmr\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\Cmr\CmrResource;
use Filament\Actions\CreateAction;

class ListCmr extends BaseListRecords
{
    protected static string $resource = CmrResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('+ Νέο CMR'),
        ];
    }
}
