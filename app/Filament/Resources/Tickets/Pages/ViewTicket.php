<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Actions\Support\PostTicketMessage;
use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewTicket extends ViewRecord
{
    protected static string $resource = TicketResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reply')
                ->label('Απάντηση')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('primary')
                ->schema([
                    Textarea::make('body')->label('Απάντηση προς τον πελάτη')->required()->rows(5),
                ])
                ->action(function (array $data, Ticket $record): void {
                    app(PostTicketMessage::class)->handle($record, [
                        'author_role' => TicketMessage::ROLE_OPERATOR,
                        'author_id' => auth()->id(),
                        'via' => TicketMessage::VIA_OPERATOR,
                        'body' => $data['body'],
                    ]);
                    Notification::make()->title('Η απάντηση καταχωρήθηκε')->success()->send();
                }),

            Action::make('note')
                ->label('Εσωτερική σημείωση')
                ->icon('heroicon-o-lock-closed')
                ->color('warning')
                ->schema([
                    Textarea::make('body')->label('Σημείωση (μόνο για χειριστές)')->required()->rows(4),
                ])
                ->action(function (array $data, Ticket $record): void {
                    app(PostTicketMessage::class)->handle($record, [
                        'author_role' => TicketMessage::ROLE_OPERATOR,
                        'author_id' => auth()->id(),
                        'via' => TicketMessage::VIA_OPERATOR,
                        'is_internal_note' => true,
                        'body' => $data['body'],
                    ]);
                    Notification::make()->title('Η σημείωση καταχωρήθηκε')->success()->send();
                }),

            Action::make('assign')
                ->label('Ανάθεση')
                ->icon('heroicon-o-user')
                ->color('gray')
                ->schema([
                    Select::make('assigned_to')
                        ->label('Χειριστής')
                        ->options(fn (): array => self::operatorOptions())
                        ->default(fn (Ticket $record): ?int => $record->assigned_to ?? auth()->id())
                        ->searchable()
                        ->placeholder('— χωρίς ανάθεση —'),
                ])
                ->action(function (array $data, Ticket $record): void {
                    $record->update(['assigned_to' => $data['assigned_to'] ?: null]);
                    Notification::make()->title('Η ανάθεση ενημερώθηκε')->success()->send();
                }),

            Action::make('hold')
                ->label('Σε αναμονή')
                ->icon('heroicon-o-pause-circle')
                ->color('gray')
                ->visible(fn (Ticket $record): bool => ! in_array($record->status, [TicketStatus::Closed, TicketStatus::OnHold], true))
                ->action(function (Ticket $record): void {
                    $record->update(['status' => TicketStatus::OnHold]);
                    Notification::make()->title('Το αίτημα μπήκε σε αναμονή')->success()->send();
                }),

            Action::make('close')
                ->label('Κλείσιμο')
                ->icon('heroicon-o-check-circle')
                ->color('gray')
                ->visible(fn (Ticket $record): bool => $record->status !== TicketStatus::Closed)
                ->requiresConfirmation()
                ->action(function (Ticket $record): void {
                    $record->update(['status' => TicketStatus::Closed, 'closed_at' => now()]);
                    Notification::make()->title('Το αίτημα έκλεισε')->success()->send();
                }),

            Action::make('reopen')
                ->label('Επαναφορά')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (Ticket $record): bool => $record->status === TicketStatus::Closed)
                ->action(function (Ticket $record): void {
                    $record->update(['status' => TicketStatus::Open, 'closed_at' => null]);
                    Notification::make()->title('Το αίτημα άνοιξε ξανά')->success()->send();
                }),
        ];
    }

    /** @return array<int, string> */
    private static function operatorOptions(): array
    {
        return Filament::getTenant()?->users()->orderBy('name')->pluck('users.name', 'users.id')->all() ?? [];
    }
}
