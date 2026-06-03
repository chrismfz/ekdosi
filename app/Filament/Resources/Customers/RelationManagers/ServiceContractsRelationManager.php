<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Enums\BillingCycle;
use App\Enums\ServiceContractStatus;
use App\Filament\Resources\ServiceContracts\ServiceContractResource;
use App\Models\ServiceContract;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * «Υπηρεσίες» — the recurring service contracts of one customer (the WHMCS
 * client-summary view). Read-mostly: an «Άνοιγμα» row action jumps to the full
 * ServiceContract view; creation/editing happens in the top-level Υπηρεσίες
 * resource (which has the product-prefill form), not here. Naturally tenant-safe
 * — these are the parent customer's own contracts, and the customer is already
 * scoped to the current Company.
 */
class ServiceContractsRelationManager extends RelationManager
{
    protected static string $relationship = 'serviceContracts';

    protected static ?string $title = 'Υπηρεσίες';

    protected static ?string $recordTitleAttribute = 'description';

    public function form(Schema $schema): Schema
    {
        // No inline form — contracts are created/edited via the resource.
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['product:id,description_short']))
            ->defaultSort('next_due_date')
            ->columns([
                TextColumn::make('description')
                    ->label('Περιγραφή')
                    ->state(fn (ServiceContract $record) => $record->description ?: $record->product?->description_short ?: '—')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('billing_cycle')
                    ->label('Κύκλος')
                    ->badge()
                    ->formatStateUsing(fn (BillingCycle $state) => $state->label()),

                TextColumn::make('status')
                    ->label('Κατάσταση')
                    ->badge()
                    ->formatStateUsing(fn (ServiceContractStatus $state) => $state->label())
                    ->color(fn (ServiceContractStatus $state) => $state->color()),

                TextColumn::make('quantity')
                    ->label('Ποσότητα')
                    ->numeric(decimalPlaces: 3)
                    ->alignEnd()
                    ->toggleable(),

                // Σύνολο = quantity × amount (net, per cycle).
                TextColumn::make('amount')
                    ->label('Σύνολο')
                    ->alignEnd()
                    ->money('EUR')
                    ->state(fn (ServiceContract $record) => round((float) $record->amount * (float) ($record->quantity ?: 1), 2)),

                TextColumn::make('next_due_date')
                    ->label('Επόμενη χρέωση')
                    ->date('d/m/Y')
                    ->placeholder('—'),

                TextColumn::make('end_date')
                    ->label('Λήξη')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->emptyStateHeading('Καμία υπηρεσία')
            ->emptyStateDescription('Ο πελάτης δεν έχει συμβόλαια υπηρεσιών.')
            ->emptyStateIcon('heroicon-o-arrow-path-rounded-square')
            ->recordActions([
                Action::make('open')
                    ->label('Άνοιγμα')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(fn (ServiceContract $record): string => ServiceContractResource::getUrl('view', ['record' => $record]))
                    ->openUrlInNewTab(),
            ]);
    }
}
