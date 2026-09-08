<?php

namespace App\Filament\Resources\DomainTlds\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/**
 * The explicit per-year prices of one TLD (Πυλώνας A / A1) — the WHMCS «Domain
 * Pricing» modal as rows: operation × year × currency, cost/price, enable
 * toggle (the «-1 disables this term»). Manual entry; the Openprovider
 * cost-sync (A2) fills `cost` on synced registrars.
 */
class PricesRelationManager extends RelationManager
{
    protected static string $relationship = 'prices';

    protected static ?string $title = 'Τιμές';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('operation')
                ->label('Ενέργεια')
                ->options([
                    'register' => 'Καταχώρηση',
                    'transfer' => 'Μεταφορά',
                    'renewal' => 'Ανανέωση',
                    'restore' => 'Restore',
                    'redemption' => 'Redemption',
                ])
                ->required()
                ->native(false),

            TextInput::make('years')
                ->label('Έτη')
                ->numeric()
                ->required()
                ->minValue(1)
                ->maxValue(10)
                // Friendly duplicate check for the composite unique
                // (tld × operation × years × currency) — a field message, not
                // a raw QueryException (the CreateDomain/EditDomain pattern).
                ->unique(
                    table: 'domain_tld_prices',
                    column: 'years',
                    ignoreRecord: true,
                    modifyRuleUsing: fn ($rule, Get $get) => $rule
                        ->where('domain_tld_id', $this->getOwnerRecord()->getKey())
                        ->where('operation', (string) $get('operation'))
                        ->where('currency', mb_strtoupper(trim((string) ($get('currency') ?: 'EUR')))),
                )
                ->validationMessages(['unique' => 'Υπάρχει ήδη τιμή για αυτόν τον συνδυασμό ενέργειας/ετών/νομίσματος.']),

            TextInput::make('currency')
                ->label('Νόμισμα')
                ->default('EUR')
                ->maxLength(3)
                // Canonical on WRITE (the tld field's pattern): validator,
                // stored row and DB unique all see the same value — a cleared
                // field can't slip in as '' next to an 'EUR' row.
                ->dehydrateStateUsing(fn (?string $state): string => mb_strtoupper(trim((string) $state)) ?: 'EUR')
                ->helperText('EUR = settlement· άλλα νομίσματα display-only.'),

            TextInput::make('cost')
                ->label('Κόστος (registrar)')
                ->numeric()
                ->minValue(0)
                ->helperText('Χειροκίνητα εδώ· για Openprovider θα συγχρονίζεται (A2).'),

            TextInput::make('price')
                ->label('Τιμή πώλησης')
                ->numeric()
                ->minValue(0),

            Toggle::make('is_enabled')
                ->label('Ενεργή')
                ->default(true)
                ->helperText('Ανενεργή = ο συνδυασμός ενέργειας/ετών δεν προσφέρεται (το «-1» της WHMCS).'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('years')
            ->columns([
                TextColumn::make('operation')
                    ->label('Ενέργεια')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'register' => 'Καταχώρηση',
                        'transfer' => 'Μεταφορά',
                        'renewal' => 'Ανανέωση',
                        'restore' => 'Restore',
                        'redemption' => 'Redemption',
                        default => $state,
                    })
                    ->sortable(),

                TextColumn::make('years')
                    ->label('Έτη')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('currency')
                    ->label('Νόμισμα')
                    ->alignCenter(),

                TextColumn::make('cost')
                    ->label('Κόστος')
                    ->numeric(decimalPlaces: 2)
                    ->placeholder('—')
                    ->alignRight(),

                TextColumn::make('price')
                    ->label('Τιμή')
                    ->numeric(decimalPlaces: 2)
                    ->placeholder('—')
                    ->alignRight(),

                ToggleColumn::make('is_enabled')
                    ->label('Ενεργή'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Προσθήκη τιμής')
                    // Explicit tenant stamp (the CLAUDE.md tenancy rule).
                    ->mutateDataUsing(function (array $data): array {
                        $data['company_id'] = Filament::getTenant()?->getKey();

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
