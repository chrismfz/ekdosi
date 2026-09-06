<?php

namespace App\Filament\Resources\TicketDepartments\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\TicketDepartments\TicketDepartmentResource;
use Filament\Actions\CreateAction;

class ListTicketDepartments extends BaseListRecords
{
    protected static string $resource = TicketDepartmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
