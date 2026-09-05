<?php

namespace App\Filament\Resources\CustomerUsers\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\CustomerUsers\CustomerUserResource;
use Filament\Actions\CreateAction;

class ListCustomerUsers extends BaseListRecords
{
    protected static string $resource = CustomerUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
