<?php

namespace App\Filament\Resources\DomainRegistrarConnections\Pages;

use App\Filament\Resources\DomainRegistrarConnections\DomainRegistrarConnectionResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateDomainRegistrarConnection extends CreateRecord
{
    protected static string $resource = DomainRegistrarConnectionResource::class;

    /**
     * Stamp the tenant explicitly rather than relying on Filament's implicit
     * tenant association (the CLAUDE.md tenancy rule: a tenant-owned write
     * declares its company_id). company_id is fillable on the model.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Filament::getTenant()?->getKey();

        return $data;
    }
}
