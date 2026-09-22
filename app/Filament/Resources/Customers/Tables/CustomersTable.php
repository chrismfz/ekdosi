<?php

namespace App\Filament\Resources\Customers\Tables;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\Tags\TagControls;
use App\Models\Customer;
use App\Support\InvoiceScope;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Apply filters immediately (Filament defers them by default).
            // The dashboard's "Ανεξόφλητα (πιστωτικά)" card drills in via a
            // ?filters[balance_status][value]=debtor URL (App\Support\TableFilterUrl
            // builds it — `filters` is the bound key); with deferred
            // filters that value only PRE-FILLS the form and the operator
            // would still have to click "Apply" — the list would land
            // unfiltered. deferFilters(false) makes the drill-down (and all
            // filtering on this list) take effect on load / on change.
            ->deferFilters(false)
            // Attach the `outstanding_balance` alias (+ its cust_owed /
            // cust_paid join sub-selects) so the "Υπόλοιπο" column + the
            // balance_status filter below can read it. Computed in SQL,
            // identical math to the dashboard headline. Always applied so
            // the filter's whereRaw can reference the join aliases even
            // when the column is toggled off.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withOutstandingBalance(Filament::getTenant()?->getKey() ?? 0)
                // Sales-activity aliases (invoice_count / turnover /
                // last_invoiced_at) for the «Αρ. Παρ/ων», «Τζίρος» and
                // «Τελευταίο παρ/κό» columns + the «Δραστηριότητα» filter.
                // Chained AFTER withOutstandingBalance (it addSelects, so the
                // outstanding_balance alias survives). Always applied so the
                // filter's whereRaw can reference cust_stats even when the
                // columns are toggled off.
                ->withInvoiceStats(Filament::getTenant()?->getKey() ?? 0))
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                // Pin frequent customers to the top of the new-invoice
                // picker. Toggle inline.
                ToggleColumn::make('is_favorite')
                    ->label('Αγαπημένο')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('afm')
                    ->label('AFM')
                    ->searchable()
                    ->copyable()
                    ->toggleable(),

                TextColumn::make('city')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('email')
                    ->searchable()
                    // Sortable so blanks cluster (empties sort first/last) —
                    // the «Χωρίς email» filter below is the direct way to find
                    // them, now that the Πάροχος also emails the παραστατικό.
                    ->sortable()
                    ->copyable()
                    // Truncate long addresses so they don't widen the row;
                    // full value stays available on hover + via copy.
                    ->limit(30)
                    ->tooltip(fn ($state): ?string => $state)
                    ->toggleable(),

                // Hidden by default — rarely needed at a glance; available
                // from the column controls when an operator wants to call.
                TextColumn::make('phone1')
                    ->label('Phone')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('outstanding_balance')
                    ->label('Υπόλοιπο')
                    ->money('EUR')
                    ->alignRight()
                    ->sortable()
                    ->weight('bold')
                    // Red when they owe, muted otherwise.
                    ->color(fn ($state): ?string => (float) $state > 0.005 ? 'danger' : 'gray')
                    ->toggleable(),

                // Sales activity (aliases from withInvoiceStats). Sortable in
                // SQL to spot «νεκρούς» (0) and the busiest customers at a glance.
                TextColumn::make('invoice_count')
                    ->label('Αρ. Παρ/ων')
                    ->tooltip('Αριθμός εκδομένων παραστατικών (χωρίς πρόχειρα / ακυρωμένα / πιστωτικά)')
                    ->alignRight()
                    ->sortable()
                    // Grey out the dead ones (0) so they stand out when sorted.
                    ->color(fn ($state): ?string => (int) $state === 0 ? 'gray' : null)
                    ->toggleable(),

                // «Τζίρος» = καθαρός κύκλος εργασιών (net, χωρίς ΦΠΑ) — τα
                // πιστωτικά αφαιρούνται, όπως στο βιβλίο εσόδων. Off by default —
                // the operator opens it when ranking customers by value.
                TextColumn::make('turnover')
                    ->label('Τζίρος')
                    ->tooltip('Καθαρός κύκλος εργασιών: καθαρή αξία πωλήσεων μείον πιστωτικά (χωρίς ΦΠΑ)')
                    ->money('EUR')
                    ->alignRight()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                // Dormancy signal — MAX(issued_at). A customer with many
                // invoices but none for a long time is churning; sort here.
                TextColumn::make('last_invoiced_at')
                    ->label('Τελ. παρ/κό')
                    ->tooltip('Ημερομηνία τελευταίου παραστατικού')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->alignRight()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                // #8: AADE/GSIS registry status of the ΑΦΜ. «—» until first checked
                // (on-demand «Διασταύρωση» or the periodic customers:refresh-aade-status).
                TextColumn::make('aade_active')
                    ->label('ΑΦΜ ΑΑΔΕ')
                    ->badge()
                    ->state(fn ($record): string => $record->aade_status_checked_at === null
                        ? '—'
                        : ($record->aade_active === false ? 'Ανενεργό' : 'Ενεργό'))
                    ->color(fn ($record): string => match (true) {
                        $record->aade_status_checked_at === null => 'gray',
                        $record->aade_active === false => 'danger',
                        default => 'success',
                    })
                    ->tooltip(fn ($record): ?string => $record->aade_status_checked_at?->format('d/m/Y'))
                    ->toggleable(),

                IconColumn::make('needs_immediate_invoice')
                    ->label('Άμεσο')
                    ->tooltip('Άμεση τιμολόγηση — έκδοση αμέσως μετά την πληρωμή')
                    ->boolean()
                    ->trueIcon('heroicon-o-bolt')
                    ->falseIcon('heroicon-o-clock')
                    ->toggleable(),

                TextColumn::make('discount')
                    ->suffix('%')
                    ->alignRight()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('whmcs_client_id')
                    ->label('WHMCS')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                // T-1b (#6): flags customers who route some of their WHMCS
                // invoices to third parties ("Παραστατικά σε τρίτους"). A
                // heads-up that this customer's invoices may not all be
                // theirs. Maintained by `whmcs:sync-resellers`.
                TextColumn::make('whmcs_reseller_routes')
                    ->label('Σε τρίτους')
                    ->badge()
                    ->color('warning')
                    ->icon('heroicon-o-users')
                    ->placeholder('—')
                    ->state(fn ($record): ?string => ($record->whmcs_reseller_routes ?? 0) > 0
                        ? $record->whmcs_reseller_routes.' υπηρ.'
                        : null)
                    ->tooltip('Δρομολογεί παραστατικά σε τρίτους — έλεγξε ότι τα τιμολόγια είναι όντως δικά του.')
                    ->toggleable(),

                TagControls::column(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TagControls::filter(),

                TernaryFilter::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->default(true)
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only')
                    ->placeholder('All'),

                TernaryFilter::make('is_favorite')
                    ->label('Αγαπημένα')
                    ->placeholder('Όλοι'),

                // Leads L1: customers that came in through the mini-CRM.
                TernaryFilter::make('from_lead')
                    ->label('Από lead')
                    ->placeholder('Όλοι')
                    ->trueLabel('Μόνο από lead')
                    ->falseLabel('Μόνο απευθείας')
                    ->queries(
                        true: fn (Builder $q): Builder => $q->whereHas('originLead'),
                        false: fn (Builder $q): Builder => $q->whereDoesntHave('originLead'),
                        blank: fn (Builder $q): Builder => $q,
                    ),

                TernaryFilter::make('needs_immediate_invoice')
                    ->label('Immediate invoicing')
                    ->boolean()
                    ->trueLabel('Άμεσα μόνο')
                    ->falseLabel('Batched only')
                    ->placeholder('All'),

                // Υπόλοιπο — χρεωστικοί (μας χρωστάνε, θετικό) vs πιστωτικοί
                // (έχουν πίστωση, αρνητικό) vs μηδενικό. Reads the join aliases
                // attached by modifyQueryUsing() above. `debtor` is also the
                // target of the dashboard's «Ανεξόφλητα» card drill-down (see
                // IncomeStatsOverview) — keep the value key `debtor` stable.
                SelectFilter::make('balance_status')
                    ->label('Υπόλοιπο')
                    ->placeholder('Όλοι')
                    ->options([
                        'debtor' => 'Χρεωστικοί (μας χρωστάνε)',
                        'creditor' => 'Πιστωτικοί (έχουν πίστωση)',
                        'zero' => 'Μηδενικό υπόλοιπο',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'debtor' => $query->whereRaw(Customer::OUTSTANDING_BALANCE_SQL.' > 0.005'),
                        'creditor' => $query->whereRaw(Customer::OUTSTANDING_BALANCE_SQL.' < -0.005'),
                        'zero' => $query->whereRaw('ABS('.Customer::OUTSTANDING_BALANCE_SQL.') <= 0.005'),
                        default => $query,
                    }),

                // Πελάτες με ≥1 ανοιχτό προτιμολόγιο (unissued sale draft). Same
                // predicate as the dashboard «Πρόχειρα» pillar (MON-5), so the two
                // agree on what a draft is.
                TernaryFilter::make('has_open_drafts')
                    ->label('Προτιμολόγια')
                    ->placeholder('Όλοι')
                    ->trueLabel('Με ανοιχτά προτιμολόγια')
                    ->falseLabel('Χωρίς ανοιχτά προτιμολόγια')
                    ->queries(
                        true: fn (Builder $query): Builder => $query
                            ->whereHas('invoices', fn (Builder $iq) => InvoiceScope::onlyUnissuedDrafts($iq)),
                        false: fn (Builder $query): Builder => $query
                            ->whereDoesntHave('invoices', fn (Builder $iq) => InvoiceScope::onlyUnissuedDrafts($iq)),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                // Data quality: find customers with no email on file — the ones
                // to chase now that the Πάροχος also emails the παραστατικό.
                // Empty string counts as «χωρίς» (legacy rows store '' not NULL).
                TernaryFilter::make('has_email')
                    ->label('Email')
                    ->placeholder('Όλοι')
                    ->trueLabel('Με email')
                    ->falseLabel('Χωρίς email')
                    ->queries(
                        true: fn (Builder $query): Builder => $query
                            ->whereNotNull('email')->where('email', '!=', ''),
                        false: fn (Builder $query): Builder => $query
                            ->where(fn (Builder $q) => $q->whereNull('email')->orWhere('email', '=', '')),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                // «Νεκροί» vs ενεργοί — reads the same cust_stats.invoice_count
                // alias the «Αρ. Παρ/ων» column shows (INVOICE_COUNT_SQL), so the
                // filter and the column can never disagree on what «έχει
                // παραστατικά» means.
                TernaryFilter::make('has_invoices')
                    ->label('Δραστηριότητα')
                    ->placeholder('Όλοι')
                    ->trueLabel('Με παραστατικά')
                    ->falseLabel('Χωρίς παραστατικά (νεκροί)')
                    ->queries(
                        true: fn (Builder $query): Builder => $query
                            ->whereRaw(Customer::INVOICE_COUNT_SQL.' > 0'),
                        false: fn (Builder $query): Builder => $query
                            ->whereRaw(Customer::INVOICE_COUNT_SQL.' = 0'),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),

                Action::make('toggle_active')
                    ->authorize('update')
                    ->label(fn ($record) => $record->is_active ? 'Deactivate' : 'Activate')
                    ->icon(fn ($record) => $record->is_active ? 'heroicon-o-no-symbol' : 'heroicon-o-check-circle')
                    ->color(fn ($record) => $record->is_active ? 'gray' : 'success')
                    ->visible(fn ($record) => ! $record->trashed())
                    ->requiresConfirmation()
                    ->action(function ($record): void {
                        $record->update(['is_active' => ! $record->is_active]);
                        Notification::make()
                            ->title(($record->is_active ? 'Activated: ' : 'Deactivated: ').$record->name)
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ])
            // Row click → Καρτέλα (the financial view). Matches Greek
            // accounting software convention (Singular / Atlantis /
            // Soft1) — operators primarily SEE customer accounts;
            // edits are rare. The explicit Edit action above stays as
            // the secondary path.
            ->recordUrl(fn ($record) => CustomerResource::getUrl('ledger', ['record' => $record]))
            ->defaultSort('name');
    }
}
