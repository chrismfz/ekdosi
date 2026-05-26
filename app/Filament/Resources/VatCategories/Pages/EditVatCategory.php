<?php

namespace App\Filament\Resources\VatCategories\Pages;

use App\Filament\Resources\VatCategories\VatCategoryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditVatCategory extends EditRecord
{
    protected static string $resource = VatCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
