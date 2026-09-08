<?php

namespace App\Filament\Resources\PaymentMethods\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\PaymentMethods\PaymentMethodResource;
use App\Filament\Support\StandardLookupSeedAction;
use Filament\Actions\CreateAction;

class ListPaymentMethods extends BaseListRecords
{
    protected static string $resource = PaymentMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),

            StandardLookupSeedAction::make(
                'seedPaymentMethods',
                'Εισαγωγή τυπικών (ΑΑΔΕ §8.12)',
                'Εισαγωγή τυπικών τρόπων πληρωμής',
                'Προστίθενται οι 8 τυπικοί τρόποι πληρωμής της ΑΑΔΕ (Μετρητά, POS/e-POS, Επί Πιστώσει, IRIS…) με τον κωδικό myDATA τους. Υπάρχοντες διατηρούνται — δεν διπλασιάζονται.',
            ),
        ];
    }
}
