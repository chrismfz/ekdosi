<?php

namespace App\Filament\Resources\DomainRegistrarConnections\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\DomainRegistrarConnections\DomainRegistrarConnectionResource;
use Filament\Actions\CreateAction;

class ListDomainRegistrarConnections extends BaseListRecords
{
    protected static string $resource = DomainRegistrarConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Προσθήκη σύνδεσης'),
        ];
    }
}
