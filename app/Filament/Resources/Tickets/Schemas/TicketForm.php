<?php

namespace App\Filament\Resources\Tickets\Schemas;

use App\Enums\TicketPriority;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * The «νέο αίτημα» form. It collects the ticket header PLUS the first message
 * `body` (a non-model field) — CreateTicket hands the lot to OpenTicket, which
 * allocates the reference and posts the opening message atomically.
 */
class TicketForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Νέο αίτημα')
                    ->columns(2)
                    ->schema([
                        Select::make('ticket_department_id')
                            ->label('Τμήμα')
                            ->relationship('department', 'name')
                            ->searchable()
                            ->preload()
                            ->native(false),
                        Select::make('customer_id')
                            ->label('Πελάτης')
                            ->relationship('customer', 'name')
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->live()
                            ->placeholder('— GUEST (χωρίς λογαριασμό) —')
                            ->helperText('Κενό = GUEST· συμπλήρωσε τα στοιχεία αιτούντα κάτω.'),
                        TextInput::make('requester_name')
                            ->label('Όνομα αιτούντα (GUEST)')
                            ->maxLength(191)
                            ->visible(fn (Get $get): bool => blank($get('customer_id'))),
                        TextInput::make('requester_email')
                            ->label('Email αιτούντα (GUEST)')
                            ->email()
                            ->maxLength(191)
                            ->visible(fn (Get $get): bool => blank($get('customer_id')))
                            // A GUEST ticket needs SOME identity — without a customer, the
                            // email is how the requester is reached (Phase 3) and identified.
                            ->required(fn (Get $get): bool => blank($get('customer_id'))),
                        TextInput::make('subject')
                            ->label('Θέμα')
                            ->required()
                            ->maxLength(191)
                            ->columnSpanFull(),
                        Select::make('priority')
                            ->label('Προτεραιότητα')
                            ->options(TicketPriority::options())
                            ->default(TicketPriority::Normal->value)
                            ->required(),
                    ]),

                Section::make('Μήνυμα')
                    ->schema([
                        Textarea::make('body')
                            ->label('Περιγραφή')
                            ->required()
                            ->rows(6)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
