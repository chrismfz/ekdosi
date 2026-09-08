<?php

namespace App\Filament\Resources\CustomerUsers\Schemas;

use App\Models\CustomerUser;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

class CustomerUserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Όνομα')
                            ->required()
                            ->maxLength(191),
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(191)
                            ->helperText('Το αναγνωριστικό εισόδου (μοναδικό σε όλες τις εταιρίες).'),
                        TextInput::make('phone')
                            ->label('Τηλέφωνο')
                            ->maxLength(40),
                        Select::make('locale')
                            ->label('Γλώσσα')
                            ->native(false)
                            ->options(['el' => 'Ελληνικά', 'en' => 'English']),
                        Select::make('status')
                            ->label('Κατάσταση')
                            ->required()
                            ->native(false)
                            ->default(CustomerUser::STATUS_INVITED)
                            ->options([
                                CustomerUser::STATUS_INVITED => 'Πρόσκληση (χωρίς κωδικό)',
                                CustomerUser::STATUS_ACTIVE => 'Ενεργός',
                                CustomerUser::STATUS_SUSPENDED => 'Σε αναστολή',
                            ])
                            ->helperText('Μόνο «Ενεργός» + κωδικός μπορεί να συνδεθεί.'),
                    ]),

                Section::make('Κωδικός')
                    ->schema([
                        TextInput::make('password')
                            ->label('Κωδικός')
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            // Optional: an invited login has no password (the
                            // customer sets it via reset). Fill it only for an
                            // immediate set/reset. The model's `hashed` cast hashes
                            // it — we pass plaintext (no double-hash), and skip the
                            // column entirely when left blank.
                            ->rules(fn (?string $state): array => filled($state) ? [Password::defaults()] : [])
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText('Άφησέ το κενό για πρόσκληση (ο πελάτης θα ορίσει κωδικό). Συμπλήρωσέ το για άμεσο set/reset.'),
                    ]),
            ]);
    }
}
