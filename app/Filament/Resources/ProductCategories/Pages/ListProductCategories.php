<?php

namespace App\Filament\Resources\ProductCategories\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\ProductCategories\ProductCategoryResource;
use App\Filament\Support\StandardLookupSeedAction;
use Filament\Actions\CreateAction;

class ListProductCategories extends BaseListRecords
{
    protected static string $resource = ProductCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),

            StandardLookupSeedAction::make(
                'seedProductCategories',
                'Εισαγωγή τυπικών',
                'Εισαγωγή τυπικών κατηγοριών προϊόντων',
                'Προστίθενται βασικές κατηγορίες (Υπηρεσίες, Εμπορεύματα, Προϊόντα) με μηδενικό περιθώριο. Υπάρχουσες διατηρούνται — δεν διπλασιάζονται.',
            ),
        ];
    }
}
