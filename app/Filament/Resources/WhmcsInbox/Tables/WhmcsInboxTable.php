<?php

namespace App\Filament\Resources\WhmcsInbox\Tables;

use App\Models\Customer;
use App\Models\InvoiceType;
use App\Models\PendingWhmcsInvoice;
use App\Services\WhmcsInbox\WhmcsInvoiceFiler;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Stage B-2 inbox table: list pending_whmcs_invoices rows + per-row
 * actions. All actions scope to the current tenant explicitly because
 * PendingWhmcsInvoice has no global tenant scope (tracked deferral in
 * CLAUDE.md).
 */
class WhmcsInboxTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                $tenant = Filament::getTenant();
                $query->where('company_id', $tenant?->getKey() ?? 0)
                    ->with(['customer:id,name,afm', 'filedByUser:id,name']);
            })
            ->columns([
                TextColumn::make('whmcs_invoice_id')
                    ->label('WHMCS #')
                    ->sortable()
                    ->searchable()
                    ->prefix('#'),

                TextColumn::make('payload.date')
                    ->label('Ημερομηνία')
                    ->date('Y-m-d')
                    ->state(fn (PendingWhmcsInvoice $r) => $r->payload['date'] ?? null),

                TextColumn::make('payload.total')
                    ->label('Σύνολο')
                    ->state(function (PendingWhmcsInvoice $r): string {
                        $total = (float) ($r->payload['total'] ?? 0);
                        $cur = (string) ($r->payload['currencycode'] ?? '');
                        return number_format($total, 2, ',', '.').' '.$cur;
                    })
                    ->alignRight(),

                TextColumn::make('customer.name')
                    ->label('Πελάτης (ekdosi)')
                    ->placeholder('— μη συνδεδεμένος —')
                    ->description(fn (PendingWhmcsInvoice $r) => $r->customer?->afm
                        ? 'ΑΦΜ '.$r->customer->afm
                        : null
                    )
                    ->searchable(),

                TextColumn::make('match_reason')
                    ->label('Match')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        PendingWhmcsInvoice::REASON_LINKED    => 'success',
                        PendingWhmcsInvoice::REASON_AFM       => 'success',
                        PendingWhmcsInvoice::REASON_EMAIL     => 'info',
                        PendingWhmcsInvoice::REASON_NAME      => 'warning',
                        PendingWhmcsInvoice::REASON_UNMATCHED => 'danger',
                        default                               => 'gray',
                    }),

                TextColumn::make('status')
                    ->label('Κατάσταση')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        PendingWhmcsInvoice::STATUS_PENDING_REVIEW => 'warning',
                        PendingWhmcsInvoice::STATUS_FILED          => 'success',
                        PendingWhmcsInvoice::STATUS_REJECTED       => 'danger',
                        PendingWhmcsInvoice::STATUS_HELD           => 'gray',
                        default                                    => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        PendingWhmcsInvoice::STATUS_PENDING_REVIEW => 'Προς έλεγχο',
                        PendingWhmcsInvoice::STATUS_FILED          => 'Καταχωρημένο',
                        PendingWhmcsInvoice::STATUS_REJECTED       => 'Απορρίφθηκε',
                        PendingWhmcsInvoice::STATUS_HELD           => 'Σε αναμονή',
                        default                                    => $state,
                    }),

                TextColumn::make('mydata_mark')
                    ->label('MARK')
                    ->copyable()
                    ->placeholder('—')
                    ->fontFamily('mono'),

                TextColumn::make('created_at')
                    ->label('Στάλθηκε')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Κατάσταση')
                    ->options([
                        PendingWhmcsInvoice::STATUS_PENDING_REVIEW => 'Προς έλεγχο',
                        PendingWhmcsInvoice::STATUS_FILED          => 'Καταχωρημένο',
                        PendingWhmcsInvoice::STATUS_REJECTED       => 'Απορρίφθηκε',
                        PendingWhmcsInvoice::STATUS_HELD           => 'Σε αναμονή',
                    ])
                    ->default(PendingWhmcsInvoice::STATUS_PENDING_REVIEW),
            ])
            ->recordActions([
                self::fileAtAadeAction(),
                self::rejectAction(),
                self::holdAction(),
                self::reStageAction(),
            ]);
    }

    /**
     * The headline action: full-preview modal -> file at AADE.
     * Visible only on pending_review rows (filed/rejected/held can't
     * be filed; held must be re-staged first).
     */
    private static function fileAtAadeAction(): Action
    {
        return Action::make('file_at_aade')
            ->label('Καταχώρηση στην ΑΑΔΕ')
            ->icon('heroicon-o-cloud-arrow-up')
            ->color('success')
            ->visible(fn (PendingWhmcsInvoice $r) => $r->status === PendingWhmcsInvoice::STATUS_PENDING_REVIEW)
            ->form(fn (PendingWhmcsInvoice $r) => [
                Select::make('customer_id')
                    ->label('Πελάτης')
                    // Mirror InvoiceForm.php's canonical pattern: lazy
                    // server-side search via getSearchResultsUsing, no
                    // preload of all customers (the previous
                    // ->options()->limit(500)->preload()->searchable()
                    // pattern silently truncated tenants with >500
                    // customers — customers alphabetically past #500
                    // were unreachable through the search box because
                    // Filament's searchable+preload only filters the
                    // preloaded options client-side).
                    ->searchable()
                    ->preload(false)
                    ->getSearchResultsUsing(fn (string $search) => Customer::query()
                        ->where('company_id', Filament::getTenant()?->getKey())
                        ->where(fn ($q) => $q
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('afm', 'like', "%{$search}%"))
                        ->orderBy('name')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn ($c) => [$c->id => $c->name.($c->afm ? ' ('.$c->afm.')' : '')])
                        ->toArray())
                    // withTrashed: if Stage B-1 matched the row to a
                    // customer that's since been soft-deleted, the
                    // default ID rendering still resolves (with a
                    // "(διαγραμμένος)" suffix) instead of producing
                    // a blank-label Select. The action's firstOrFail
                    // below would otherwise 500 on submit.
                    ->getOptionLabelUsing(function ($value): ?string {
                        $c = Customer::query()
                            ->withTrashed()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->find($value);
                        if ($c === null) {
                            return null;
                        }
                        return $c->trashed()
                            ? $c->name.' (διαγραμμένος)'
                            : $c->name;
                    })
                    ->required()
                    ->live()     // re-renders the preview Placeholder below
                    ->default($r->customer_id)
                    ->helperText('Προτείνεται ο πελάτης που εντοπίστηκε από τη Stage B-1 (match: '
                        .$r->match_reason.'). Άλλαξέ τον αν δεν είναι σωστός.'),

                Select::make('invoice_type_id')
                    ->label('Τύπος παραστατικού')
                    ->options(fn () => InvoiceType::query()
                        ->where('company_id', Filament::getTenant()?->getKey())
                        ->orderBy('code')
                        ->pluck('code', 'id'))
                    ->required()
                    ->searchable()
                    ->live()
                    ->helperText('Επιλέγει σειρά + ΑΑ counter + myDATA mapping.'),

                // Full preview: re-renders whenever customer / invoice
                // type change. Calls the filer's preview() (no DB writes)
                // and renders the result via the same Blade partial the
                // real filing path would build. Operator sees exactly
                // what would go to AADE BEFORE clicking Confirm.
                Placeholder::make('preview')
                    ->label('Προεπισκόπηση παραστατικού')
                    ->content(function (Get $get) use ($r): \Illuminate\Contracts\View\View|string {
                        $customerId = (int) ($get('customer_id') ?? 0);
                        $invoiceTypeId = (int) ($get('invoice_type_id') ?? 0);
                        if ($customerId <= 0 || $invoiceTypeId <= 0) {
                            return view('filament.whmcs-inbox.preview-partial', [
                                'preview' => 'Επίλεξε πελάτη και τύπο παραστατικού για να δεις την προεπισκόπηση.',
                            ]);
                        }
                        $tenant = Filament::getTenant();
                        $customer = Customer::query()
                            ->where('company_id', $tenant?->getKey())
                            ->whereKey($customerId)
                            ->first();
                        $invoiceType = InvoiceType::query()
                            ->where('company_id', $tenant?->getKey())
                            ->whereKey($invoiceTypeId)
                            ->first();
                        if (! $customer || ! $invoiceType) {
                            return view('filament.whmcs-inbox.preview-partial', [
                                'preview' => 'Δεν βρέθηκε ο πελάτης ή ο τύπος παραστατικού.',
                            ]);
                        }
                        try {
                            $preview = app(WhmcsInvoiceFiler::class)
                                ->preview($tenant, $r, $customer, $invoiceType);
                            return view('filament.whmcs-inbox.preview-partial', ['preview' => $preview]);
                        } catch (\Throwable $e) {
                            return view('filament.whmcs-inbox.preview-partial', [
                                'preview' => 'Σφάλμα προεπισκόπησης: '.$e->getMessage(),
                            ]);
                        }
                    }),
            ])
            ->modalHeading(fn (PendingWhmcsInvoice $r) => 'Καταχώρηση WHMCS #'.$r->whmcs_invoice_id.' στην ΑΑΔΕ')
            ->modalDescription('Επιλέγεις πελάτη και τύπο. Η προεπισκόπηση παρακάτω ανανεώνεται αυτόματα. Πατώντας Καταχώρηση δημιουργείται το παραστατικό στο ekdosi και υποβάλλεται στην ΑΑΔΕ.')
            ->modalSubmitActionLabel('Καταχώρηση στην ΑΑΔΕ')
            ->modalCancelActionLabel('Άκυρο')
            ->modalWidth('5xl')
            ->action(function (PendingWhmcsInvoice $r, array $data) {
                $tenant = Filament::getTenant();
                $customer = Customer::query()
                    ->where('company_id', $tenant->getKey())
                    ->whereKey($data['customer_id'])
                    ->firstOrFail();
                $invoiceType = InvoiceType::query()
                    ->where('company_id', $tenant->getKey())
                    ->whereKey($data['invoice_type_id'])
                    ->firstOrFail();

                try {
                    $filer = app(WhmcsInvoiceFiler::class);
                    $result = $filer->file(
                        tenant: $tenant,
                        pending: $r,
                        customer: $customer,
                        invoiceType: $invoiceType,
                        filedByUserId: auth()->id(),
                    );
                    Notification::make()
                        ->title('Καταχώρηση επιτυχής')
                        ->body('Παραστατικό '.$result->invoice->invcode
                            .($result->mark ? ' με MARK '.$result->mark : ' (off-mode, χωρίς MARK)')
                            .'. Δες το στη λίστα παραστατικών.')
                        ->success()
                        ->persistent()
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Η καταχώρηση ΑΠΕΤΥΧΕ')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();
                }
            });
    }

    private static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Απόρριψη')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (PendingWhmcsInvoice $r) => $r->status === PendingWhmcsInvoice::STATUS_PENDING_REVIEW
                || $r->status === PendingWhmcsInvoice::STATUS_HELD)
            ->form([
                Textarea::make('rejected_reason')
                    ->label('Λόγος απόρριψης (προαιρετικό)')
                    ->placeholder('π.χ. διπλό, ακυρωμένο, λάθος πελάτης, να μην καταχωρηθεί στην ΑΑΔΕ')
                    ->rows(3)
                    ->maxLength(200),
            ])
            ->requiresConfirmation()
            ->modalHeading(fn (PendingWhmcsInvoice $r) => 'Απόρριψη WHMCS #'.$r->whmcs_invoice_id.';')
            ->modalDescription('Δεν θα καταχωρηθεί στην ΑΑΔΕ. Μπορείς να το επανεκκινήσεις αργότερα αν αλλάξεις γνώμη.')
            ->modalSubmitActionLabel('Απόρριψη')
            ->action(function (PendingWhmcsInvoice $r, array $data) {
                $r->update([
                    'status'          => PendingWhmcsInvoice::STATUS_REJECTED,
                    'rejected_reason' => trim((string) ($data['rejected_reason'] ?? '')) ?: null,
                ]);
                Notification::make()->title('Απορρίφθηκε')->success()->send();
            });
    }

    private static function holdAction(): Action
    {
        return Action::make('hold')
            ->label('Σε αναμονή')
            ->icon('heroicon-o-pause-circle')
            ->color('gray')
            ->visible(fn (PendingWhmcsInvoice $r) => $r->status === PendingWhmcsInvoice::STATUS_PENDING_REVIEW)
            ->requiresConfirmation()
            ->modalHeading(fn (PendingWhmcsInvoice $r) => 'Αναμονή για WHMCS #'.$r->whmcs_invoice_id)
            ->modalDescription('Κρύβεται από την προεπιλεγμένη λίστα. Άρε το από κατάσταση = "Σε αναμονή" όταν είσαι έτοιμος.')
            ->action(function (PendingWhmcsInvoice $r) {
                $r->update(['status' => PendingWhmcsInvoice::STATUS_HELD]);
                Notification::make()->title('Μπήκε σε αναμονή')->success()->send();
            });
    }

    private static function reStageAction(): Action
    {
        return Action::make('re_stage')
            ->label('Επαναφορά προς έλεγχο')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (PendingWhmcsInvoice $r) => $r->status === PendingWhmcsInvoice::STATUS_REJECTED
                || $r->status === PendingWhmcsInvoice::STATUS_HELD)
            ->requiresConfirmation()
            ->modalHeading(fn (PendingWhmcsInvoice $r) => 'Επαναφορά WHMCS #'.$r->whmcs_invoice_id)
            ->modalDescription('Επιστρέφει στην κατάσταση "Προς έλεγχο" — διαθέσιμο για καταχώρηση στην ΑΑΔΕ.')
            ->action(function (PendingWhmcsInvoice $r) {
                $r->update([
                    'status'          => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
                    'rejected_reason' => null,
                ]);
                Notification::make()->title('Επαναφέρθηκε προς έλεγχο')->success()->send();
            });
    }
}
