<?php

namespace App\Filament\Resources\LeaveRequests\Schemas;

use App\Models\LeaveRequest;
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
        ]);
    }
}
