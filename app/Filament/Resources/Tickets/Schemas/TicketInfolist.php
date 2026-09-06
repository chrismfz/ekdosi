<?php

namespace App\Filament\Resources\Tickets\Schemas;

use App\Models\Ticket;
use App\Models\TicketMessage;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The read-only ticket view: a header (status/priority/who/department) + the
 * message thread rendered with a RepeatableEntry (the repo's pattern for a
 * child-record list in an infolist). Internal notes are flagged and coloured so
 * an operator never mistakes one for a customer-visible reply. Replying and
 * status changes are the header actions on ViewTicket.
 */
class TicketInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Αίτημα')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('reference')->label('Κωδικός')->copyable()->weight('bold'),
                        TextEntry::make('status')->label('Κατάσταση')->badge(),
                        TextEntry::make('priority')->label('Προτεραιότητα')->badge(),
                        TextEntry::make('subject')->label('Θέμα')->columnSpanFull(),
                        TextEntry::make('requester')
                            ->label('Αιτών')
                            ->state(fn (Ticket $record): string => $record->requesterLabel().($record->isGuest() ? ' (GUEST)' : ''))
                            ->badge(fn (Ticket $record): bool => $record->isGuest())
                            ->color(fn (Ticket $record): string => $record->isGuest() ? 'gray' : 'info'),
                        TextEntry::make('department.name')->label('Τμήμα')->placeholder('—'),
                        TextEntry::make('assignee.name')->label('Χειριστής')->placeholder('— χωρίς ανάθεση —'),
                        TextEntry::make('created_at')->label('Ανοίχτηκε')->dateTime('d/m/Y H:i'),
                        TextEntry::make('last_reply_at')->label('Τελευταία απάντηση')->since()->placeholder('—'),
                    ]),

                Section::make('Συνομιλία')
                    ->schema([
                        RepeatableEntry::make('messages')
                            ->hiddenLabel()
                            ->columns(2)
                            ->schema([
                                TextEntry::make('author')
                                    ->hiddenLabel()
                                    ->badge()
                                    ->state(fn (TicketMessage $record): string => self::authorLabel($record))
                                    ->color(fn (TicketMessage $record): string => $record->is_internal_note
                                        ? 'warning'
                                        : ($record->isFromOperator() ? 'success' : 'info')),
                                TextEntry::make('created_at')
                                    ->hiddenLabel()
                                    ->since()
                                    ->color('gray')
                                    ->alignEnd(),
                                TextEntry::make('note_flag')
                                    ->hiddenLabel()
                                    ->state(fn (TicketMessage $record): ?string => $record->is_internal_note
                                        ? '🔒 Εσωτερική σημείωση — δεν τη βλέπει ο πελάτης'
                                        : null)
                                    ->color('warning')
                                    ->visible(fn (TicketMessage $record): bool => $record->is_internal_note)
                                    ->columnSpanFull(),
                                TextEntry::make('body')->hiddenLabel()->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }

    private static function authorLabel(TicketMessage $message): string
    {
        return match ($message->author_role) {
            TicketMessage::ROLE_OPERATOR => $message->author()?->name ?? 'Χειριστής',
            TicketMessage::ROLE_CUSTOMER => $message->author()?->name ?? 'Πελάτης',
            default => 'Σύστημα',
        };
    }
}
