<?php

namespace App\Filament\Resources\PaymentMethods\Tables;

use App\Filament\Resources\PaymentMethods\PaymentMethodResource;
use App\Filament\Support\GuardedDeleteAction;
use App\Models\Company;
use App\Support\MyData\Codes;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class PaymentMethodsTable
{
    public static function configure(Table $table): Table
    {
        // Does the ACTIVE tenant file to AADE (by provider)? The §8.12 «λείπει»
        // warning is meaningful only then — a non-AADE tenant (ee-peppol / none)
        // never files a myDATA payment type, so we stay neutral «—» there instead of
        // nagging. Provider-gated (mode-independent) to match MyDataConfigAudit MYD-4:
        // a mode=off gr-mydata tenant should still prepare its mapping before go-live.
        // Constant per request → resolve once, not per row/closure.
        $tenant = Filament::getTenant();
        $filesToAade = $tenant instanceof Company && $tenant->filesToAadeByProvider();

        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('description')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('due_days')
                    ->label('Due days')
                    ->numeric()
                    ->sortable()
                    ->alignRight(),

                // §8.12 mapping, mirroring the Invoice Types «myDATA» column. A method
                // WITHOUT a type is filed as «Μετρητά» (3) — NOT rejected, just possibly
                // wrong — so an unmapped one is a WARN («λείπει → 3»), not a hard error
                // (that matches MyDataConfigAudit MYD-4 and the Preflight «Προσοχή»).
                TextColumn::make('mydata_payment_type')
                    ->label('myDATA (§8.12)')
                    ->badge()
                    ->state(function ($record) use ($filesToAade): string {
                        $type = $record->mydata_payment_type;
                        if (filled($type)) {
                            $label = Codes::PAYMENT_METHODS[(int) $type] ?? '';

                            return $label !== '' ? $type.' · '.$label : (string) $type;
                        }

                        return $filesToAade ? 'λείπει → 3' : '—';
                    })
                    // A single «warn?» flag drives color/icon/tooltip together, so they
                    // can never disagree: only an unmapped method on an AADE tenant nags.
                    ->color(fn ($record): string => self::warns($record, $filesToAade) ? 'warning' : 'gray')
                    ->icon(fn ($record): ?string => self::warns($record, $filesToAade) ? 'heroicon-o-exclamation-triangle' : null)
                    ->tooltip(fn ($record): ?string => self::warns($record, $filesToAade)
                        ? 'Χωρίς αντιστοίχιση §8.12 → δηλώνεται ως «Μετρητά» (τύπος 3). Όρισε τον σωστό τύπο στο Edit.'
                        : null)
                    ->toggleable(),

                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    GuardedDeleteAction::bulk(fn ($record): array => PaymentMethodResource::dependents($record)),
                    RestoreBulkAction::make(),
                    GuardedDeleteAction::forceBulk(fn ($record): array => PaymentMethodResource::dependents($record)),
                ]),
            ])
            ->defaultSort('description');
    }

    /** Unmapped §8.12 on a tenant that files to AADE → the row nags (orange + icon + tooltip). */
    private static function warns($record, bool $filesToAade): bool
    {
        return $filesToAade && blank($record->mydata_payment_type);
    }
}
