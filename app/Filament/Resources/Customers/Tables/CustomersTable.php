<?php

namespace App\Filament\Resources\Customers\Tables;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('afm')
                    ->label('AFM')
                    ->searchable()
                    ->copyable()
                    ->toggleable(),

                TextColumn::make('city')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('email')
                    ->searchable()
                    ->copyable()
                    ->toggleable(),

                TextColumn::make('phone1')
                    ->label('Phone')
                    ->copyable()
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                IconColumn::make('needs_immediate_invoice')
                    ->label('Immediate')
                    ->tooltip('γκρινιάρης — invoice immediately on payment')
                    ->boolean()
                    ->trueIcon('heroicon-o-bolt')
                    ->falseIcon('heroicon-o-clock')
                    ->toggleable(),

                TextColumn::make('discount')
                    ->suffix('%')
                    ->alignRight()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('whmcs_client_id')
                    ->label('WHMCS')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->default(true)
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only')
                    ->placeholder('All'),

                TernaryFilter::make('needs_immediate_invoice')
                    ->label('Immediate invoicing')
                    ->boolean()
                    ->trueLabel('γκρινιάρης only')
                    ->falseLabel('Batched only')
                    ->placeholder('All'),

                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),

                Action::make('toggle_active')
                    ->authorize('update')
                    ->label(fn ($record) => $record->is_active ? 'Deactivate' : 'Activate')
                    ->icon(fn ($record) => $record->is_active ? 'heroicon-o-no-symbol' : 'heroicon-o-check-circle')
                    ->color(fn ($record) => $record->is_active ? 'gray' : 'success')
                    ->visible(fn ($record) => ! $record->trashed())
                    ->requiresConfirmation()
                    ->action(function ($record): void {
                        $record->update(['is_active' => ! $record->is_active]);
                        Notification::make()
                            ->title(($record->is_active ? 'Activated: ' : 'Deactivated: ').$record->name)
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ])
            // Row click → Καρτέλα (the financial view). Matches Greek
            // accounting software convention (Singular / Atlantis /
            // Soft1) — operators primarily SEE customer accounts;
            // edits are rare. The explicit Edit action above stays as
            // the secondary path.
            ->recordUrl(fn ($record) => \App\Filament\Resources\Customers\CustomerResource::getUrl('ledger', ['record' => $record]))
            ->defaultSort('name');
    }
}
