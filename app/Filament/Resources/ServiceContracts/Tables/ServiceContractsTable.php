<?php

namespace App\Filament\Resources\ServiceContracts\Tables;

use App\Enums\BillingCycle;
use App\Enums\ServiceContractStatus;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ServiceContractsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['customer:id,name', 'product:id,description_short']))
            ->defaultSort('next_due_date')
            ->columns([
                TextColumn::make('customer.name')
                    ->label('Πελάτης')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('description')
                    ->label('Περιγραφή')
                    ->state(fn ($record) => $record->description ?: $record->product?->description_short ?: '—')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('billing_cycle')
                    ->label('Κύκλος')
                    ->badge()
                    ->formatStateUsing(fn (BillingCycle $state) => $state->label()),

                TextColumn::make('amount')
                    ->label('Ποσό')
                    ->money('EUR')
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Κατάσταση')
                    ->badge()
                    ->formatStateUsing(fn (ServiceContractStatus $state) => $state->label())
                    ->color(fn (ServiceContractStatus $state) => $state->color()),

                TextColumn::make('next_due_date')
                    ->label('Επόμενη χρέωση')
                    ->date('Y-m-d')
                    ->sortable(),

                TextColumn::make('end_date')
                    ->label('Λήξη')
                    ->date('Y-m-d')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Κατάσταση')
                    ->options(ServiceContractStatus::options()),

                SelectFilter::make('billing_cycle')
                    ->label('Κύκλος')
                    ->options(BillingCycle::options()),

                Filter::make('due_soon')
                    ->label('Λήγει σε…')
                    ->form([
                        Select::make('within_days')
                            ->label('Επόμενη χρέωση εντός')
                            ->options([
                                30 => '30 ημέρες',
                                60 => '60 ημέρες',
                                90 => '90 ημέρες',
                            ]),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $days = $data['within_days'] ?? null;
                        if (! $days) {
                            return $query;
                        }

                        return $query
                            ->whereNotNull('next_due_date')
                            ->whereDate('next_due_date', '<=', today()->addDays((int) $days));
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
