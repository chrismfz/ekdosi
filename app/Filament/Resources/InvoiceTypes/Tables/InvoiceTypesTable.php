<?php

namespace App\Filament\Resources\InvoiceTypes\Tables;

use App\Models\InvoiceType;
use App\Services\MyData\MyDataConfigAudit;
use App\Support\MyData\InvoiceTypeClassSuggester;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class InvoiceTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('code')
                    ->label('Series')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                // Pin the types you issue most (ΤΙΜ/ΤΠΥ…) to the top of the
                // new-invoice picker. Toggle inline.
                ToggleColumn::make('is_favorite')
                    ->label('Αγαπημένο')
                    ->sortable(),

                TextColumn::make('invcount')
                    ->label('Next ΑΑ')
                    ->numeric()
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('mydata_type')
                    ->label('myDATA')
                    ->badge()
                    // Empty classification = AADE would reject it at filing.
                    // Flag it red and show the suggested §8.1 code so the operator
                    // knows what to set (Edit → myDATA). Filled = plain gray code.
                    ->state(function ($record): string {
                        if (filled($record->mydata_type)) {
                            return $record->mydata_type;
                        }
                        $s = InvoiceTypeClassSuggester::suggest(
                            (string) $record->name,
                            (bool) $record->is_credit,
                            (bool) $record->is_return,
                        );

                        return $s ? "λείπει → {$s['code']}?" : 'λείπει';
                    })
                    ->color(fn ($record): string => filled($record->mydata_type) ? 'gray' : 'danger')
                    ->tooltip(function ($record): ?string {
                        if (filled($record->mydata_type)) {
                            return null;
                        }
                        $s = InvoiceTypeClassSuggester::suggest(
                            (string) $record->name,
                            (bool) $record->is_credit,
                            (bool) $record->is_return,
                        );

                        return $s
                            ? "Προτεινόμενη: {$s['code']} — {$s['label']} (επιβεβαιώστε στο Edit)"
                            : 'Ορίστε κατηγορία myDATA στο Edit';
                    })
                    ->toggleable(),

                // myDATA-readiness verdict from the SHARED MyDataConfigAudit — the
                // same check behind `mydata:preflight` and the «Έλεγχος ρυθμίσεων»
                // console tab. Beyond «is mydata_type set» (the column above), it
                // also validates the income classification (type + category), so a
                // ✓ here means AADE would accept this type's config.
                TextColumn::make('mydata_ready')
                    ->label('Ετοιμότητα myDATA')
                    ->badge()
                    ->state(fn (InvoiceType $record): string => match (self::auditRow($record)->status()) {
                        'error' => 'Σφάλμα',
                        'warn' => 'Προσοχή',
                        default => 'Εντάξει',
                    })
                    ->color(fn (InvoiceType $record): string => match (self::auditRow($record)->status()) {
                        'error' => 'danger',
                        'warn' => 'warning',
                        default => 'success',
                    })
                    ->icon(fn (InvoiceType $record): string => match (self::auditRow($record)->status()) {
                        'error' => 'heroicon-o-x-circle',
                        'warn' => 'heroicon-o-exclamation-triangle',
                        default => 'heroicon-o-check-circle',
                    })
                    ->tooltip(fn (InvoiceType $record): ?string => implode(' · ', self::auditRow($record)->messages()) ?: null)
                    ->toggleable(),

                IconColumn::make('show_on_menu')
                    ->label('On menu')
                    ->boolean()
                    ->toggleable(),

                IconColumn::make('is_credit')
                    ->label('Credit')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_return')
                    ->label('Return')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('show_on_menu')
                    ->label('On menu')
                    ->placeholder('All')
                    ->default(true),

                TernaryFilter::make('is_favorite')
                    ->label('Αγαπημένα')
                    ->placeholder('Όλα'),

                TernaryFilter::make('is_credit')
                    ->label('Credit documents')
                    ->placeholder('All'),

                TernaryFilter::make('is_return')
                    ->label('Return documents')
                    ->placeholder('All'),

                TrashedFilter::make(),
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
            ->defaultSort('code');
    }

    /**
     * Memoised per-record audit row (the badge reads status/color/icon AND the
     * tooltip reads messages() off the SAME row — four closures per row, one
     * audit). In-memory check against the §8 code tables, no query. Keyed by the
     * global surrogate PK (no cross-tenant collision; the legacy_id collision
     * doesn't touch the PK). Per-request under FPM — see the Octane note in
     * CLAUDE.md if this ever runs on a persistent worker.
     *
     * @var array<int, \App\Services\MyData\ConfigAuditRow>
     */
    private static array $auditCache = [];

    private static function auditRow(InvoiceType $record): \App\Services\MyData\ConfigAuditRow
    {
        return self::$auditCache[$record->getKey()] ??=
            app(MyDataConfigAudit::class)->auditInvoiceType($record);
    }
}
