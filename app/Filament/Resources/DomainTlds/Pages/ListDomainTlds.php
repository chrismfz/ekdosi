<?php

namespace App\Filament\Resources\DomainTlds\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\DomainTlds\DomainTldResource;
use Filament\Actions\CreateAction;

class ListDomainTlds extends BaseListRecords
{
    protected static string $resource = DomainTldResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Προσθήκη TLD'),
        ];
    }
}
