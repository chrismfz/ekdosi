<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Widgets\StaffSetupChecklist;
use Filament\Actions\CreateAction;

class ListEmployees extends BaseListRecords
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Νέος εργαζόμενος')];
    }

    protected function getHeaderWidgets(): array
    {
        return [StaffSetupChecklist::class];
    }
}
