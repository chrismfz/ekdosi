<?php

namespace App\Filament\Resources\PaymentMethods\Pages;

use App\Filament\Resources\PaymentMethods\PaymentMethodResource;
use App\Filament\Support\GuardedDeleteAction;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\ServiceContract;
use Filament\Resources\Pages\EditRecord;

class EditPaymentMethod extends EditRecord
{
    protected static string $resource = PaymentMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuardedDeleteAction::make(fn ($record): array => [
                'τιμολόγια' => Invoice::where('payment_method_id', $record->id)->count(),
                'πελάτες' => Customer::where('payment_method_id', $record->id)->count(),
                'συμβόλαια' => ServiceContract::where('payment_method_id', $record->id)->count(),
            ]),
        ];
    }
}
