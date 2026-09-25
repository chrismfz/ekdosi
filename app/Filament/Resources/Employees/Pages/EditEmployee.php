<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    public function getTitle(): string
    {
        /** @var Employee $employee */
        $employee = $this->getRecord();

        return 'Εργαζόμενος — '.$employee->full_name;
    }

    protected function getHeaderActions(): array
    {
        return [
            EmployeeResource::unlockPinAction(),
            DeleteAction::make()
                ->modalDescription('Προτιμήστε «Ενεργός: όχι» — έτσι το ιστορικό αδειών και κάρτας μένει ορατό.'),
            RestoreAction::make(),
        ];
    }

    /** A new PIN also clears any lockout from wrong tries. */
    protected function afterSave(): void
    {
        /** @var Employee $employee */
        $employee = $this->getRecord();
        if ($employee->wasChanged('card_pin_hash')) {
            $employee->forceFill(['card_pin_failures' => 0, 'card_pin_locked_until' => null])->saveQuietly();
        }
    }
}
