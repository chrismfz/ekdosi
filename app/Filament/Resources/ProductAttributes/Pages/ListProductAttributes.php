<?php

namespace App\Filament\Resources\ProductAttributes\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\ProductAttributes\ProductAttributeResource;
use Filament\Actions\CreateAction;

class ListProductAttributes extends BaseListRecords
{
    protected static string $resource = ProductAttributeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
