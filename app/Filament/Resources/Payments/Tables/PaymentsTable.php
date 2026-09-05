<?php

namespace App\Filament\Resources\Payments\Tables;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\Payments\PaymentGatewayRegistry;
use App\Support\Money;
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
            // Eager-load the intent (id+gateway only) so the «Κανάλι» column doesn't N+1.
            ->modifyQueryUsing(fn ($query) => $query->with('paymentIntent:id,gateway'))
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

                // Κανάλι/προέλευση: came AUTOMATICALLY via a portal gateway (linked to
                // an intent → «Πύλη · Eurobank») vs entered by the operator/import
                // («Χειροκίνητα»). Distinct from «Τρόπος» (the myDATA method).
                TextColumn::make('payment_channel')
                    ->label('Κανάλι')
                    ->badge()
                    ->state(function (Payment $record): string {
                        if ($record->payment_intent_id === null) {
                            return 'Χειροκίνητα';
                        }
                        $gateway = (string) $record->paymentIntent?->gateway;

                        return $gateway !== ''
                            ? 'Πύλη · '.app(PaymentGatewayRegistry::class)->label($gateway)
                            : 'Πύλη';   // intent purged/soft-deleted — still «Πύλη», no stray «· —»
                    })
                    ->color(fn (Payment $record): string => $record->payment_intent_id !== null ? 'info' : 'gray'),

                // A refund IS a Payment row (kind='refund'); mark it so it doesn't
                // read as an incoming payment.
                TextColumn::make('kind')
                    ->label('Τύπος')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Payment::kindLabel($state))
                    ->color(fn (?string $state): string => $state === 'refund' ? 'warning' : 'success'),

                TextColumn::make('amount')
                    ->label('Ποσό')
                    ->alignEnd()
                    ->sortable()
                    // Show a refund as NEGATIVE (money OUT) so the sign matches the
                    // Καρτέλα and it can't be mistaken for an incoming payment.
                    ->color(fn (Payment $record): ?string => $record->isRefund() ? 'danger' : null)
                    ->formatStateUsing(fn ($state, Payment $record): string => Money::eur(
                        ($record->isRefund() ? -1 : 1) * (float) $state,
                    )),

                TextColumn::make('invoice.payment_status')
                    ->label('Κατάσταση παρ/κού')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state) => $state ? PaymentStatus::from($state)->label() : '—')
                    ->color(fn (?string $state) => $state ? PaymentStatus::from($state)->color() : 'gray'),

                // Acquirer transaction id («ID Συναλλαγής» in the bank's mail) —
                // searchable + copyable so «βρες την πληρωμή με ID 320…» is one search.
                TextColumn::make('transaction_id')
                    ->label('Κωδ. συναλλαγής')
                    ->placeholder('—')
                    ->copyable()
                    ->searchable()
                    ->toggleable(),

                // Our ΠΛ- receipt key that groups an είσπραξη's rows (and matches the
                // portal intent) — searchable, hidden by default to avoid clutter.
                TextColumn::make('reference')
                    ->label('Αναφορά')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

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
