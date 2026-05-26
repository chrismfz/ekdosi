<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Email address')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                    ]),

                Section::make('Authentication')
                    ->columns(2)
                    ->schema([
                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            // Hash on save. Skip the column when the field is empty
                            // (edit page: blank password input = "don't change").
                            ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->helperText(fn (string $operation): string => $operation === 'edit'
                                ? 'Leave blank to keep the current password.'
                                : 'At least 8 characters.'),

                        Toggle::make('email_verified')
                            ->label('Email verified')
                            ->default(true)
                            ->dehydrated(false)
                            // Keep the toggle in sync with the underlying timestamp.
                            ->afterStateHydrated(fn (Toggle $component, $record) =>
                                $component->state(filled($record?->email_verified_at)))
                            ->live()
                            ->afterStateUpdated(function (bool $state, callable $set) {
                                $set('email_verified_at', $state ? now() : null);
                            }),

                        DateTimePicker::make('email_verified_at')
                            ->label('Verified at')
                            ->hidden(),
                    ]),
            ]);
    }
}
