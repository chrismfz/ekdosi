<?php

namespace App\Filament\Resources\PaymentGatewayEvents\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\PaymentGatewayEvents\PaymentGatewayEventResource;

class ListPaymentGatewayEvents extends BaseListRecords
{
    protected static string $resource = PaymentGatewayEventResource::class;
}
