<?php

namespace App\Filament\Resources\ProductAttributes\Pages;

use App\Filament\Resources\ProductAttributes\ProductAttributeResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateProductAttribute extends CreateRecord
{
    protected static string $resource = ProductAttributeResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] ??= Filament::getTenant()?->getKey();

        return $data;
    }
}
