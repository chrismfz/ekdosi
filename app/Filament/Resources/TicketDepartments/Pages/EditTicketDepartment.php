<?php

namespace App\Filament\Resources\TicketDepartments\Pages;

use App\Filament\Resources\TicketDepartments\TicketDepartmentResource;
use App\Models\TicketDepartment;
use App\Services\Support\Inbound\ImapMailbox;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditTicketDepartment extends EditRecord
{
    protected static string $resource = TicketDepartmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // «Test σύνδεσης» — read-only IMAP connect+login against the SAVED config
            // (save first, then test), so the operator knows the credentials work
            // before arming the poller. Same check the MCP support_imap tool runs.
            Action::make('test_imap')
                ->label('Test σύνδεσης')
                ->icon('heroicon-o-signal')
                ->color('gray')
                ->visible(fn (TicketDepartment $record): bool => $record->canPollMail())
                ->action(function (TicketDepartment $record): void {
                    $result = app(ImapMailbox::class)->test($record);

                    $notification = Notification::make()
                        ->title($result->ok ? 'IMAP OK' : 'IMAP απέτυχε')
                        ->body($result->message);

                    ($result->ok ? $notification->success() : $notification->danger())->send();
                }),
            DeleteAction::make(),
        ];
    }
}
