<?php

namespace App\Filament\Resources\VatCategories\Pages;

use App\Filament\Resources\VatCategories\VatCategoryResource;
use Filament\Actions\CreateAction;
use App\Filament\BaseListRecords;

class ListVatCategories extends BaseListRecords
{
    protected static string $resource = VatCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
