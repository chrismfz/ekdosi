<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\Companies\CompanyResource;
use Filament\Actions\CreateAction;

class ListCompanies extends BaseListRecords
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
