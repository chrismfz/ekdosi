<?php

namespace App\Filament\Resources\PaymentGatewayEvents\Tables;

use App\Models\PaymentGatewayEvent;
use App\Services\Payments\PaymentGatewayRegistry;
use App\Support\Money;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PaymentGatewayEventsTable
{
    /** Reject/ignore reason code → operator-facing Greek. */
    private const REASONS = [
        'intent_not_found' => 'Άγνωστη παραγγελία (orderid)',
        'connection_or_gateway_missing' => 'Λείπει ο τρόπος/gateway',
        'digest_verification_failed' => 'Απέτυχε ο έλεγχος υπογραφής (digest)',
        'reference_mismatch' => 'Αναντιστοιχία orderid',
        'company_mismatch' => 'Αναντιστοιχία εταιρίας',
        'amount_mismatch' => 'Αναντιστοιχία ποσού',
        'currency_mismatch' => 'Αναντιστοιχία νομίσματος',
        'not_captured' => 'Δεν ολοκληρώθηκε (μη CAPTURED)',
    ];

    public static function configure(Table $table): Table
    {
        $registry = app(PaymentGatewayRegistry::class);

        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Ημ/νία')->dateTime('d/m/Y H:i:s')->sortable(),
                // Cross-tenant view (super-admin) → name the company; «—» for an
                // unattributable return (unknown/forged orderid, no intent).
                TextColumn::make('company.name')->label('Εταιρία')->placeholder('— (άγνωστη)')->toggleable(),
                TextColumn::make('order_id')->label('ID')->searchable()->copyable()
                    ->tooltip('Κωδικός παραγγελίας (orderid) στο vPOS = «Ekdosi #ID».'),
                TextColumn::make('gateway')->label('Πάροχος')->badge()
                    ->formatStateUsing(fn (string $state): string => $registry->label($state)),
                TextColumn::make('outcome')->label('Έκβαση')->badge()
                    ->formatStateUsing(fn (string $state): string => PaymentGatewayEvent::outcomeLabel($state))
                    ->color(fn (string $state): string => match ($state) {
                        PaymentGatewayEvent::OUTCOME_SETTLED => 'success',
                        PaymentGatewayEvent::OUTCOME_REJECTED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('reason')->label('Λόγος')->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => $state ? (self::REASONS[$state] ?? $state) : '—')
                    ->wrap(),
                IconColumn::make('verified')->label('Υπογραφή')->boolean(),
                TextColumn::make('provider_status')->label('Κατάσταση')->placeholder('—')->badge(),
                TextColumn::make('transaction_id')->label('Κωδ. συναλλαγής')->placeholder('—')->copyable()->searchable(),
                TextColumn::make('amount')->label('Ποσό')->alignEnd()->placeholder('—')
                    ->formatStateUsing(fn ($state): string => $state !== null ? Money::eur($state) : '—'),
                TextColumn::make('ip')->label('IP')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('outcome')->label('Έκβαση')->options([
                    PaymentGatewayEvent::OUTCOME_SETTLED => 'Καταχωρίστηκε',
                    PaymentGatewayEvent::OUTCOME_IGNORED => 'Αγνοήθηκε',
                    PaymentGatewayEvent::OUTCOME_REJECTED => 'Απορρίφθηκε',
                ]),
                SelectFilter::make('gateway')->label('Πάροχος')
                    ->options(fn (): array => collect($registry->keys())
                        ->mapWithKeys(fn (string $k): array => [$k => $registry->label($k)])
                        ->all()),
            ]);
    }
}
