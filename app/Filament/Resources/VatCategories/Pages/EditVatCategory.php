<?php

namespace App\Filament\Resources\VatCategories\Pages;

use App\Filament\Resources\VatCategories\VatCategoryResource;
use App\Filament\Support\GuardedDeleteAction;
use App\Models\Product;
use Filament\Resources\Pages\EditRecord;

class EditVatCategory extends EditRecord
{
    protected static string $resource = VatCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuardedDeleteAction::make(fn ($record): array => [
                'προϊόντα' => Product::where('vat_category_id', $record->id)->count(),
            ]),
        ];
    }
}
