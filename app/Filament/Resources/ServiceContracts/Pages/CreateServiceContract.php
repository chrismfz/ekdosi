<?php

namespace App\Filament\Resources\ServiceContracts\Pages;

use App\Filament\Resources\ServiceContracts\ServiceContractResource;
use Filament\Resources\Pages\CreateRecord;

class CreateServiceContract extends CreateRecord
{
    protected static string $resource = ServiceContractResource::class;
}
