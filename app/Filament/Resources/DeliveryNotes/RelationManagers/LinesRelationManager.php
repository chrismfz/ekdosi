<?php

namespace App\Filament\Resources\DeliveryNotes\RelationManagers;

use App\Models\Product;
use App\Support\MyData\Codes;
use App\Support\MyData\DeliveryCodes;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Delivery-note lines (value-less: description + quantity + unit, no money).
 * Editable ONLY while the note is a draft — once filed (mydata_state set) the
 * lines are frozen on the MARK, so the create/edit/delete actions hide. Mirrors
 * the DeliveryNoteForm line repeater so editing here or on the form is the same.
 */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Γραμμές';

    protected static ?string $recordTitleAttribute = 'product_descr';

    /**
     * Relation managers are read-only on a ViewRecord page by default; opt out so
     * our own draft-gating (editable()) governs the create/edit/delete actions —
     * lines stay editable from the View page while the note is a draft.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    /** A draft note (not yet filed) is editable; a filed one is frozen. */
    protected function editable(): bool
    {
        $record = $this->getOwnerRecord();

        return ($record->local_status ?? null) === 'draft'
            && $record->mydata_state === null;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('product_id')
                    ->label('Προϊόν (προαιρετικό)')
                    ->searchable()
                    ->preload(false)
                    ->getSearchResultsUsing(fn (string $search) => Product::query()
                        ->where('company_id', Filament::getTenant()?->getKey())
                        ->where('description_short', 'like', "%{$search}%")
                        ->orderBy('description_short')
                        ->limit(50)
                        ->pluck('description_short', 'id')
                        ->toArray())
                    ->getOptionLabelUsing(fn ($value) => optional(Product::query()
                        ->where('company_id', Filament::getTenant()?->getKey())
                        ->find($value))->description_short)
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set): void {
                        if (! $state) {
                            return;
                        }
                        $product = Product::find($state);
                        if ($product) {
                            $set('product_descr', $product->description_short);
                        }
                    }),

                TextInput::make('product_descr')
                    ->label('Περιγραφή')
                    ->placeholder('Από προϊόν ή ελεύθερο κείμενο'),

                TextInput::make('qty')
                    ->label('Ποσότητα')
                    ->required()
                    ->numeric()
                    ->step('0.001')
                    ->default(1)
                    ->minValue(0.001),

                Select::make('measurement_unit')
                    ->label('Μ.Μ.')
                    ->options(Codes::QUANTITY_TYPES)
                    ->default(1)
                    ->selectablePlaceholder(false),

                Select::make('move_purpose_line')
                    ->label('Σκοπός γραμμής (προαιρετικό)')
                    ->options(DeliveryCodes::movePurposeOptions())
                    ->searchable(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product_descr')
                    ->label('Περιγραφή')
                    ->wrap()
                    ->placeholder('—'),

                TextColumn::make('qty')
                    ->label('Ποσότητα')
                    ->numeric(decimalPlaces: 3)
                    ->alignRight(),

                TextColumn::make('measurement_unit')
                    ->label('Μ.Μ.')
                    ->formatStateUsing(fn ($state) => Codes::QUANTITY_TYPES[(int) $state] ?? $state)
                    ->placeholder('—'),

                TextColumn::make('move_purpose_line')
                    ->label('Σκοπός γραμμής')
                    ->formatStateUsing(fn ($state) => $state === null ? null : DeliveryCodes::movePurposeLabel((int) $state))
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Προσθήκη γραμμής')
                    ->visible(fn (): bool => $this->editable()),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => $this->editable()),
                DeleteAction::make()
                    ->visible(fn (): bool => $this->editable()),
            ])
            ->defaultSort('id');
    }
}
