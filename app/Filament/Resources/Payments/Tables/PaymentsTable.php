<?php

namespace App\Filament\Resources\Payments\Tables;

use App\Enums\PaymentStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('pay_date')
                    ->label('Ημ/νία')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('customer.name')
                    ->label('Πελάτης')
                    ->searchable()
                    ->limit(35),

                TextColumn::make('invoice.invcode')
                    ->label('Παραστατικό')
                    ->placeholder('Έναντι λογαριασμού'),

                TextColumn::make('paymentMethod.description')
                    ->label('Τρόπος')
                    ->placeholder('—'),

                TextColumn::make('amount')
                    ->label('Ποσό')
                    ->money('EUR')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('invoice.payment_status')
                    ->label('Κατάσταση παρ/κού')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state) => $state ? PaymentStatus::from($state)->label() : '—')
                    ->color(fn (?string $state) => $state ? PaymentStatus::from($state)->color() : 'gray'),

                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
                SelectFilter::make('payment_method_id')
                    ->label('Τρόπος')
                    ->relationship('paymentMethod', 'description'),
                TernaryFilter::make('invoice_id')
                    ->label('Έναντι λογαριασμού')
                    ->placeholder('Όλες')
                    ->trueLabel('Μόνο έναντι λογαριασμού')
                    ->falseLabel('Μόνο σε παραστατικό')
                    ->queries(
                        true: fn ($q) => $q->whereNull('invoice_id'),
                        false: fn ($q) => $q->whereNotNull('invoice_id'),
                        blank: fn ($q) => $q,
                    ),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('pay_date', 'desc');
    }
}
