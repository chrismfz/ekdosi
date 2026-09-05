<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Payment;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPayment extends EditRecord
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // «Πίσω στην καρτέλα» — a payment/refund is usually reached FROM a
            // customer's Καρτέλα (the ledger rows link here), so offer a one-click
            // way back instead of hunting through the menu.
            Action::make('customer_ledger')
                ->label('Καρτέλα πελάτη')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->visible(fn (Payment $record): bool => $record->customer_id !== null)
                ->url(fn (Payment $record): ?string => $record->customer_id
                    ? CustomerResource::getUrl('ledger', ['record' => $record->customer_id])
                    : null),
            DeleteAction::make(),
        ];
    }
}
