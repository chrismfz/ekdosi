<?php

namespace App\Filament\Resources\DistributionAims\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\DistributionAims\DistributionAimResource;
use App\Filament\Support\StandardLookupSeedAction;
use Filament\Actions\CreateAction;

class ListDistributionAims extends BaseListRecords
{
    protected static string $resource = DistributionAimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),

            StandardLookupSeedAction::make(
                'seedDistributionAims',
                'Εισαγωγή τυπικών',
                'Εισαγωγή τυπικών σκοπών διακίνησης',
                'Προστίθενται οι συνηθισμένοι σκοποί διακίνησης της ΑΑΔΕ (Πώληση, Πώληση για Λογ. Τρίτων, Δειγματισμός, Επιστροφή…). Υπάρχοντες διατηρούνται — δεν διπλασιάζονται.',
            ),
        ];
    }
}
