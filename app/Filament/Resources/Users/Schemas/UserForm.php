<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
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

                        // Bind the toggle DIRECTLY to the `email_verified_at`
                        // timestamp: display the stored timestamp as a bool, and on
                        // save dehydrate the bool back to a timestamp|null. (The old
                        // proxy toggle + hidden DateTimePicker never persisted — a
                        // hidden field is NOT dehydrated by default in Filament v5
                        // (isDehydratedWhenHidden defaults to false), so both create
                        // and edit silently kept the user «unverified» despite the
                        // «Αποθηκεύτηκε» toast.)
                        Toggle::make('email_verified_at')
                            ->label('Email verified')
                            ->default(true)
                            ->formatStateUsing(fn ($state): bool => filled($state))
                            // Preserve the existing verified-at when already verified,
                            // so an unrelated edit+save doesn't bump the timestamp.
                            ->dehydrateStateUsing(fn ($state, ?User $record) => $state
                                ? ($record?->email_verified_at ?? now())
                                : null),
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
