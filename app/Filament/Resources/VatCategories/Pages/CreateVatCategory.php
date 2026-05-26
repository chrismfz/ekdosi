<?php

namespace App\Filament\Resources\VatCategories\Pages;

use App\Filament\Resources\VatCategories\VatCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVatCategory extends CreateRecord
{
    protected static string $resource = VatCategoryResource::class;
}
