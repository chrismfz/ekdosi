<?php

namespace App\Filament\Resources\PaymentGatewayConnections\Pages;

use App\Filament\Resources\PaymentGatewayConnections\PaymentGatewayConnectionResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePaymentGatewayConnection extends CreateRecord
{
    protected static string $resource = PaymentGatewayConnectionResource::class;
}
