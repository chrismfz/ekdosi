<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

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
                            // SEC-2: enforce the app-wide password policy
                            // (Password::defaults() — min 8 + breach check). Gate on
                            // a FILLED value so a blank edit (= keep current password)
                            // isn't wrongly rejected; on CREATE the field is required
                            // so the policy always fires.
                            ->rules(fn (?string $state): array => filled($state) ? [Password::defaults()] : [])
                            // Hash on save. Skip the column when the field is empty
                            // (edit page: blank password input = "don't change").
                            ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->helperText(fn (string $operation): string => $operation === 'edit'
                                ? 'Leave blank to keep the current password (min 8 if changing).'
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

                // Read-only 2FA status. Enabling is inherently self-service
                // (the user scans the QR on THEIR OWN profile), so there is no
                // «enable» button here — only the status + the «Επαναφορά 2FA»
                // header action to disable/reset. Edit-only (no record on create).
                Section::make('Two-factor authentication (2FA)')
                    ->visibleOn('edit')
                    ->schema([
                        TextEntry::make('two_factor_status')
                            ->label('Κατάσταση')
                            ->state(fn (?User $record): string => filled($record?->app_authentication_secret) ? 'Ενεργό' : 'Ανενεργό')
                            ->badge()
                            ->color(fn (?User $record): string => filled($record?->app_authentication_secret) ? 'success' : 'gray')
                            ->icon(fn (?User $record): string => filled($record?->app_authentication_secret)
                                ? 'heroicon-o-shield-check'
                                : 'heroicon-o-shield-exclamation'),
                        TextEntry::make('two_factor_help')
                            ->hiddenLabel()
                            ->state('Η ενεργοποίηση γίνεται από τον ίδιο τον χρήστη στο προφίλ του (σάρωση QR). '
                                .'Για απενεργοποίηση/επαναφορά, χρησιμοποίησε το «Επαναφορά 2FA» πάνω δεξιά.')
                            ->color('gray'),
                    ]),
            ]);
    }
}
