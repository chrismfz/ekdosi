<?php

namespace App\Filament\Resources\CustomerUsers\Pages;

use App\Filament\Resources\CustomerUsers\CustomerUserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomerUser extends CreateRecord
{
    protected static string $resource = CustomerUserResource::class;
}
