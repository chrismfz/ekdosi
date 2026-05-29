<?php

namespace App\Filament\Resources\Customers\Tables;

use App\Filament\Resources\Customers\CustomerResource;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Attach the `outstanding_balance` alias (+ its cust_owed /
            // cust_paid join sub-selects) so the "Υπόλοιπο" column + the
            // "Με υπόλοιπο" filter below can read it. Computed in SQL,
            // identical math to the dashboard headline. Always applied so
            // the filter's whereRaw can reference the join aliases even
            // when the column is toggled off.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withOutstandingBalance(Filament::getTenant()?->getKey() ?? 0))
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
                    // Truncate long addresses so they don't widen the row;
                    // full value stays available on hover + via copy.
                    ->limit(30)
                    ->tooltip(fn ($state): ?string => $state)
                    ->toggleable(),

                TextColumn::make('phone1')
                    ->label('Phone')
                    ->copyable()
                    ->toggleable(),

                TextColumn::make('outstanding_balance')
                    ->label('Υπόλοιπο')
                    ->money('EUR')
                    ->alignRight()
                    ->sortable()
                    ->weight('bold')
                    // Red when they owe, muted otherwise.
                    ->color(fn ($state): ?string => (float) $state > 0.005 ? 'danger' : 'gray')
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

                // T-1b (#6): flags customers who route some of their WHMCS
                // invoices to third parties ("Παραστατικά σε τρίτους"). A
                // heads-up that this customer's invoices may not all be
                // theirs. Maintained by `whmcs:sync-resellers`.
                TextColumn::make('whmcs_reseller_routes')
                    ->label('Σε τρίτους')
                    ->badge()
                    ->color('warning')
                    ->icon('heroicon-o-users')
                    ->placeholder('—')
                    ->state(fn ($record): ?string => ($record->whmcs_reseller_routes ?? 0) > 0
                        ? $record->whmcs_reseller_routes.' υπηρ.'
                        : null)
                    ->tooltip('Δρομολογεί παραστατικά σε τρίτους — έλεγξε ότι τα τιμολόγια είναι όντως δικά του.')
                    ->toggleable(),

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

                // "Με υπόλοιπο" — the target of the dashboard's
                // "Ανεξόφλητα (πιστωτικά)" card. Reads the join aliases
                // attached by modifyQueryUsing() above.
                TernaryFilter::make('with_balance')
                    ->label('Υπόλοιπο')
                    ->placeholder('Όλοι')
                    ->trueLabel('Μόνο με υπόλοιπο')
                    ->falseLabel('Χωρίς υπόλοιπο')
                    ->queries(
                        true: fn (Builder $query): Builder => $query
                            ->whereRaw('(COALESCE(cust_owed.owed, 0) - COALESCE(cust_paid.paid, 0)) > 0.005'),
                        false: fn (Builder $query): Builder => $query
                            ->whereRaw('(COALESCE(cust_owed.owed, 0) - COALESCE(cust_paid.paid, 0)) <= 0.005'),
                        blank: fn (Builder $query): Builder => $query,
                    ),

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
            ->recordUrl(fn ($record) => CustomerResource::getUrl('ledger', ['record' => $record]))
            ->defaultSort('name');
    }
}
