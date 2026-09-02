<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

    /**
     * Stamp the tenant explicitly (robust in CLI/test paths where Filament's
     * tenancy observer isn't booted — same as CreateTag / CreateQuote / CreateLead).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] ??= Filament::getTenant()?->getKey();

        return $data;
    }
}
