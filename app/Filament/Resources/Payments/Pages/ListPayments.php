<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\Payments\PaymentResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;

class ListPayments extends BaseListRecords
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // The receipt cockpit: customer → balance → open invoices, FIFO split.
            Action::make('receive')
                ->label('Νέα είσπραξη')
                ->icon('heroicon-o-banknotes')
                ->url(PaymentResource::getUrl('receive'))
                ->visible(fn (): bool => PaymentResource::canCreate()),
            CreateAction::make()->color('gray'),
        ];
    }
}
