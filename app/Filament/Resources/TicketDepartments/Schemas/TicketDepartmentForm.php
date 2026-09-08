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
                            ->helperText('Η διεύθυνση (π.χ. support@…) του τμήματος: ο poller διαβάζει τα εισερχόμενα από εδώ (→ αιτήματα) και οι απαντήσεις προς τον πελάτη φεύγουν από αυτή. Κενό = χωρίς mailbox.'),
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
                        Toggle::make('feedback_on_close')
                            ->label('Αίτημα αξιολόγησης στο κλείσιμο')
                            ->helperText('Στο κλείσιμο, ο πελάτης παίρνει email με σύνδεσμο αξιολόγησης (1–5).'),
                        // «Αυτόματη απάντηση» + «Να μην κλείνει ο πελάτης το αίτημα» ΑΦΑΙΡΕΘΗΚΑΝ από τη
                        // φόρμα: οι στήλες υπάρχουν αλλά δεν τις διαβάζει καμία ροή ακόμη (θα παραπλανούσαν
                        // — π.χ. «Αυτόματη απάντηση» με default ON χωρίς να στέλνεται τίποτα). Θα ξαναμπούν
                        // όταν υλοποιηθεί η συμπεριφορά (docs/BACKLOG.md).
                        TextInput::make('sort')
                            ->label('Σειρά')
                            ->numeric()
                            ->default(0),
                    ]),

                Section::make('Mailbox (IMAP)')
                    ->description('Ρυθμίσεις IMAP για την άντληση εισερχόμενων email του τμήματος → αιτήματα. Μετά την αποθήκευση, έλεγξε τη σύνδεση με «Test σύνδεσης». Το αυτόματο poll τρέχει όταν είναι ενεργό κεντρικά στον scheduler (EKDOSI_SCHEDULE_TICKETS_POLL_IMAP)· αλλιώς χειροκίνητα με «tickets:poll-imap».')
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
