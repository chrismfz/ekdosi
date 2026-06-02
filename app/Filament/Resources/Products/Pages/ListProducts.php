<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\Tags\TagControls;
use App\Models\Product;
use Filament\Actions\CreateAction;
use App\Filament\BaseListRecords;

class ListProducts extends BaseListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return TagControls::pinnedTabs(Product::class);
    }
}
