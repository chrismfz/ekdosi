<?php

namespace App\Filament\Resources\LeaveRequests\Pages;

use App\Filament\Resources\LeaveRequests\LeaveRequestActions;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use Filament\Resources\Pages\ViewRecord;

class ViewLeaveRequest extends ViewRecord
{
    protected static string $resource = LeaveRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            LeaveRequestActions::approve(),
            LeaveRequestActions::reject(),
            LeaveRequestActions::cancel(),
            LeaveRequestActions::resendAccountant(),
        ];
    }
}
