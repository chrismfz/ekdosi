<?php

namespace App\Filament\Resources\Companies\Tables;

use App\Filament\Resources\Companies\Actions\CompanyBackupActions;
use App\Models\Company;
use App\Support\EInvoice\SendChannel;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->badge()
                    ->color('gray')
                    ->searchable(),
                TextColumn::make('country_code')
                    ->label('Country')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'GR' => 'info',
                        'EE' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('einvoice_provider')
                    ->label('Τρόπος αποστολής')
                    ->badge()
                    ->color(fn (Company $record) => $record->isLiveProviderTenant() ? 'info' : 'gray')
                    // Show the FULL channel (provider + env), e.g. «InvoSign — Δοκιμαστικό»
                    // / «myDATA — Παραγωγή» — not the raw column value.
                    ->state(fn (Company $record): string => SendChannel::options(config('ekdosi.einvoice.provider_labels', []))[SendChannel::fromCompany($record)]
                        ?? match ($record->einvoice_provider) {
                            'ee-peppol' => 'PEPPOL',
                            'none' => 'PDF only',
                            default => (string) $record->einvoice_provider,
                        }),
                TextColumn::make('mydata_mode')
                    ->label('myDATA')
                    ->badge()
                    ->color(fn (?string $state, Company $record) => match (true) {
                        $record->einvoice_provider === 'gr-provider' => 'info', // present for reads
                        $state === 'production' => 'danger',  // 🔴 LIVE
                        $state === 'sandbox' => 'warning',    // 🟡 test
                        default => 'gray',
                    })
                    // For a provider tenant myDATA is the READ path (console / Ε3 /
                    // MARK check), not a filing mode — show «ανάγνωση», not a bare «off».
                    ->formatStateUsing(fn (?string $state, Company $record) => match (true) {
                        $record->einvoice_provider === 'gr-provider' => (filled($record->mydata_aade_id_sandbox) || filled($record->mydata_aade_id_production)) ? 'ανάγνωση' : '—',
                        $state === 'production' => 'LIVE',
                        $state === 'sandbox' => 'sandbox',
                        $state === 'off' => 'off',
                        default => '—',
                    }),
                TextColumn::make('users_count')
                    ->label('Users')
                    ->counts('users')
                    ->alignRight()
                    ->numeric(),
                TextColumn::make('afm')
                    ->label('AFM')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('country_code')
                    ->label('Country')
                    ->options([
                        'GR' => 'Greece',
                        'EE' => 'Estonia',
                    ]),
                SelectFilter::make('einvoice_provider')
                    ->label('e-invoice provider')
                    ->options([
                        'gr-mydata' => 'Greek myDATA',
                        'ee-peppol' => 'Estonian PEPPOL',
                        'none' => 'None',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    CompanyBackupActions::scheduleSettings(),
                    CompanyBackupActions::runNow(),
                    CompanyBackupActions::downloadNow(),
                    CompanyBackupActions::export(),
                    CompanyBackupActions::importInto(),
                    CompanyBackupActions::wipe(),
                ])
                    ->label('Αντίγραφα')
                    ->icon('heroicon-o-archive-box')
                    ->color('gray')
                    ->button(),
            ])
            ->toolbarActions([
                // «Εισαγωγή εταιρίας από αρχείο» moved to the page header, next to
                // «New company» (its natural twin) — see ListCompanies.
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }
}
