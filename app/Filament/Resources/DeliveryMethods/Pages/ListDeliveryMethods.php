<?php

namespace App\Filament\Resources\DeliveryMethods\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\DeliveryMethods\DeliveryMethodResource;
use App\Filament\Support\StandardLookupSeedAction;
use Filament\Actions\CreateAction;

class ListDeliveryMethods extends BaseListRecords
{
    protected static string $resource = DeliveryMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),

            StandardLookupSeedAction::make(
                'seedDeliveryMethods',
                'Εισαγωγή τυπικών',
                'Εισαγωγή τυπικών τρόπων αποστολής',
                'Προστίθενται κοινοί τρόποι αποστολής (Παραλαβή από κατάστημα, Courier, ΕΛΤΑ, Ηλεκτρονική παράδοση…). Υπάρχοντες διατηρούνται — δεν διπλασιάζονται.',
            ),
        ];
    }
}
