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
     * On mount, hydrate the live-only `markup_display` field by DERIVING
     * it from the persisted (buy_price, sell_price) pair. This preserves
     * the historical relationship — if operator later nudges buy_price,
     * sell_price recomputes against the markup that was originally
     * applied, not against whatever the category's markup happens to be
     * NOW (admins can edit category markups after products are saved).
     *
     * Fallback: if buy_price is 0 / null (no price relationship to derive
     * from), fall back to the category's current markup as a hint. If
     * neither, leave at 0.
     *
     * Markup is purely a UX helper, not persisted (dehydrated:false),
     * matching the legacy app where markup lived in a Windows Registry
     * app-wide setting.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $buy = (float) ($data['buy_price'] ?? 0);
        $sell = (float) ($data['sell_price'] ?? 0);

        if ($buy > 0) {
            $data['markup_display'] = round(($sell - $buy) / $buy * 100, 2);
        } elseif (! empty($data['product_category_id'])) {
            $data['markup_display'] = (float) (ProductCategory::query()
                ->where('company_id', Filament::getTenant()?->getKey())
                ->whereKey($data['product_category_id'])
                ->value('markup') ?? 0);
        } else {
            $data['markup_display'] = 0;
        }

        return $data;
    }
}
