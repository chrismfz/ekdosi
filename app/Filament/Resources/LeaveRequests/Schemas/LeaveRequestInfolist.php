<?php

namespace App\Filament\Resources\LeaveRequests\Schemas;

use App\Filament\Resources\LeaveRequests\Tables\LeaveRequestsTable;
use App\Models\LeaveRequest;
use App\Policies\LeaveRequestPolicy;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LeaveRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Άδεια')
                ->columns(3)
                ->schema([
                    TextEntry::make('employee.full_name')->label('Εργαζόμενος'),
                    TextEntry::make('type')->label('Είδος')->badge(),
                    TextEntry::make('status')->label('Κατάσταση')->badge(),
                    TextEntry::make('period')->label('Διάστημα')->state(fn (LeaveRequest $record): string => $record->periodLabel()),
                    TextEntry::make('days')->label('Εργάσιμες ημέρες'),
                    TextEntry::make('requestedBy.name')->label('Υποβλήθηκε από')->placeholder('—'),
                    TextEntry::make('reason')->label('Σημείωση εργαζομένου')->placeholder('—')->columnSpanFull(),
                ]),
            Section::make('Απόφαση')
                ->columns(3)
                ->schema([
                    TextEntry::make('decidedBy.name')->label('Από')->placeholder('—'),
                    TextEntry::make('decided_at')->label('Πότε')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('accountant_notified_at')->label('Email λογιστή')->dateTime('d/m/Y H:i')->placeholder('Δεν στάλθηκε'),
                    TextEntry::make('decision_note')->label('Σημείωση')->placeholder('—')->columnSpanFull(),
                ]),
            Section::make('ΕΡΓΑΝΗ')
                ->visible(fn (LeaveRequest $record): bool => LeaveRequestPolicy::isApprover(auth()->user())
                    && ($record->ergani_status !== null || $record->erganiSubmissions()->exists()))
                ->columns(3)
                ->schema([
                    TextEntry::make('ergani_status')->label('Κατάσταση')->badge()
                        ->formatStateUsing(fn (?string $state, LeaveRequest $record): string => LeaveRequestsTable::erganiLabel($record)),
                    TextEntry::make('ergani_protocol')->label('Πρωτόκολλο')->placeholder('—')->copyable(),
                    TextEntry::make('ergani_submitted_at')->label('Υποβολή')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('ergani_error')->label('Σφάλμα')->placeholder('—')->color('danger')->columnSpanFull(),
                    RepeatableEntry::make('erganiSubmissions')
                        ->label('Ιστορικό κλήσεων')
                        ->columnSpanFull()
                        ->columns(5)
                        ->schema([
                            TextEntry::make('created_at')->label('Πότε')->dateTime('d/m/Y H:i:s'),
                            TextEntry::make('action')->label('Ενέργεια')
                                ->formatStateUsing(fn (string $state): string => $state === 'cancel' ? 'Ανάκληση' : 'Υποβολή'),
                            TextEntry::make('environment')->label('Περιβάλλον')
                                ->formatStateUsing(fn (string $state): string => $state === 'production' ? 'Παραγωγή' : 'Δοκιμαστικό'),
                            TextEntry::make('ok')->label('Αποτέλεσμα')->badge()
                                ->formatStateUsing(fn (bool $state): string => $state ? 'OK' : 'Σφάλμα')
                                ->color(fn (bool $state): string => $state ? 'success' : 'danger'),
                            TextEntry::make('protocol')->label('Πρωτ. / μήνυμα')
                                ->state(fn ($record): string => (string) ($record->protocol ?: $record->message ?: '—')),
                        ]),
                ]),
        ]);
    }
}
