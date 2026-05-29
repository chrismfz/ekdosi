<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\Suppliers\SupplierResource;
use Filament\Actions\CreateAction;

class ListSuppliers extends BaseListRecords
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
