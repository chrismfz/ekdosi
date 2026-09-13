<?php

namespace App\Filament\Resources\InboundDeliveryNotes\Tables;

use App\Filament\Resources\InboundDeliveryNotes\InboundDeliveryNoteResource;
use App\Models\InboundDeliveryNote;
use App\Support\MyData\DeliveryCodes;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Slice 4b — «Εισερχόμενα Διακίνησης» list. Explicit tenant scope (the model
 * carries a CompanyScope for the panel, but scoping the query here too keeps the
 * inbox honest under any context). Rows link to the view page for the recipient
 * actions.
 */
class InboundDeliveryNotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                $tenant = Filament::getTenant();
                $query->where('company_id', $tenant?->getKey() ?? 0)
                    ->with('supplier:id,name');
            })
            ->defaultSort('issue_date', 'desc')
            ->columns([
                TextColumn::make('issuer_name')
                    ->label('Εκδότης')
                    ->searchable()
                    ->description(fn (InboundDeliveryNote $r): ?string => $r->issuer_afm)
                    ->placeholder('—'),

                TextColumn::make('invoice_type')
                    ->label('Τύπος')
                    ->badge()
                    ->placeholder('—'),

                TextColumn::make('aa')
                    ->label('ΑΑ')
                    ->placeholder('—'),

                TextColumn::make('issue_date')
                    ->label('Ημ/νία')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('aade_delivery_status')
                    ->label('Κατάσταση ΑΑΔΕ')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => DeliveryCodes::deliveryStatusLabel($state === null ? null : (int) $state) ?? '—')
                    ->color('gray'),

                TextColumn::make('local_state')
                    ->label('Διάθεση')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => InboundDeliveryNote::stateLabel($state) ?? '—')
                    ->color(fn ($state): string => match ($state) {
                        InboundDeliveryNote::STATE_NEW => 'warning',
                        InboundDeliveryNote::STATE_ACKNOWLEDGED => 'info',
                        InboundDeliveryNote::STATE_REJECTED, InboundDeliveryNote::STATE_CANCELLED_BY_ISSUER => 'danger',
                        InboundDeliveryNote::STATE_CONFIRMED => 'success',
                        default => 'gray',
                    }),

                TextColumn::make('mydata_mark')
                    ->label('MARK')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('last_fetched_at')
                    ->label('Τελευταία λήψη')
                    ->since()
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('local_state')
                    ->label('Διάθεση')
                    ->options(InboundDeliveryNote::STATE_LABELS),
            ])
            ->recordUrl(fn (InboundDeliveryNote $record): string => InboundDeliveryNoteResource::getUrl('view', ['record' => $record]));
    }
}
