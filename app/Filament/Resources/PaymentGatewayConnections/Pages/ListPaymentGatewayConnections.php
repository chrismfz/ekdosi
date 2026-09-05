<?php

namespace App\Filament\Resources\PaymentGatewayConnections\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\PaymentGatewayConnections\PaymentGatewayConnectionResource;
use Filament\Actions\CreateAction;

class ListPaymentGatewayConnections extends BaseListRecords
{
    protected static string $resource = PaymentGatewayConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Προσθήκη τρόπου'),
        ];
    }
}
