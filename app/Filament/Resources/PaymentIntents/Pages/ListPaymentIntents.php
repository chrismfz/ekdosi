<?php

namespace App\Filament\Resources\PaymentIntents\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\PaymentIntents\PaymentIntentResource;

class ListPaymentIntents extends BaseListRecords
{
    protected static string $resource = PaymentIntentResource::class;
}
