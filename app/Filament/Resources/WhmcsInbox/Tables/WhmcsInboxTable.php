<?php

namespace App\Filament\Resources\WhmcsInbox\Tables;

use App\Models\Customer;
use App\Models\InvoiceType;
use App\Models\PendingWhmcsInvoice;
use App\Services\WhmcsInbox\WhmcsInvoiceFiler;
use App\Services\WhmcsInbox\WhmcsInvoiceSplitter;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
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
                        PendingWhmcsInvoice::REASON_LINKED => 'success',
                        PendingWhmcsInvoice::REASON_AFM => 'success',
                        PendingWhmcsInvoice::REASON_EMAIL => 'info',
                        PendingWhmcsInvoice::REASON_NAME => 'warning',
                        PendingWhmcsInvoice::REASON_UNMATCHED => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('third_party_state')
                    ->label('Τρίτος')
                    ->badge()
                    ->placeholder('—')
                    ->color(fn (?string $state): string => match ($state) {
                        PendingWhmcsInvoice::TP_SINGLE => 'info',
                        PendingWhmcsInvoice::TP_MULTI => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        PendingWhmcsInvoice::TP_SINGLE => 'Σε τρίτο',
                        PendingWhmcsInvoice::TP_MULTI => 'Διαχωρισμός',
                        PendingWhmcsInvoice::TP_NONE => 'Όχι',
                        default => '—',
                    })
                    ->tooltip(fn (PendingWhmcsInvoice $r): ?string => $r->third_party_state === PendingWhmcsInvoice::TP_MULTI
                        ? 'Πολλαπλοί δικαιούχοι σε ένα WHMCS τιμολόγιο — χρειάζεται χειροκίνητος διαχωρισμός.'
                        : null),

                TextColumn::make('status')
                    ->label('Κατάσταση')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        PendingWhmcsInvoice::STATUS_PENDING_REVIEW => 'warning',
                        PendingWhmcsInvoice::STATUS_FILED => 'success',
                        PendingWhmcsInvoice::STATUS_REJECTED => 'danger',
                        PendingWhmcsInvoice::STATUS_HELD => 'gray',
                        PendingWhmcsInvoice::STATUS_SPLIT => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        PendingWhmcsInvoice::STATUS_PENDING_REVIEW => 'Προς έλεγχο',
                        PendingWhmcsInvoice::STATUS_FILED => 'Καταχωρημένο',
                        PendingWhmcsInvoice::STATUS_REJECTED => 'Απορρίφθηκε',
                        PendingWhmcsInvoice::STATUS_HELD => 'Σε αναμονή',
                        PendingWhmcsInvoice::STATUS_SPLIT => 'Διαχωρισμένο',
                        default => $state,
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
                        PendingWhmcsInvoice::STATUS_FILED => 'Καταχωρημένο',
                        PendingWhmcsInvoice::STATUS_REJECTED => 'Απορρίφθηκε',
                        PendingWhmcsInvoice::STATUS_HELD => 'Σε αναμονή',
                        PendingWhmcsInvoice::STATUS_SPLIT => 'Διαχωρισμένο',
                    ])
                    ->default(PendingWhmcsInvoice::STATUS_PENDING_REVIEW),
            ])
            ->recordActions([
                self::fileAtAadeAction(),
                self::splitAction(),
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
            // Tier 1 #1: gate on the policy. WhmcsInboxResource::canAccess
            // intentionally allows any auth'd user to SEE the list (so the
            // resource doesn't 404 between deploy and shield:generate),
            // but every destructive action MUST consult the policy or
            // any reader becomes a filer.
            ->authorize('update')
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
                    ->content(function (Get $get) use ($r): View|string {
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
                        } catch (Throwable $e) {
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
                // Tier 1 #2: include trashed customers in the action's
                // lookup. The Select's getOptionLabelUsing already uses
                // withTrashed so a soft-deleted match is RENDERED in
                // the dropdown ("(διαγραμμένος)" suffix), but without
                // the same withTrashed on the action body's firstOrFail
                // the submission silently 500s with ModelNotFoundException.
                // After the row resolves, refuse the action when the
                // customer is trashed — filing under a soft-deleted
                // customer would propagate stale snapshot data and
                // confuse the operator about who was really billed.
                $customer = Customer::query()
                    ->withTrashed()
                    ->where('company_id', $tenant->getKey())
                    ->whereKey($data['customer_id'])
                    ->firstOrFail();
                if ($customer->trashed()) {
                    Notification::make()
                        ->title('Ο πελάτης είναι διαγραμμένος')
                        ->body('Επανάφερέ τον από τη λίστα πελατών ή επίλεξε άλλον πελάτη πριν την καταχώρηση.')
                        ->warning()
                        ->persistent()
                        ->send();

                    return;
                }
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

    /**
     * T-1c: guided multi-party split. Visible only on multi-party rows.
     * Creates one DRAFT invoice per billing party (operator files each via the
     * normal myDATA submit path) — no risky batch AADE filing here.
     */
    private static function splitAction(): Action
    {
        return Action::make('split_third_party')
            ->label('Διαχωρισμός σε προσχέδια')
            ->icon('heroicon-o-scissors')
            ->color('info')
            ->authorize('update')
            ->visible(fn (PendingWhmcsInvoice $r) => $r->third_party_state === PendingWhmcsInvoice::TP_MULTI
                && in_array($r->status, [PendingWhmcsInvoice::STATUS_HELD, PendingWhmcsInvoice::STATUS_PENDING_REVIEW], true))
            ->form([
                Select::make('invoice_type_id')
                    ->label('Τύπος τιμολογίου')
                    ->options(fn () => InvoiceType::query()
                        ->where('company_id', Filament::getTenant()?->getKey())
                        ->orderBy('code')
                        ->pluck('code', 'id'))
                    ->required()
                    ->searchable()
                    ->helperText('Για τους δικαιούχους που χρειάζονται τιμολόγιο.'),

                Select::make('receipt_type_id')
                    ->label('Τύπος απόδειξης')
                    ->options(fn () => InvoiceType::query()
                        ->where('company_id', Filament::getTenant()?->getKey())
                        ->orderBy('code')
                        ->pluck('code', 'id'))
                    ->searchable()
                    ->helperText('Υποχρεωτικό μόνο αν κάποιος δικαιούχος έχει σημανθεί ως απόδειξη (βλ. λίστα παρακάτω).'),

                Placeholder::make('groups')
                    ->label('Δικαιούχοι που θα προκύψουν')
                    ->content(function (PendingWhmcsInvoice $r): string {
                        $tenant = Filament::getTenant();
                        $groups = app(WhmcsInvoiceSplitter::class)->planGroups($tenant, $r);
                        if ($groups === []) {
                            return 'Δεν βρέθηκαν δικαιούχοι στην ανάλυση.';
                        }
                        $rows = array_map(function (array $g): string {
                            $who = html_entity_decode((string) $g['label'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            $doc = $g['is_receipt'] ? 'απόδειξη' : 'τιμολόγιο';
                            $count = count($g['item_ids']);
                            $resolved = $g['customer'] ? '✓' : '⚠ χωρίς ΑΦΜ/πελάτη';

                            return "• {$who} — {$count} γραμμή(ές), {$doc} [{$resolved}]";
                        }, $groups);

                        return implode("\n", $rows);
                    }),
            ])
            ->modalHeading(fn (PendingWhmcsInvoice $r) => 'Διαχωρισμός WHMCS #'.$r->whmcs_invoice_id.' σε προσχέδια')
            ->modalDescription('Δημιουργούνται ξεχωριστά προσχέδια παραστατικά ανά δικαιούχο. Δεν υποβάλλονται στην ΑΑΔΕ — τα καταχωρείς ένα-ένα από τη λίστα παραστατικών.')
            ->modalSubmitActionLabel('Δημιουργία προσχεδίων')
            ->action(function (PendingWhmcsInvoice $r, array $data) {
                $tenant = Filament::getTenant();
                $invoiceType = InvoiceType::query()
                    ->where('company_id', $tenant->getKey())
                    ->whereKey($data['invoice_type_id'])
                    ->firstOrFail();
                $receiptType = ! empty($data['receipt_type_id'])
                    ? InvoiceType::query()
                        ->where('company_id', $tenant->getKey())
                        ->whereKey($data['receipt_type_id'])
                        ->first()
                    : null;
                try {
                    $invoices = app(WhmcsInvoiceSplitter::class)
                        ->split($tenant, $r, $invoiceType, $receiptType, auth()->id());
                    $codes = implode(', ', array_map(fn ($i) => $i->invcode, $invoices));
                    Notification::make()
                        ->title('Δημιουργήθηκαν '.count($invoices).' προσχέδια')
                        ->body('Παραστατικά: '.$codes.'. Κατάχώρησε το καθένα στην ΑΑΔΕ από τη λίστα παραστατικών.')
                        ->success()
                        ->persistent()
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Ο διαχωρισμός απέτυχε')
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
            ->authorize('update')
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
                    'status' => PendingWhmcsInvoice::STATUS_REJECTED,
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
            ->authorize('update')
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
            ->authorize('update')
            ->visible(fn (PendingWhmcsInvoice $r) => $r->status === PendingWhmcsInvoice::STATUS_REJECTED
                || $r->status === PendingWhmcsInvoice::STATUS_HELD)
            ->requiresConfirmation()
            ->modalHeading(fn (PendingWhmcsInvoice $r) => 'Επαναφορά WHMCS #'.$r->whmcs_invoice_id)
            ->modalDescription('Επιστρέφει στην κατάσταση "Προς έλεγχο" — διαθέσιμο για καταχώρηση στην ΑΑΔΕ.')
            ->action(function (PendingWhmcsInvoice $r) {
                $r->update([
                    'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
                    'rejected_reason' => null,
                ]);
                Notification::make()->title('Επαναφέρθηκε προς έλεγχο')->success()->send();
            });
    }
}
