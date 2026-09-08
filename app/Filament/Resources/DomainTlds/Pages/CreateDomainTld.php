<?php

namespace App\Filament\Resources\DomainTlds\Pages;

use App\Filament\Resources\DomainTlds\DomainTldResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateDomainTld extends CreateRecord
{
    protected static string $resource = DomainTldResource::class;

    /** Explicit tenant stamp (the CLAUDE.md tenancy rule). */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Filament::getTenant()?->getKey();

        return $data;
    }
}
