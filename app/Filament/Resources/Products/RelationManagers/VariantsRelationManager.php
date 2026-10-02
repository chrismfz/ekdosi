<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Filament\Resources\ProductAttributes\ProductAttributeResource;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Services\Products\VariantGenerator;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

/**
 * «Παραλλαγές» of a variable product (docs/woocommerce-bridge-plan.md §0).
 * Each row is a full product (own stock/SKU/barcode/price); barcode, SKU and
 * internal code are editable inline so a stack of new stock can be scanned in
 * one pass. Generation + parent→variant sync are header actions.
 */
class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    protected static ?string $title = 'Παραλλαγές';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Product
            && $ownerRecord->isVariable()
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withoutGlobalScopes([SoftDeletingScope::class])
                ->with('variantValues.attribute')
                ->withSum('stockMovements as stock_on_hand', 'qty_change'))
            ->recordUrl(fn (Product $record) => ProductResource::getUrl('edit', ['record' => $record]))
            ->columns([
                TextColumn::make('values')
                    ->label('Παραλλαγή')
                    ->state(fn (Product $record) => $record->variantValues
                        ->sortBy(fn ($v) => [$v->attribute?->sort ?? 0, $v->product_attribute_id])
                        ->map(fn ($v) => ($v->color_hex
                            ? '<span class="ekdosi-swatch" style="background: '.e($v->color_hex).'"></span>'
                            : '').e($v->value))
                        ->implode(' / '))
                    ->html(),

                TextInputColumn::make('barcode')
                    ->label('Barcode')
                    // DB unique(company_id, barcode) counts trashed rows too.
                    ->rules(fn (Product $record) => ['nullable', 'max:25', Rule::unique('products', 'barcode')
                        ->where('company_id', $record->company_id)
                        ->ignore($record->id)])
                    ->disabled(fn (Product $record) => ! $this->canEditVariant($record)),

                TextInputColumn::make('sku')
                    ->label('SKU')
                    ->rules(fn (Product $record) => ['nullable', 'max:40', Rule::unique('products', 'sku')
                        ->where('company_id', $record->company_id)
                        ->ignore($record->id)])
                    ->disabled(fn (Product $record) => ! $this->canEditVariant($record)),

                TextInputColumn::make('internal_code')
                    ->label('Εσωτ. κωδικός')
                    ->rules(fn (Product $record) => ['nullable', 'max:60', Rule::unique('products', 'internal_code')
                        ->where('company_id', $record->company_id)
                        ->ignore($record->id)])
                    ->disabled(fn (Product $record) => ! $this->canEditVariant($record))
                    ->toggleable(),

                TextColumn::make('price_wvat')
                    ->label('Τιμή (με ΦΠΑ)')
                    ->money('EUR')
                    ->alignRight(),

                TextColumn::make('stock_on_hand')
                    ->label('Απόθεμα')
                    ->state(fn (Product $record) => $record->track_stock ? (float) ($record->stock_on_hand ?? 0) : null)
                    ->numeric(decimalPlaces: 0)
                    ->badge()
                    ->color(fn (Product $record) => ! $record->track_stock ? 'gray'
                        : ((float) ($record->stock_on_hand ?? 0) < 0 ? 'danger'
                            : ((float) ($record->stock_on_hand ?? 0) <= 0 ? 'warning' : 'success')))
                    ->placeholder('—')
                    ->alignRight(),

                IconColumn::make('is_active')
                    ->label('Ενεργή')
                    ->boolean(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->headerActions([
                $this->generateAction(),
                $this->syncAction(),
            ])
            ->recordActions([
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Inline/bulk edits bypass the edit page, so gate them on the update policy.
                    BulkAction::make('deactivate')
                        ->label('Απενεργοποίηση')
                        ->icon('heroicon-o-eye-slash')
                        ->visible(fn () => $this->canEditVariant($this->getOwnerRecord()))
                        ->action(fn (Collection $records) => $records->each->update(['is_active' => false]))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('activate')
                        ->label('Ενεργοποίηση')
                        ->icon('heroicon-o-eye')
                        ->visible(fn () => $this->canEditVariant($this->getOwnerRecord()))
                        ->action(fn (Collection $records) => $records->each->update(['is_active' => true]))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50);
    }

    private function canEditVariant(Product $record): bool
    {
        return (bool) auth()->user()?->can('update', $record);
    }

    /** Pick values per attribute → every missing combination becomes a variant. */
    private function generateAction(): Action
    {
        return Action::make('generate_variants')
            ->label('Δημιουργία παραλλαγών')
            ->icon('heroicon-o-squares-plus')
            ->modalHeading('Δημιουργία παραλλαγών')
            ->modalDescription('Διάλεξε τιμές — δημιουργείται μία παραλλαγή για κάθε συνδυασμό που λείπει. Οι υπάρχουσες δεν αλλάζουν. Τιμή, ΦΠΑ και κατηγορία αντιγράφονται από το γονικό.')
            ->authorize('update', $this->getOwnerRecord())
            ->schema(function (): array {
                $attributes = ProductAttribute::query()
                    ->where('company_id', $this->getOwnerRecord()->company_id)
                    ->with('values')
                    ->orderBy('sort')
                    ->orderBy('id')
                    ->get();

                if ($attributes->isEmpty()) {
                    return [
                        Section::make()->schema([])->description(new HtmlString(
                            'Δεν υπάρχουν χαρακτηριστικά. Φτιάξε πρώτα π.χ. «Χρώμα» και «Μέγεθος» στα '
                            .'<a class="fi-link" href="'.e(ProductAttributeResource::getUrl('index')).'">Χαρακτηριστικά</a>.'
                        )),
                    ];
                }

                return $attributes->map(fn (ProductAttribute $attribute) => CheckboxList::make('values_'.$attribute->id)
                    ->label($attribute->name)
                    ->options($attribute->values->pluck('value', 'id'))
                    ->columns(6)
                    ->bulkToggleable()
                )->all();
            })
            ->action(function (array $data): void {
                $picked = [];
                foreach ($data as $key => $ids) {
                    if (str_starts_with((string) $key, 'values_') && ! empty($ids)) {
                        $picked[(int) substr((string) $key, 7)] = array_map('intval', $ids);
                    }
                }

                if ($picked === []) {
                    Notification::make()->warning()->title('Δεν επιλέχθηκαν τιμές')->send();

                    return;
                }

                $created = app(VariantGenerator::class)->generate($this->getOwnerRecord(), $picked);

                Notification::make()
                    ->success()
                    ->title($created->isEmpty()
                        ? 'Δεν χρειάστηκε νέα παραλλαγή — υπάρχουν ήδη όλοι οι συνδυασμοί'
                        : 'Δημιουργήθηκαν '.$created->count().' παραλλαγές')
                    ->send();
            });
    }

    /** Push chosen parent fields (prices, VAT, names…) down to every variant. */
    private function syncAction(): Action
    {
        return Action::make('sync_from_parent')
            ->label('Εφαρμογή γονικού στις παραλλαγές')
            ->icon('heroicon-o-arrow-down-on-square-stack')
            ->color('gray')
            ->modalHeading('Εφαρμογή στοιχείων γονικού σε όλες τις παραλλαγές')
            ->modalDescription('Τα επιλεγμένα πεδία αντικαθίστανται σε ΚΑΘΕ παραλλαγή με τις τιμές του γονικού — και όσες είχες αλλάξει χειροκίνητα. Αποθήκευσε πρώτα τις αλλαγές του γονικού.')
            ->authorize('update', $this->getOwnerRecord())
            ->schema([
                CheckboxList::make('groups')
                    ->label('Τι να εφαρμοστεί')
                    ->options(VariantGenerator::SYNC_GROUP_LABELS)
                    ->required(),
            ])
            ->action(function (array $data): void {
                $count = app(VariantGenerator::class)->syncFromParent($this->getOwnerRecord(), $data['groups'] ?? []);

                Notification::make()->success()->title('Ενημερώθηκαν '.$count.' παραλλαγές')->send();
            });
    }
}
