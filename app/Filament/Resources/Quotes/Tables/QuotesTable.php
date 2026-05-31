<?php

namespace App\Filament\Resources\Quotes\Tables;

use App\Enums\QuoteStatus;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Actions\ViewAction;
use Filament\Tables\Table;

/**
 * Quotes list. Shows the dates the operator cares about: when we made it
 * (issued_at), when the offer expires (valid_until), and — when it's a
 * service — when the service expires (service_until), so renewals can be
 * tracked by eye until the recurring engine exists.
 */
class QuotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Κωδικός')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('subject')
                    ->label('Θέμα')
                    ->searchable()
                    ->limit(40)
                    ->wrap(),

                TextColumn::make('company_name')
                    ->label('Πελάτης')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('issued_at')
                    ->label('Ημ/νία')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('valid_until')
                    ->label('Ισχύει έως')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—')
                    // Red when the offer has lapsed and it's not closed yet.
                    ->color(fn ($state, $record) => $state
                        && $record->valid_until?->isPast()
                        && in_array($record->status, [QuoteStatus::Draft, QuoteStatus::Sent], true)
                        ? 'danger' : null),

                TextColumn::make('service_until')
                    ->label('Λήξη υπηρεσίας')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—')
                    ->color(fn ($state) => $state && $state->isPast() ? 'warning' : null)
                    ->toggleable(),

                TextColumn::make('gross_total')
                    ->label('Σύνολο')
                    ->money('EUR')
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Κατάσταση')
                    ->badge()
                    ->sortable(),

                IconColumn::make('converted_invoice_id')
                    ->label('Παραστατικό')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon('heroicon-o-minus')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->tooltip(fn ($record) => $record->converted_invoice_id
                        ? 'Έχει μετατραπεί σε παραστατικό' : 'Δεν έχει μετατραπεί'),

                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Κατάσταση')
                    ->options(collect(QuoteStatus::cases())
                        ->mapWithKeys(fn (QuoteStatus $s) => [$s->value => $s->getLabel()])
                        ->toArray()),

                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('issued_at', 'desc');
    }
}
