<?php

namespace App\Filament\Resources\TicketDepartments\Pages;

use App\Filament\Resources\TicketDepartments\TicketDepartmentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTicketDepartment extends EditRecord
{
    protected static string $resource = TicketDepartmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
