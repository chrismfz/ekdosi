<?php

namespace App\Filament\Resources\PaymentMethods\Pages;

use App\Filament\Resources\PaymentMethods\PaymentMethodResource;
use App\Filament\Support\GuardedDeleteAction;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\ServiceContract;
use Filament\Resources\Pages\EditRecord;

class EditPaymentMethod extends EditRecord
{
    protected static string $resource = PaymentMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuardedDeleteAction::make(fn ($record): array => [
                'τιμολόγια' => GuardedDeleteAction::count(Invoice::class, 'payment_method_id', $record->id),
                'πληρωμές' => GuardedDeleteAction::count(Payment::class, 'payment_method_id', $record->id),
                'πελάτες' => GuardedDeleteAction::count(Customer::class, 'payment_method_id', $record->id),
                'συμβόλαια' => GuardedDeleteAction::count(ServiceContract::class, 'payment_method_id', $record->id),
                'τύποι παραστατικών (προεπιλογή)' => GuardedDeleteAction::count(InvoiceType::class, 'payment_method_id', $record->id),
            ]),
        ];
    }
}
