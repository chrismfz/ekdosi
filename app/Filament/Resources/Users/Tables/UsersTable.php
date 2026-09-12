<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Users\Actions\ResetTwoFactorAction;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('email_verified_at')
                    ->label('Verified')
                    ->boolean()
                    ->sortable(),
                // 2FA at a glance: green check = TOTP enrolled, red × = off.
                // Not a real column — derived from the secret's presence. The
                // MaybeEncrypted secret is non-null whenever enrolled (encrypted
                // or plaintext), so the NULL check is correct either way.
                IconColumn::make('two_factor')
                    ->label('2FA')
                    ->state(fn (User $record): bool => filled($record->app_authentication_secret))
                    ->boolean()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw(
                        '(app_authentication_secret IS NOT NULL) '.($direction === 'desc' ? 'desc' : 'asc')
                    ))
                    ->tooltip(fn (User $record): string => filled($record->app_authentication_secret) ? 'Ενεργό' : 'Ανενεργό'),
                TextColumn::make('companies_count')
                    ->label('Tenants')
                    ->counts('companies')
                    ->alignRight()
                    ->numeric(),
                // A user in no company gets 403 at the panel door — surface the
                // orphan here so an admin spots it before the user is locked out.
                TextColumn::make('no_company')
                    ->label('')
                    ->state(fn ($record): ?string => ($record->companies_count ?? 0) === 0 ? 'χωρίς εταιρεία' : null)
                    ->badge()
                    ->color('danger')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->placeholder(''),
                TextColumn::make('roles_count')
                    ->label('Roles')
                    ->counts('roles')
                    ->alignRight()
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->label('Last update')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('email_verified_at')
                    ->label('Verified')
                    ->nullable(),
                TernaryFilter::make('two_factor')
                    ->label('2FA')
                    ->placeholder('Όλοι')
                    ->trueLabel('Με 2FA')
                    ->falseLabel('Χωρίς 2FA')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('app_authentication_secret'),
                        false: fn (Builder $query): Builder => $query->whereNull('app_authentication_secret'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                Filter::make('no_company')
                    ->label('Χωρίς εταιρεία (θα παίρνει 403)')
                    ->query(fn ($query) => $query->whereDoesntHave('companies')),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('reset_password')
                    ->label('Reset password')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->schema([
                        TextInput::make('new_password')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(8)
                            ->helperText('Will be hashed on save.'),
                    ])
                    ->action(function (array $data, $record): void {
                        $record->forceFill([
                            'password' => Hash::make($data['new_password']),
                        ])->save();

                        Notification::make()
                            ->title("Password reset for {$record->email}")
                            ->success()
                            ->send();
                    }),
                // Recovery path for a lost authenticator (shared with the
                // Edit-user page header) — see ResetTwoFactorAction.
                ResetTwoFactorAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }
}
