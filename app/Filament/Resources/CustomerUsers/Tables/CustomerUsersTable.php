<?php

namespace App\Filament\Resources\CustomerUsers\Tables;

use App\Models\CustomerUser;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class CustomerUsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Όνομα')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Κατάσταση')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        CustomerUser::STATUS_ACTIVE => 'Ενεργός',
                        CustomerUser::STATUS_SUSPENDED => 'Σε αναστολή',
                        default => 'Πρόσκληση',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        CustomerUser::STATUS_ACTIVE => 'success',
                        CustomerUser::STATUS_SUSPENDED => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('last_login_at')
                    ->label('Τελευταία είσοδος')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Δημιουργήθηκε')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Κατάσταση')
                    ->options([
                        CustomerUser::STATUS_INVITED => 'Πρόσκληση',
                        CustomerUser::STATUS_ACTIVE => 'Ενεργός',
                        CustomerUser::STATUS_SUSPENDED => 'Σε αναστολή',
                    ]),
                // Surfaces soft-deleted logins so an operator can restore one
                // instead of hitting a dead-end «email taken» on a hidden row.
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('activate')
                    ->label('Ενεργοποίηση')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (CustomerUser $record): bool => $record->status !== CustomerUser::STATUS_ACTIVE)
                    ->requiresConfirmation()
                    ->action(function (CustomerUser $record): void {
                        $record->forceFill(['status' => CustomerUser::STATUS_ACTIVE])->save();
                        Notification::make()->title("Ενεργοποιήθηκε: {$record->email}")->success()->send();
                    }),
                Action::make('suspend')
                    ->label('Αναστολή')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (CustomerUser $record): bool => $record->status === CustomerUser::STATUS_ACTIVE)
                    ->requiresConfirmation()
                    ->action(function (CustomerUser $record): void {
                        $record->forceFill(['status' => CustomerUser::STATUS_SUSPENDED])->save();
                        Notification::make()->title("Σε αναστολή: {$record->email}")->success()->send();
                    }),
                Action::make('reset_password')
                    ->label('Ορισμός/επαναφορά κωδικού')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->schema([
                        TextInput::make('new_password')
                            ->label('Νέος κωδικός')
                            ->password()
                            ->revealable()
                            ->required()
                            // Same policy the customer's own change enforces
                            // (ProfileController), so an operator can't set a
                            // weaker password than the customer is allowed to.
                            ->rule(Password::defaults()),
                    ])
                    ->action(function (array $data, CustomerUser $record): void {
                        $record->forceFill([
                            'password' => Hash::make($data['new_password']),
                            'password_changed_at' => now(),
                        ])->save();
                        Notification::make()->title("Ορίστηκε κωδικός για {$record->email}")->success()->send();
                    }),
                // Only shown on trashed rows (via the TrashedFilter above).
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ]);
    }
}
