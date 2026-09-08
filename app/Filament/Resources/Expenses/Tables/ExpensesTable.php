<?php

namespace App\Filament\Resources\Expenses\Tables;

use App\Enums\ExpenseSource;
use App\Filament\Support\Tags\TagControls;
use App\Services\MyData\ExpenseClassifier;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class ExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('issue_date')
                    ->label('Έκδοση')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('supplier.name')
                    ->label('Προμηθευτής')
                    ->placeholder('—')
                    ->description(fn ($record): ?string => $record->supplier_afm)
                    ->searchable()
                    ->wrap(),

                TextColumn::make('invoice_type')
                    ->label('Τύπος')
                    ->badge()
                    ->placeholder('—'),

                TextColumn::make('mydata_mark')
                    ->label('ΜΑΡΚ')
                    ->fontFamily('mono')
                    ->size('xs')
                    ->copyable()
                    ->searchable(),

                TextColumn::make('net_total')
                    ->label('Καθαρή')
                    ->money('EUR')
                    ->alignRight(),

                TextColumn::make('vat_total')
                    ->label('ΦΠΑ')
                    ->money('EUR')
                    ->alignRight(),

                TextColumn::make('gross_total')
                    ->label('Σύνολο')
                    ->money('EUR')
                    ->alignRight()
                    ->weight('bold'),

                TextColumn::make('mydata_state')
                    ->label('Κατάσταση')
                    ->badge()
                    ->color(fn (?string $state): string => $state === 'CANCELLED' ? 'danger' : 'success')
                    ->placeholder('—'),

                TextColumn::make('classification_state')
                    ->label('Χαρακτηρισμός')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => \App\Models\Expense::classificationStateLabel($state))
                    ->color(fn (?string $state): string => \App\Models\Expense::classificationStateColor($state)),

                TextColumn::make('source')
                    ->label('Προέλευση')
                    ->badge()
                    ->formatStateUsing(fn (ExpenseSource $state): string => $state->label())
                    ->color(fn (ExpenseSource $state): string => match ($state) {
                        ExpenseSource::Sync => 'info',
                        ExpenseSource::SelfDeclared => 'warning',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                // Economic bucket label for self-declared docs (πάγια / μισθοδοσία
                // / ενδοκοινοτικά…). Blank for supplier-sync rows (their type
                // already says it). Resolved via the same §8 table the dashboard uses.
                TextColumn::make('category')
                    ->label('Κατηγορία')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? '—'
                        : (\App\Support\MyData\Codes::selfDeclaredVatCategoryLabel($state) ?? $state))
                    ->toggleable(),

                TagControls::column(),
            ])
            ->filters([
                TagControls::filter(),

                SelectFilter::make('source')
                    ->label('Προέλευση')
                    ->options(ExpenseSource::options()),

                SelectFilter::make('mydata_state')
                    ->label('Κατάσταση myDATA')
                    ->options(['VALID' => 'VALID', 'CANCELLED' => 'CANCELLED']),

                SelectFilter::make('category')
                    ->label('Κατηγορία (δικά μας)')
                    ->options(\App\Support\MyData\Codes::selfDeclaredVatCategoryOptions()),

                SelectFilter::make('classification_state')
                    ->label('Χαρακτηρισμός')
                    ->options(['classified' => 'Χαρακτηρισμένο'])
                    ->query(fn ($query, array $data) => ($data['value'] ?? null) === 'classified'
                        ? $query->where('classification_state', 'classified')
                        : $query),

                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // No create/edit form for expenses (read-only import) — the
                    // bulk action is how you tag them.
                    TagControls::bulkAttachAction(),

                    // Run the auto-classification rules (#5) over the selected
                    // expenses — classifies the ones whose supplier matches a rule.
                    BulkAction::make('applyClassificationRules')
                        ->label('Εφαρμογή κανόνων χαρακτηρισμού')
                        ->icon('heroicon-o-sparkles')
                        ->color('primary')
                        ->action(function (Collection $records): void {
                            $applied = app(ExpenseClassifier::class)->classifyMany($records);

                            Notification::make()
                                ->title($applied > 0 ? "Χαρακτηρίστηκαν {$applied} έξοδα" : 'Κανένα έξοδο δεν ταίριαξε σε κανόνα')
                                ->body($applied > 0 ? 'Υπόβαλέ τα στην ΑΑΔΕ από το κάθε έξοδο ή μαζικά.' : 'Φτιάξε κανόνες στο «Κανόνες χαρακτηρισμού».')
                                ->{$applied > 0 ? 'success' : 'warning'}()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('issue_date', 'desc');
    }
}
