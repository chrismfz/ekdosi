<?php

namespace App\Filament\Resources\LeaveRequests\Pages;

use App\Filament\Resources\LeaveRequests\LeaveRequestActions;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Models\LeaveRequest;
use Filament\Resources\Pages\ViewRecord;

class ViewLeaveRequest extends ViewRecord
{
    protected static string $resource = LeaveRequestResource::class;

    public function getTitle(): string
    {
        /** @var LeaveRequest $leave */
        $leave = $this->getRecord();

        return 'Άδεια — '.$leave->employee?->full_name.' · '.$leave->periodLabel();
    }

    protected function getHeaderActions(): array
    {
        return [
            LeaveRequestActions::approve(),
            LeaveRequestActions::reject(),
            LeaveRequestActions::cancel(),
            LeaveRequestActions::resendAccountant(),
            LeaveRequestActions::submitToErgani(),
            LeaveRequestActions::recordErganiProtocol(),
            LeaveRequestActions::erganiPdf(),
            LeaveRequestActions::cancelInErgani(),
        ];
    }
}
