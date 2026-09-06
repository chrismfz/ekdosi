<?php

namespace App\Filament\Resources\TicketDepartments\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TicketDepartmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Στοιχεία τμήματος')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Όνομα')
                            ->required()
                            ->maxLength(120),
                        TextInput::make('email')
                            ->label('Email τμήματος')
                            ->email()
                            ->maxLength(191)
                            ->helperText('Η διεύθυνση (π.χ. support@…) που αργότερα (Phase 3) εντοπίζει εισερχόμενα και στέλνει εξερχόμενα. Κενό = χωρίς mailbox.'),
                        Select::make('agents')
                            ->label('Χειριστές')
                            ->relationship('agents', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->columnSpanFull()
                            ->helperText('Ποιοι χειριστές ανήκουν/παρακολουθούν το τμήμα.'),
                    ]),

                Section::make('Ρυθμίσεις')
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Ενεργό')
                            ->default(true),
                        Toggle::make('is_hidden')
                            ->label('Κρυφό (εκτός πύλης πελάτη)')
                            ->helperText('Δεν προσφέρεται στον πελάτη όταν ανοίγει αίτημα.'),
                        Toggle::make('clients_only')
                            ->label('Μόνο πελάτες')
                            ->helperText('Δέχεται αίτημα/απάντηση μόνο από καταχωρημένο πελάτη (αλλιώς GUEST).'),
                        Toggle::make('autoresponder')
                            ->label('Αυτόματη απάντηση')
                            ->default(true),
                        Toggle::make('feedback_on_close')
                            ->label('Αίτημα αξιολόγησης στο κλείσιμο'),
                        Toggle::make('prevent_client_closure')
                            ->label('Να μην κλείνει ο πελάτης το αίτημα'),
                        TextInput::make('sort')
                            ->label('Σειρά')
                            ->numeric()
                            ->default(0),
                    ]),

                Section::make('Mailbox (IMAP) — για αργότερα')
                    ->description('Άντληση εισερχόμενων email του τμήματος (Phase 3). Δεν χρησιμοποιείται ακόμη — μπορείς να το συμπληρώσεις εκ των προτέρων.')
                    ->columns(2)
                    ->collapsed()
                    ->schema([
                        TextInput::make('imap_host')
                            ->label('IMAP host')
                            ->maxLength(191),
                        TextInput::make('imap_port')
                            ->label('Port')
                            ->numeric()
                            ->default(993),
                        TextInput::make('imap_username')
                            ->label('Username')
                            ->maxLength(191),
                        TextInput::make('imap_password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->maxLength(191)
                            ->dehydrated(fn (?string $state) => filled($state))
                            ->dehydrateStateUsing(fn (string $state) => $state)
                            ->helperText('Encrypted at rest. Κενό = διατήρηση υπάρχοντος.'),
                        Select::make('imap_encryption')
                            ->label('Κρυπτογράφηση')
                            ->options(['ssl' => 'SSL', 'tls' => 'TLS', 'none' => 'Καμία'])
                            ->default('ssl'),
                        TextInput::make('imap_folder')
                            ->label('Φάκελος')
                            ->default('INBOX')
                            ->maxLength(191),
                    ]),
            ]);
    }
}
