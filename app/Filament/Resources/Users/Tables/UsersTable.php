<?php

namespace App\Filament\Resources\Users\Tables;

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
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }
}
