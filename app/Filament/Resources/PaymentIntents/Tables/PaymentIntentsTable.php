<?php

namespace App\Filament\Resources\PaymentIntents\Tables;

use App\Models\Payment;
use App\Models\PaymentIntent;
use App\Models\PaymentMethod;
use App\Services\Payments\PaymentGatewayRegistry;
use App\Services\Payments\PaymentIntentService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class PaymentIntentsTable
{
    public static function configure(Table $table): Table
    {
        $registry = app(PaymentGatewayRegistry::class);

        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Ημ/νία')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('reference')->label('Αναφορά')->searchable()->copyable(),
                TextColumn::make('customer.name')->label('Πελάτης')->searchable(),
                TextColumn::make('gateway')->label('Τρόπος')->badge()
                    ->formatStateUsing(fn (string $state): string => $registry->label($state)),
                TextColumn::make('amount')->label('Ποσό')->alignRight()
                    ->formatStateUsing(fn ($state): string => Money::eur($state)),
                TextColumn::make('status')->label('Κατάσταση')->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        PaymentIntent::STATUS_PENDING => 'Εκκρεμεί',
                        PaymentIntent::STATUS_SETTLED => 'Καταχωρίστηκε',
                        PaymentIntent::STATUS_CANCELLED => 'Ακυρώθηκε',
                        PaymentIntent::STATUS_EXPIRED => 'Έληξε',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        PaymentIntent::STATUS_SETTLED => 'success',
                        PaymentIntent::STATUS_PENDING => 'warning',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->label('Κατάσταση')->options([
                    PaymentIntent::STATUS_PENDING => 'Εκκρεμεί',
                    PaymentIntent::STATUS_SETTLED => 'Καταχωρίστηκε',
                    PaymentIntent::STATUS_CANCELLED => 'Ακυρώθηκε',
                    PaymentIntent::STATUS_EXPIRED => 'Έληξε',
                ])->default(PaymentIntent::STATUS_PENDING),
            ])
            ->recordActions([
                Action::make('settle')
                    ->label('Καταχώριση πληρωμής')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    // Writing money requires the payment-create right — not just
                    // read access to this list. Hard-guarded in the body too
                    // (mountAction does NOT re-check visible()).
                    ->visible(fn (PaymentIntent $record): bool => $record->isPending() && Gate::allows('create', Payment::class))
                    ->schema([
                        TextInput::make('actual_amount')
                            ->label('Ποσό που εισπράχθηκε (€)')
                            ->numeric()
                            ->minValue(0.01)
                            ->required()
                            ->default(fn (PaymentIntent $record) => (float) $record->amount)
                            ->helperText('Το πραγματικό ποσό που έφτασε — μπορεί να διαφέρει από το ζητούμενο.'),
                        Select::make('payment_method_id')
                            ->label('Τρόπος πληρωμής (myDATA)')
                            ->options(fn () => PaymentMethod::query()->pluck('description', 'id'))
                            ->native(false)
                            ->placeholder('— προαιρετικό —'),
                    ])
                    ->action(function (array $data, PaymentIntent $record): void {
                        abort_unless(Gate::allows('create', Payment::class), 403);
                        app(PaymentIntentService::class)->settle(
                            $record,
                            settledBy: (string) (auth()->user()?->email ?? 'operator'),
                            actualAmount: (float) $data['actual_amount'],
                            paymentMethodId: filled($data['payment_method_id'] ?? null) ? (int) $data['payment_method_id'] : null,
                        );
                        Notification::make()->title("Καταχωρίστηκε η πληρωμή {$record->reference}")->success()->send();
                    }),
                Action::make('cancel')
                    ->label('Ακύρωση')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (PaymentIntent $record): bool => $record->isPending() && Gate::allows('create', Payment::class))
                    ->requiresConfirmation()
                    ->action(function (PaymentIntent $record): void {
                        abort_unless(Gate::allows('create', Payment::class), 403);
                        // Guarded pending→cancelled (locked) so it can't race/overwrite a settle.
                        app(PaymentIntentService::class)->cancel($record);
                        Notification::make()->title("Ακυρώθηκε η εκκρεμότητα {$record->reference}")->success()->send();
                    }),
            ]);
    }
}
