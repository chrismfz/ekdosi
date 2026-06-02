<?php

namespace App\Filament\Resources\MetricUnits\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\MetricUnits\MetricUnitResource;
use App\Filament\Support\StandardLookupSeedAction;
use Filament\Actions\CreateAction;

class ListMetricUnits extends BaseListRecords
{
    protected static string $resource = MetricUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),

            StandardLookupSeedAction::make(
                'seedMetricUnits',
                'Εισαγωγή τυπικών',
                'Εισαγωγή τυπικών μονάδων μέτρησης',
                'Προστίθενται κοινές μονάδες (ΤΕΜ, ΥΠΗΡΕΣΙΑ, ΩΡΑ, ΜΗΝΑΣ, ΚΙΛΟ, ΛΙΤΡΟ, ΜΕΤΡΟ, Μ², Μ³…). Υπάρχουσες διατηρούνται — δεν διπλασιάζονται.',
            ),
        ];
    }
}
