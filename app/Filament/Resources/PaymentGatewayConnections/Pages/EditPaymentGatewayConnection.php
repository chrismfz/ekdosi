<?php

namespace App\Filament\Resources\PaymentGatewayConnections\Pages;

use App\Filament\Resources\PaymentGatewayConnections\PaymentGatewayConnectionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPaymentGatewayConnection extends EditRecord
{
    protected static string $resource = PaymentGatewayConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
