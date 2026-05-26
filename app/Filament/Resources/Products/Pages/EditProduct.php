<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Models\ProductCategory;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * On mount, hydrate the live-only `markup_display` field from the
     * selected product category's stored markup. This is purely cosmetic
     * — markup isn't persisted on products (matches legacy, which read
     * markup from a Windows Registry app setting). Without this, the
     * field would appear empty on edit even though the form depends on
     * it for the reactive sell-price calc.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (! empty($data['product_category_id'])) {
            $data['markup_display'] = (float) (ProductCategory::query()
                ->where('company_id', Filament::getTenant()?->getKey())
                ->whereKey($data['product_category_id'])
                ->value('markup') ?? 0);
        }
        return $data;
    }
}
