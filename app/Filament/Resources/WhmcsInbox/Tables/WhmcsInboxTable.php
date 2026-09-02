<?php

namespace App\Filament\Resources\WhmcsInbox\Tables;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\PickerOptions;
use App\Models\Company;
use App\Models\Customer;
use App\Models\InvoiceType;
use App\Models\PendingWhmcsInvoice;
use App\Services\Billing\BillingSourceRegistry;
use App\Services\Whmcs\LegacyInvoicedRefresher;
use App\Services\Whmcs\WhmcsCustomerCreateResult;
use App\Services\Whmcs\WhmcsCustomerCreator;
use App\Services\Whmcs\WhmcsInvoiceIngestor;
use App\Services\Whmcs\WhmcsWritebackService;
use App\Services\WhmcsInbox\WhmcsInvoiceFiler;
use App\Services\WhmcsInbox\WhmcsInvoiceSplitter;
use App\Support\Afm;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\HtmlString;
use Livewire\Component;
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
        // Bridges/Connectors: resolve source-key → label ONCE from the registry
        // (not per row). An unregistered source key on a row then falls back to
        // its upper-cased key WITHOUT spamming a "unknown source" warning per row
        // (BillingSourceRegistry::for() logs + doesn't cache null on miss).
        $sourceLabels = [];
        foreach (app(BillingSourceRegistry::class)->all() as $key => $src) {
            $sourceLabels[$key] = $src->label();
        }

        return $table
            ->modifyQueryUsing(function (Builder $query) {
                $tenant = Filament::getTenant();
                $query->where('company_id', $tenant?->getKey() ?? 0)
                    ->with(['customer:id,name,afm,needs_immediate_invoice', 'filedByUser:id,name', 'company:id,whmcs_custom_field_map']);
            })
            ->columns([
                // Bridges/Connectors: which billing source this row came from. One
                // «Εισερχόμενα» for every bridge; the badge label comes from the
                // source's registry entry (so a future WooCommerce row reads its own
                // label from one place). WHMCS-only today, but already source-driven.
                TextColumn::make('source')
                    ->label('Πηγή')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => $sourceLabels[(string) $state]
                        ?? strtoupper((string) ($state ?? '—'))),

                TextColumn::make('whmcs_invoice_id')
                    // Phase 0 (Bridges/Connectors): the external-id label comes
                    // from the billing source's capabilities, so a future source
                    // reads «WooCommerce #» from one place. WHMCS-only today.
                    ->label(app(BillingSourceRegistry::class)
                        ->for(PendingWhmcsInvoice::SOURCE_WHMCS)?->capabilities()->externalIdLabel ?? 'WHMCS #')
                    ->sortable()
                    ->searchable()
                    ->prefix('#')
                    ->color('primary')
                    ->tooltip('Προβολή ολόκληρου του WHMCS τιμολογίου')
                    // E: click the # → full invoice view, rendered from the
                    // staged payload (no live API call).
                    ->action(
                        Action::make('view_whmcs')
                            ->modalHeading(fn (PendingWhmcsInvoice $r) => 'WHMCS τιμολόγιο #'.$r->whmcs_invoice_id)
                            ->modalContent(fn (PendingWhmcsInvoice $r) => view('filament.whmcs-inbox.invoice-view', ['r' => $r]))
                            ->modalSubmitAction(false)
                            ->modalCancelActionLabel('Κλείσιμο')
                            ->modalWidth('3xl')
                    ),

                TextColumn::make('payload.date')
                    ->label('Ημ/νία τιμολ.')
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

                // WHMCS payment status: Unpaid rows should be issued επί πιστώσει
                // (open receivable), Paid rows settled at issue. Drives the draft's
                // pre-selected type; surfaced so the operator sees it at a glance.
                TextColumn::make('whmcs_paid_status')
                    ->label('Πληρωμή WHMCS')
                    ->badge()
                    ->state(fn (PendingWhmcsInvoice $r): ?string => match (true) {
                        $r->whmcsIsUnpaid() => 'Απλήρωτο',
                        strcasecmp((string) $r->whmcsStatus(), 'Paid') === 0 => 'Πληρωμένο',
                        $r->whmcsStatus() !== null => $r->whmcsStatus(),   // Cancelled/Refunded raw
                        default => null,
                    })
                    ->color(fn (PendingWhmcsInvoice $r): string => $r->whmcsIsUnpaid()
                        ? 'warning'
                        : (strcasecmp((string) $r->whmcsStatus(), 'Paid') === 0 ? 'success' : 'gray'))
                    ->toggleable(),

                // Who the invoice is from on the WHMCS side — always shown,
                // even for unmatched rows, so the operator has the full picture
                // (#xxxx from WHMCS client "X") and knows who to link/import.
                TextColumn::make('whmcs_client')
                    ->label('Πελάτης (WHMCS)')
                    ->state(fn (PendingWhmcsInvoice $r): string => $r->whmcsClientName() ?? '—')
                    ->description(fn (PendingWhmcsInvoice $r): ?string => $r->whmcsAfm()
                        ? 'ΑΦΜ '.$r->whmcsAfm()
                        : null),

                // Recipient of the ekdosi invoice — ONE column: the third-party
                // beneficiary when the WHMCS lines route to one, else the matched
                // ekdosi customer (ΑΦΜ-only match). «— μη συνδεδεμένος —» = no ΑΦΜ
                // match → operator links or clicks «Δημ. πελάτη (ΑΑΔΕ)». Replaces
                // the old split «Πελάτης (ekdosi)» + «Τρίτος» columns.
                TextColumn::make('customer.name')
                    ->label('Παραλήπτης (ekdosi)')
                    ->placeholder('— μη συνδεδεμένος —')
                    ->state(fn (PendingWhmcsInvoice $r): ?string => match ($r->third_party_state) {
                        PendingWhmcsInvoice::TP_SINGLE => self::firstBeneficiaryName($r) ?? 'Σε τρίτο',
                        PendingWhmcsInvoice::TP_MULTI => 'Διαχωρισμός σε δικαιούχους',
                        default => $r->customer?->name,
                    })
                    ->color(fn (PendingWhmcsInvoice $r): ?string => match ($r->third_party_state) {
                        PendingWhmcsInvoice::TP_MULTI => 'warning',
                        PendingWhmcsInvoice::TP_SINGLE => 'info',
                        default => null,
                    })
                    ->description(fn (PendingWhmcsInvoice $r): ?string => match ($r->third_party_state) {
                        PendingWhmcsInvoice::TP_SINGLE => 'Τρίτος δικαιούχος',
                        PendingWhmcsInvoice::TP_MULTI => 'Πολλαπλοί — χρειάζεται διαχωρισμός',
                        default => $r->customer?->afm ? 'ΑΦΜ '.$r->customer->afm : null,
                    })
                    ->tooltip(function (PendingWhmcsInvoice $r): ?string {
                        $names = self::beneficiaryNames($r);
                        if ($r->third_party_state === PendingWhmcsInvoice::TP_MULTI) {
                            return 'Πολλαπλοί δικαιούχοι ('.implode(' · ', $names).') — χρειάζεται χειροκίνητος διαχωρισμός.';
                        }
                        if ($r->third_party_state === PendingWhmcsInvoice::TP_SINGLE && $names !== []) {
                            return 'Δικαιούχος: '.$names[0];
                        }

                        return null;
                    })
                    ->searchable(),

                // G8 (phase 1): άμεση-τιμολόγηση / immediate-invoicing heads-up. A
                // matched customer flagged needs_immediate_invoice wants their
                // παραστατικό issued ASAP — surface it so the operator
                // prioritises this row. Warning only here; auto-issue is a
                // separate, default-OFF knob (see CLAUDE.md G8).
                TextColumn::make('immediate')
                    ->label('Άμεσο')
                    ->badge()
                    ->color('danger')
                    ->icon('heroicon-o-bolt')
                    ->placeholder('—')
                    ->state(fn (PendingWhmcsInvoice $r): ?string => $r->customer?->needs_immediate_invoice
                        ? 'Άμεσο'
                        : null)
                    ->tooltip('Ο πελάτης ζητά άμεση τιμολόγηση — δώσε προτεραιότητα.'),

                // Scannable third-party flag: lights up when the WHMCS invoice
                // routes (some/all) lines to a beneficiary other than the client.
                // Single → the beneficiary name; multi → «Πολλοί (N)» (needs split).
                // The «Παραλήπτης» column carries the detail; this is the at-a-glance
                // «έχει τρίτο;» badge the operator scans for.
                TextColumn::make('third_party')
                    ->label('Τρίτος')
                    ->badge()
                    ->icon('heroicon-o-users')
                    ->placeholder('—')
                    ->state(fn (PendingWhmcsInvoice $r): ?string => match ($r->third_party_state) {
                        PendingWhmcsInvoice::TP_SINGLE => self::firstBeneficiaryName($r) ?? 'Τρίτος',
                        PendingWhmcsInvoice::TP_MULTI => ($n = count(self::beneficiaryNames($r))) > 0 ? "Πολλοί ({$n})" : 'Πολλοί',
                        default => null,
                    })
                    ->color(fn (PendingWhmcsInvoice $r): string => $r->third_party_state === PendingWhmcsInvoice::TP_MULTI ? 'warning' : 'info')
                    ->tooltip(fn (PendingWhmcsInvoice $r): ?string => ($n = self::beneficiaryNames($r)) !== [] ? 'Κλικ για ανάλυση ανά γραμμή · '.implode(' · ', $n) : null)
                    // Click the badge → per-line routing preview. Only meaningful for
                    // third-party rows; the '—' placeholder on plain rows isn't clickable.
                    ->disabledClick(fn (PendingWhmcsInvoice $r): bool => ! in_array(
                        $r->third_party_state,
                        [PendingWhmcsInvoice::TP_SINGLE, PendingWhmcsInvoice::TP_MULTI],
                        true,
                    ))
                    ->action(self::viewRoutingAction()),

                TextColumn::make('status')
                    ->label('Κατάσταση')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        PendingWhmcsInvoice::STATUS_PENDING_REVIEW => 'warning',
                        PendingWhmcsInvoice::STATUS_FILED => 'success',
                        PendingWhmcsInvoice::STATUS_REJECTED => 'danger',
                        PendingWhmcsInvoice::STATUS_HELD => 'gray',
                        PendingWhmcsInvoice::STATUS_SPLIT => 'info',
                        PendingWhmcsInvoice::STATUS_DRAFTED => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        PendingWhmcsInvoice::STATUS_PENDING_REVIEW => 'Προς έλεγχο',
                        PendingWhmcsInvoice::STATUS_FILED => 'Καταχωρημένο',
                        PendingWhmcsInvoice::STATUS_REJECTED => 'Απορρίφθηκε',
                        PendingWhmcsInvoice::STATUS_HELD => 'Σε αναμονή',
                        PendingWhmcsInvoice::STATUS_SPLIT => 'Διαχωρισμένο',
                        PendingWhmcsInvoice::STATUS_DRAFTED => 'Προσχέδιο',
                        default => $state,
                    })
                    // Surface WHY a row is held (e.g. "Αναμονή για ΑΦΜ") /
                    // why it was rejected, without opening it.
                    ->tooltip(fn (PendingWhmcsInvoice $r): ?string => match ($r->status) {
                        PendingWhmcsInvoice::STATUS_HELD => $r->hold_reason,
                        PendingWhmcsInvoice::STATUS_REJECTED => $r->rejected_reason,
                        default => null,
                    }),

                TextColumn::make('mydata_mark')
                    ->label('MARK')
                    ->copyable()
                    ->placeholder('—')
                    ->fontFamily('mono')
                    // Empty for «προς έλεγχο» rows — hidden by default, on demand
                    // via the column toggle.
                    ->toggleable(isToggledHiddenByDefault: true),

                // WH-7: MARK write-back state. A filed row whose write-back FAILED
                // otherwise shows a green «Καταχωρημένο» with a MARK and nothing
                // signals the WHMCS bookkeeping is broken. «Απέτυχε» (red) is the
                // findable signal + the retry action's trigger.
                TextColumn::make('whmcs_writeback_state')
                    ->label('Επιστροφή ΜΑΡΚ')
                    ->badge()
                    ->placeholder('—')
                    ->state(fn (PendingWhmcsInvoice $r): ?string => match ($r->whmcs_writeback_state) {
                        PendingWhmcsInvoice::WRITEBACK_SUCCEEDED => 'Στο WHMCS',
                        PendingWhmcsInvoice::WRITEBACK_FAILED => 'Απέτυχε',
                        PendingWhmcsInvoice::WRITEBACK_PENDING => 'Εκκρεμεί',
                        PendingWhmcsInvoice::WRITEBACK_SKIPPED => 'Χωρίς γέφυρα',
                        default => null,
                    })
                    ->color(fn (PendingWhmcsInvoice $r): string => match ($r->whmcs_writeback_state) {
                        PendingWhmcsInvoice::WRITEBACK_SUCCEEDED => 'success',
                        PendingWhmcsInvoice::WRITEBACK_FAILED => 'danger',
                        PendingWhmcsInvoice::WRITEBACK_PENDING => 'warning',
                        default => 'gray',
                    })
                    ->icon(fn (PendingWhmcsInvoice $r): ?string => $r->whmcs_writeback_state === PendingWhmcsInvoice::WRITEBACK_FAILED
                        ? 'heroicon-o-exclamation-triangle'
                        : null)
                    ->tooltip(fn (PendingWhmcsInvoice $r): ?string => $r->whmcs_writeback_state === PendingWhmcsInvoice::WRITEBACK_FAILED
                        ? ($r->whmcs_writeback_error ?: 'Η επιστροφή του ΜΑΡΚ στο WHMCS απέτυχε. Το AADE είναι εντάξει· πάτα «Επανάληψη επιστροφής ΜΑΡΚ».')
                        : null)
                    ->toggleable(isToggledHiddenByDefault: true),

                // Dual-run heads-up: this WHMCS invoice has ALSO been invoiced
                // in the LEGACY ekdosi app (tblinvoices.invoiced != 0). Three
                // visible states so the operator can tell a CHECK ran:
                //   >0 (red)  «Στην παλιά» — already invoiced in the old app
                //   0  (gray) «Όχι»        — checked, not invoiced in legacy
                //   null      «—»          — not checked yet (run «Έλεγχος legacy»)
                // Populated by whmcs:fetch-pending + the «Έλεγχος legacy» action.
                TextColumn::make('legacy_invoiced')
                    ->label('Legacy')
                    ->badge()
                    ->placeholder('—')
                    ->state(fn (PendingWhmcsInvoice $r): ?string => match (true) {
                        $r->invoicedInLegacy() => 'Στην παλιά',
                        $r->legacy_invoiced === 0 => 'Όχι',
                        default => null,
                    })
                    ->color(fn (PendingWhmcsInvoice $r): string => $r->invoicedInLegacy() ? 'danger' : 'gray')
                    ->icon(fn (PendingWhmcsInvoice $r): ?string => $r->invoicedInLegacy()
                        ? 'heroicon-o-exclamation-triangle'
                        : null)
                    ->tooltip(fn (PendingWhmcsInvoice $r): ?string => match (true) {
                        $r->invoicedInLegacy() => 'Έχει ήδη τιμολογηθεί στην παλιά εφαρμογή ekdosi. Μην το ξαναεκδώσεις εδώ — θα γίνει διπλή υποβολή στην ΑΑΔΕ.',
                        $r->legacy_invoiced === 0 => 'Ελέγχθηκε — δεν έχει τιμολογηθεί στην παλιά εφαρμογή.',
                        default => 'Δεν έχει ελεγχθεί ακόμη. Πάτα «Έλεγχος legacy» για να ρωτήσει τη γέφυρα.',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Συγχρ.')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->tooltip('Πότε συγχρονίστηκε στο inbox'),
            ])
            // DEFAULT order (overridable by a column-header click — Filament appends
            // the user's sort BEFORE this closure, so a manual sort stays primary and
            // this only acts as the default + tiebreaker): «άμεση τιμολόγηση» rows
            // float to the top, then newest first. COALESCE(...,0) makes the NULL
            // (unmatched) case deterministic across MariaDB/sqlite.
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw('coalesce((select c.needs_immediate_invoice from customers c where c.id = pending_whmcs_invoices.customer_id limit 1), 0) desc')
                ->orderByDesc('created_at'))
            // Auto-refresh so a freshly-paid (immediate) row surfaces within ~30s
            // without a manual reload — pairs with the bell notification.
            ->poll('30s')
            ->filters([
                SelectFilter::make('status')
                    ->label('Κατάσταση')
                    ->options([
                        PendingWhmcsInvoice::STATUS_PENDING_REVIEW => 'Προς έλεγχο',
                        PendingWhmcsInvoice::STATUS_FILED => 'Καταχωρημένο',
                        PendingWhmcsInvoice::STATUS_REJECTED => 'Απορρίφθηκε',
                        PendingWhmcsInvoice::STATUS_HELD => 'Σε αναμονή',
                        PendingWhmcsInvoice::STATUS_SPLIT => 'Διαχωρισμένο',
                        PendingWhmcsInvoice::STATUS_DRAFTED => 'Προσχέδιο',
                    ])
                    ->default(PendingWhmcsInvoice::STATUS_PENDING_REVIEW),

                // Filter on the legacy-invoiced flag (the dual-run «τιμολογήθηκε
                // στην παλιά εφαρμογή» signal). >0 = invoiced in legacy, 0 =
                // not, null = not checked yet.
                SelectFilter::make('legacy_invoiced')
                    ->label('Legacy (παλιά εφαρμογή)')
                    ->options([
                        'yes' => 'Τιμολογήθηκε στη legacy',
                        'no' => 'Όχι στη legacy',
                        'unknown' => 'Άγνωστο (δεν ελέγχθηκε)',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'yes' => $query->where('legacy_invoiced', '>', 0),
                        'no' => $query->where('legacy_invoiced', 0),
                        'unknown' => $query->whereNull('legacy_invoiced'),
                        default => $query,
                    }),

                // Isolate the rows the operator scans for most.
                SelectFilter::make('immediate')
                    ->label('Άμεσο')
                    ->options(['yes' => 'Άμεσα μόνο'])
                    ->query(fn (Builder $query, array $data): Builder => ($data['value'] ?? null) === 'yes'
                        ? $query->whereHas('customer', fn (Builder $q) => $q->where('needs_immediate_invoice', true))
                        : $query),

                // WH-7: surface failed MARK write-backs (AADE OK, WHMCS bookkeeping
                // stuck) — the rows the «Επανάληψη επιστροφής ΜΑΡΚ» action targets.
                SelectFilter::make('whmcs_writeback_state')
                    ->label('Επιστροφή ΜΑΡΚ')
                    ->options([
                        PendingWhmcsInvoice::WRITEBACK_FAILED => 'Απέτυχε',
                        PendingWhmcsInvoice::WRITEBACK_PENDING => 'Εκκρεμεί',
                        PendingWhmcsInvoice::WRITEBACK_SUCCEEDED => 'Στο WHMCS',
                    ]),

                SelectFilter::make('third_party')
                    ->label('Τρίτος')
                    ->options([
                        'any' => 'Με τρίτο',
                        PendingWhmcsInvoice::TP_MULTI => 'Πολλαπλοί (split)',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'any' => $query->whereIn('third_party_state', [PendingWhmcsInvoice::TP_SINGLE, PendingWhmcsInvoice::TP_MULTI]),
                        PendingWhmcsInvoice::TP_MULTI => $query->where('third_party_state', PendingWhmcsInvoice::TP_MULTI),
                        default => $query,
                    }),
            ])
            ->headerActions([
                self::syncNowAction(),
                self::refreshLegacyInvoicedAction(),
            ])
            ->recordActions([
                // Only the one action you do most stays inline — «Δημιουργία
                // Παραστατικού». Everything else (including «Άνοιγμα», kept first)
                // collapses into a «…» dropdown so the row doesn't sprawl.
                self::createDraftAction(),
                // Direct «Διαχωρισμός» button on multi-party rows (visible() gates
                // it to TP_MULTI) so splitting is one click, not buried in «…».
                self::splitAction(),
                ActionGroup::make([
                    self::openInvoiceAction(),
                    self::retryWritebackAction(),
                    self::createCustomerAction(),
                    self::reResolveThirdPartyAction(),
                    self::holdAction(),
                    self::reStageAction(),
                    self::rejectAction(),
                    self::deleteRowAction(),
                ])
                    ->label('Ενέργειες')
                    ->icon('heroicon-m-ellipsis-horizontal')
                    ->button()
                    ->color('gray'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::deleteSelectedAction(),
                ]),
            ]);
    }

    /**
     * Which rows are safe to HARD-delete: only the disposable ones (test /
     * internal / accidental pushes). A row is deletable when it's still in a
     * pre-draft state (pending_review / held / rejected) AND has produced no
     * ekdosi invoice. Filed rows (legal WHMCS↔MARK link), drafted/split rows
     * (would orphan their draft invoices), and anything carrying a MARK are
     * NEVER deletable here — reject those instead.
     */
    /**
     * Invoice-type options for the third-party split modal (invoice + receipt
     * selectors). Monetary types only — a split party is always billed a real
     * invoice/receipt, never a movement-only 9.x Δελτίο Αποστολής (MYD-003). One
     * shared query so the two selectors cannot drift.
     *
     * @return array<int, string>
     */
    private static function splitTypeOptions(): array
    {
        return InvoiceType::query()
            ->where('company_id', Filament::getTenant()?->getKey())
            ->monetary()
            ->orderBy('code')
            ->pluck('code', 'id')
            ->all();
    }

    private static function isDeletable(PendingWhmcsInvoice $r): bool
    {
        return $r->invoice_id === null
            && $r->mydata_mark === null
            && in_array($r->status, [
                PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
                PendingWhmcsInvoice::STATUS_HELD,
                PendingWhmcsInvoice::STATUS_REJECTED,
            ], true);
    }

    /**
     * Per-row hard delete — removes the staged row entirely (frees the
     * (company_id, whmcs_invoice_id) slot so a re-push re-creates it). The clean
     * way to clear test / internal / mistaken pushes, and to reset while
     * validating the bridge→inbox flow. Gated by the Delete policy (admin-level,
     * not operators) AND isDeletable().
     */
    private static function deleteRowAction(): Action
    {
        return Action::make('delete_row')
            ->label('Διαγραφή')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->authorize('delete')
            ->visible(fn (PendingWhmcsInvoice $r) => self::isDeletable($r))
            ->requiresConfirmation()
            ->modalHeading(fn (PendingWhmcsInvoice $r) => 'Οριστική διαγραφή WHMCS #'.$r->whmcs_invoice_id.';')
            ->modalDescription('Διαγράφεται οριστικά η εγγραφή από το inbox (όχι από το WHMCS). Χρήσιμο για δοκιμές / εσωτερικά / λάθος τιμολόγια. Αν ξανασταλεί από το WHMCS, θα ξαναεμφανιστεί.')
            ->modalSubmitActionLabel('Διαγραφή')
            ->action(function (PendingWhmcsInvoice $r) {
                if (! self::isDeletable($r)) {
                    Notification::make()->title('Δεν διαγράφεται (έχει παραστατικό ή MARK)')->danger()->send();

                    return;
                }
                $r->delete();
                Notification::make()->title('Διαγράφηκε')->success()->send();
            });
    }

    /**
     * Bulk hard delete — checkboxes → «Διαγραφή επιλεγμένων». Skips any selected
     * row that isn't disposable (filed / drafted / split / has a MARK) and
     * reports the count, so a mixed selection can't nuke a legal record.
     */
    private static function deleteSelectedAction(): BulkAction
    {
        return BulkAction::make('delete_selected')
            ->label('Διαγραφή επιλεγμένων')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->authorize('deleteAny')
            ->requiresConfirmation()
            ->modalHeading('Διαγραφή επιλεγμένων εγγραφών inbox')
            ->modalDescription('Διαγράφονται μόνο οι αναλώσιμες (προς έλεγχο / σε αναμονή / απορριφθείσες, χωρίς παραστατικό ή MARK). Όσες έχουν εκδοθεί/φιλιαριστεί παραλείπονται.')
            ->modalSubmitActionLabel('Διαγραφή')
            ->action(function (Collection $records): void {
                $deleted = 0;
                $skipped = 0;
                foreach ($records as $record) {
                    if (self::isDeletable($record)) {
                        $record->delete();
                        $deleted++;
                    } else {
                        $skipped++;
                    }
                }

                Notification::make()
                    ->title("Διαγράφηκαν: {$deleted}".($skipped > 0 ? " · Παραλείφθηκαν: {$skipped}" : ''))
                    ->{$skipped > 0 ? 'warning' : 'success'}()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Collapse the stored third-party resolution into a per-beneficiary list
     * for the createDraft modal: each routed contact with ΑΦΜ + line count +
     * receipt flag. Empty when nothing routed (or no resolution stored) — the
     * modal placeholder is hidden in that case.
     *
     * @return list<array{name: string, afm: string, lines: int, is_receipt: bool}>
     */
    private static function routedBeneficiaries(PendingWhmcsInvoice $r): array
    {
        $resolution = $r->third_party_resolution;
        $lines = is_array($resolution) ? ($resolution['lines'] ?? []) : [];
        if (! is_array($lines)) {
            return [];
        }

        $byContact = [];
        foreach ($lines as $line) {
            if (! is_array($line) || empty($line['routed']) || empty($line['contact']) || ! is_array($line['contact'])) {
                continue;
            }
            $contact = $line['contact'];
            $id = (int) ($contact['id'] ?? 0);
            if (! isset($byContact[$id])) {
                $byContact[$id] = [
                    'name' => html_entity_decode((string) ($contact['company_name'] ?? '—'), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'afm' => Afm::digits($contact['gr_vatno'] ?? null),
                    'lines' => 0,
                    'is_receipt' => false,
                ];
            }
            $byContact[$id]['lines']++;
            if (! empty($line['is_receipt'])) {
                $byContact[$id]['is_receipt'] = true;
            }
        }

        return array_values($byContact);
    }

    /** @return list<string> distinct routed-beneficiary names for the row. */
    private static function beneficiaryNames(PendingWhmcsInvoice $r): array
    {
        return array_values(array_filter(array_map(
            static fn (array $b): string => (string) ($b['name'] ?? ''),
            self::routedBeneficiaries($r),
        ), static fn (string $n): bool => $n !== '' && $n !== '—'));
    }

    /** The single beneficiary's name (for the «Τρίτος» badge), or null. */
    private static function firstBeneficiaryName(PendingWhmcsInvoice $r): ?string
    {
        $names = self::beneficiaryNames($r);

        return $names[0] ?? null;
    }

    /**
     * Per-LINE routing for the preview modal: each WHMCS line → who it bills →
     * ΑΦΜ → Τιμολόγιο/Απόδειξη. Mirrors the WHMCS-side «Δρομολόγηση υπηρεσιών»
     * screen so the operator SEES which line goes where before splitting. Own
     * (non-routed) lines bill the WHMCS client and take the primary's type
     * (ownLinesAreReceipt); routed lines take the route's explicit is_receipt and
     * go to the contact.
     *
     * @return list<array{line:string,who:string,afm:string,receipt:bool,routed:bool}>
     */
    private static function routingRows(PendingWhmcsInvoice $r): array
    {
        $lines = $r->third_party_resolution['lines'] ?? [];
        if (! is_array($lines) || $lines === []) {
            return [];
        }

        $decode = static fn (string $s): string => html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $ownReceipt = $r->ownLinesAreReceipt();
        $ownName = $r->customer?->name ?? $r->whmcsClientName() ?? 'Πελάτης WHMCS';
        $ownAfm = $r->customer?->afm ?? $r->whmcsAfm();

        $out = [];
        foreach ($lines as $l) {
            if (! is_array($l)) {
                continue; // defensive: a malformed (scalar) line entry
            }
            $routed = ! empty($l['routed']) && ! empty($l['contact']);
            $out[] = [
                'line' => $decode((string) ($l['description'] ?? '—')),
                'who' => $routed
                    ? $decode((string) ($l['contact']['company_name'] ?? 'Τρίτος'))
                    : $decode($ownName).' (ίδιος)',
                'afm' => $routed ? (string) ($l['contact']['gr_vatno'] ?? '') : (string) ($ownAfm ?? ''),
                'receipt' => $routed ? (bool) ($l['is_receipt'] ?? false) : $ownReceipt,
                'routed' => $routed,
            ];
        }

        return $out;
    }

    /** Read-only per-line routing preview, opened by clicking the «Τρίτος» cell. */
    private static function viewRoutingAction(): Action
    {
        return Action::make('view_routing')
            ->modalHeading(fn (PendingWhmcsInvoice $r) => 'Δρομολόγηση WHMCS #'.$r->whmcs_invoice_id)
            ->modalContent(fn (PendingWhmcsInvoice $r) => view('filament.whmcs-inbox.third-party-routing', [
                'rows' => self::routingRows($r),
                'isMulti' => $r->third_party_state === PendingWhmcsInvoice::TP_MULTI,
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Κλείσιμο')
            ->modalWidth('2xl');
    }

    /**
     * Dual-run: ask the bridge whether any of the still-actionable inbox rows
     * (προς έλεγχο / σε αναμονή) have meanwhile been invoiced in the LEGACY
     * ekdosi app, and refresh the «Legacy» column. Lets the operator spot —
     * before issuing — an invoice the partner already filed from the old app.
     * No-op (and a friendly notice) when the bridge isn't configured/reachable.
     */
    /**
     * «Συγχρονισμός τώρα» — pull paid+unfiled WHMCS invoices into the inbox on
     * demand (the manual twin of the scheduled whmcs:fetch-pending). Handy for
     * testing without SSH/cron. Delegates to the SAME command, so source
     * selection (bridge vs native) + legacy refresh are identical.
     */
    private static function syncNowAction(): Action
    {
        return Action::make('sync_now')
            ->label('Συγχρονισμός τώρα')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('primary')
            ->authorize('update')
            ->requiresConfirmation()
            ->modalHeading('Συγχρονισμός τώρα από το WHMCS;')
            ->modalDescription('Τραβά τα πληρωμένα/μη-εκδομένα τιμολόγια από το WHMCS και τα στάζει στο inbox (ίδιο με το προγραμματισμένο whmcs:fetch-pending). Idempotent — ασφαλές να ξανατρέξει. Μεγάλος tenant μπορεί να αργήσει λίγο.')
            ->modalSubmitActionLabel('Συγχρονισμός')
            ->action(function () {
                $tenant = Filament::getTenant();
                if (! $tenant) {
                    Notification::make()->title('Δεν βρέθηκε tenant')->danger()->send();

                    return;
                }
                if (empty($tenant->whmcs_api_url)) {
                    Notification::make()->title('Δεν έχει ρυθμιστεί WHMCS γι\' αυτόν τον tenant')->warning()->send();

                    return;
                }
                try {
                    $exit = Artisan::call('whmcs:fetch-pending', ['--tenant' => $tenant->slug]);
                } catch (Throwable $e) {
                    Notification::make()->title('Ο συγχρονισμός απέτυχε')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }
                $summary = collect(preg_split('/\r?\n/', trim(Artisan::output())))
                    ->first(fn ($l) => str_contains((string) $l, 'Summary')) ?: 'Έγινε.';
                Notification::make()
                    ->title($exit === 0 ? 'Συγχρονισμός ολοκληρώθηκε' : 'Συγχρονισμός με προειδοποιήσεις (exit '.$exit.')')
                    ->body((string) $summary)
                    ->{$exit === 0 ? 'success' : 'warning'}()
                    ->send();
            });
    }

    /**
     * WH-7: retry a FAILED MARK write-back for a single row. The AADE filing is
     * already the legal truth; this re-pushes the MARK to WHMCS so the badge /
     * ledger catch up. Visible only on a failed row; reuses the same
     * WhmcsWritebackService::retryWriteback() the batch command uses (touches
     * only the audit-freeze-whitelisted columns, skips split rows).
     */
    private static function retryWritebackAction(): Action
    {
        return Action::make('retry_writeback')
            ->label('Επανάληψη επιστροφής ΜΑΡΚ')
            ->icon('heroicon-o-arrow-path-rounded-square')
            ->color('warning')
            ->authorize('update')
            ->visible(fn (PendingWhmcsInvoice $r) => $r->whmcs_writeback_state === PendingWhmcsInvoice::WRITEBACK_FAILED)
            ->requiresConfirmation()
            ->modalHeading('Επανάληψη επιστροφής ΜΑΡΚ στο WHMCS')
            ->modalDescription('Το παραστατικό έχει ήδη υποβληθεί στην ΑΑΔΕ (το ΜΑΡΚ είναι έγκυρο) — απέτυχε μόνο η ενημέρωση του WHMCS. Ξαναστέλνει το ΜΑΡΚ στη γέφυρα. Δεν αγγίζει την ΑΑΔΕ.')
            ->modalSubmitActionLabel('Επανάληψη τώρα')
            ->action(function (PendingWhmcsInvoice $record) {
                try {
                    $state = app(WhmcsWritebackService::class)->retryWriteback($record);
                    if ($state === PendingWhmcsInvoice::WRITEBACK_SUCCEEDED) {
                        Notification::make()
                            ->title('Το ΜΑΡΚ επιστράφηκε στο WHMCS')
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('Η επιστροφή ΜΑΡΚ απέτυχε ξανά')
                            ->body($record->fresh()->whmcs_writeback_error ?: 'Δες τα logs. Η γέφυρα ίσως είναι εκτός.')
                            ->danger()
                            ->send();
                    }
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Δεν έγινε επανάληψη')
                        ->body($e->getMessage())
                        ->warning()
                        ->send();
                }
            });
    }

    private static function refreshLegacyInvoicedAction(): Action
    {
        return Action::make('refresh_legacy_invoiced')
            ->label('Έλεγχος legacy')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Έλεγχος: τιμολογήθηκαν στην παλιά εφαρμογή;')
            ->modalDescription('Ρωτά τη γέφυρα WHMCS αν κάποια από τα τιμολόγια «προς έλεγχο» ή «σε αναμονή» έχουν ήδη τιμολογηθεί στην παλιά εφαρμογή ekdosi, και ενημερώνει τη στήλη «Legacy». Χρήσιμο στη φάση που εκδίδεις ακόμη από την παλιά εφαρμογή, για να μην κάνεις διπλό τιμολόγιο.')
            ->modalSubmitActionLabel('Έλεγχος τώρα')
            ->action(function () {
                $tenant = Filament::getTenant();
                try {
                    $changed = app(LegacyInvoicedRefresher::class)->refresh($tenant);
                    Notification::make()
                        ->title($changed > 0
                            ? $changed.' τιμολόγιο(α) σημάνθηκαν ως «τιμολογημένα στη legacy»'
                            : 'Καμία αλλαγή — τίποτα νέο δεν τιμολογήθηκε στη legacy')
                        ->success()
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Ο έλεγχος legacy απέτυχε')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Slice 3: create the ekdosi customer from the WHMCS ΑΦΜ when it isn't in
     * ekdosi yet — official GSIS data wins, WHMCS-typed data is the fallback
     * (validates the ΑΦΜ in passing), and the new customer is linked to this
     * row. Visible only on a pending row that carries a WHMCS ΑΦΜ but has no
     * matched ekdosi customer.
     */
    private static function createCustomerAction(): Action
    {
        return Action::make('create_customer')
            ->label('Δημ. πελάτη (ΑΑΔΕ)')
            ->icon('heroicon-o-user-plus')
            ->color('success')
            ->authorize('update')
            ->visible(fn (PendingWhmcsInvoice $r) => $r->status === PendingWhmcsInvoice::STATUS_PENDING_REVIEW
                && $r->customer_id === null
                && filled($r->whmcsAfm()))
            ->requiresConfirmation()
            ->modalHeading(fn (PendingWhmcsInvoice $r) => 'Δημιουργία πελάτη ekdosi (ΑΦΜ '.$r->whmcsAfm().')')
            ->modalDescription('Δημιουργείται πελάτης με βάση το ΑΦΜ του WHMCS. Τα στοιχεία αντλούνται από την ΑΑΔΕ (GSIS) όταν το ΑΦΜ είναι έγκυρο· αλλιώς από τα στοιχεία του WHMCS. Συνδέεται αυτόματα με αυτό το τιμολόγιο.')
            ->modalSubmitActionLabel('Δημιουργία')
            ->action(function (PendingWhmcsInvoice $r) {
                $tenant = Filament::getTenant();
                $result = app(WhmcsCustomerCreator::class)->createForPending($tenant, $r);

                // Never link a soft-deleted owner ('deleted_owner' carries it only
                // so the notification can name it) — the operator restores first.
                if ($result->customer !== null && $result->source !== 'deleted_owner') {
                    $r->update([
                        'customer_id' => $result->customer->id,
                        'match_reason' => PendingWhmcsInvoice::REASON_AFM,
                    ]);
                }

                self::notifyCustomerCreateResult($result);
            });
    }

    /**
     * Surface a WhmcsCustomerCreator outcome as operator notifications: the
     * create/link result (source-coloured), plus — when the official GSIS data
     * DIFFERED from what the customer typed in WHMCS — a persistent warning
     * listing each corrected field (the GSIS value was kept). Shared by the «…»
     * «Δημ. πελάτη (ΑΑΔΕ)» action and the inline modal «Εισαγωγή από ΑΦΜ» button
     * so the two can't drift.
     */
    private static function notifyCustomerCreateResult(WhmcsCustomerCreateResult $result): void
    {
        if ($result->customer === null) {
            Notification::make()->title('Δεν υπάρχει/δόθηκε ΑΦΜ — δεν δημιουργήθηκε πελάτης')->danger()->send();

            return;
        }

        if ($result->source === 'deleted_owner') {
            Notification::make()
                ->title('Υπάρχει ΔΙΑΓΡΑΜΜΕΝΟΣ πελάτης με αυτό το ΑΦΜ: '.$result->customer->name)
                ->body('Επανέφερέ τον από τη λίστα πελατών (φίλτρο «Διαγραμμένα») και ξαναπροσπάθησε — δεν δημιουργείται δεύτερος πελάτης για το ίδιο ΑΦΜ.')
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $title = match ($result->source) {
            'aade' => 'Δημιουργήθηκε από ΑΑΔΕ',
            'whmcs' => 'Δημιουργήθηκε από στοιχεία WHMCS (ΑΑΔΕ μη διαθέσιμη — έλεγξε τα στοιχεία)',
            'existing' => 'Συνδέθηκε με υπάρχοντα πελάτη',
            default => 'Δημιουργήθηκε',
        };
        Notification::make()
            ->title($title.': '.$result->customer->name)
            ->{$result->source === 'whmcs' ? 'warning' : 'success'}()
            ->send();

        if ($result->discrepancies !== []) {
            $lines = ['Κρατήθηκαν τα επίσημα στοιχεία ΑΑΔΕ. Διέφεραν από όσα είχε δηλώσει ο πελάτης στο WHMCS:'];
            foreach ($result->discrepancies as $d) {
                $lines[] = '• '.$d['field'].': WHMCS «'.$d['whmcs'].'» → ΑΑΔΕ «'.$d['aade'].'»';
            }
            Notification::make()
                ->title('Διορθώθηκαν στοιχεία από την ΑΑΔΕ')
                ->body(implode("\n", $lines))
                ->warning()
                ->persistent()
                ->send();
        }
    }

    /**
     * Jump to the draft (or filed) invoice this row produced, so the operator
     * can review/fix/issue it. Visible once an invoice is linked.
     */
    private static function openInvoiceAction(): Action
    {
        return Action::make('open_invoice')
            ->label('Άνοιγμα παραστατικού')
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->color('gray')
            ->visible(fn (PendingWhmcsInvoice $r) => $r->invoice_id !== null)
            ->url(fn (PendingWhmcsInvoice $r) => $r->invoice_id
                ? InvoiceResource::getUrl('view', ['record' => $r->invoice_id, 'tenant' => Filament::getTenant()])
                : null)
            ->openUrlInNewTab();
    }

    /**
     * The headline action: full-preview modal -> create an editable DRAFT
     * invoice (NOT filed at AADE). Safer than filing straight from the inbox:
     * the operator opens the draft, fixes line text (e.g. a domain the
     * customer asked to hide), then issues it via the normal lifecycle
     * (Οριστικοποίηση → Υποβολή στο myDATA). Visible only on pending_review
     * rows.
     */
    private static function createDraftAction(): Action
    {
        return Action::make('create_draft')
            ->label('Δημιουργία Παραστατικού')
            ->icon('heroicon-o-document-plus')
            ->color('primary')
            // Tier 1 #1: gate on the policy. WhmcsInboxResource::canAccess
            // intentionally allows any auth'd user to SEE the list (so the
            // resource doesn't 404 between deploy and shield:generate),
            // but every destructive action MUST consult the policy or
            // any reader becomes a filer.
            ->authorize('update')
            ->visible(fn (PendingWhmcsInvoice $r) => $r->status === PendingWhmcsInvoice::STATUS_PENDING_REVIEW)
            ->form(fn (PendingWhmcsInvoice $r) => [
                // B: surface the billing intent the customer set in WHMCS so
                // the operator picks the right type without digging — wants
                // invoice?, ΑΦΜ, ΔΟΥ, δραστηριότητα. Read-only hint.
                Placeholder::make('whmcs_intent')
                    ->label('Πρόθεση πελάτη (WHMCS)')
                    ->content(function (PendingWhmcsInvoice $r): string {
                        $wants = $r->wantsInvoice();
                        $lines = [];
                        if ($name = $r->whmcsClientName()) {
                            $lines[] = 'Πελάτης WHMCS: '.$name;
                        }
                        $lines[] = match ($wants) {
                            true => '📄 Ζήτησε ΤΙΜΟΛΟΓΙΟ',
                            false => '🧾 Δεν ζήτησε τιμολόγιο → μάλλον ΑΠΟΔΕΙΞΗ',
                            default => 'ℹ️ Άγνωστη πρόθεση (δεν έχει αντιστοιχιστεί το πεδίο)',
                        };
                        $afm = $r->whmcsAfm();
                        $lines[] = 'ΑΦΜ: '.($afm ?: '— (λείπει — μάλλον ιδιώτης/απόδειξη)');
                        if ($doy = $r->whmcsTaxOffice()) {
                            $lines[] = 'ΔΟΥ: '.$doy;
                        }
                        if ($act = $r->whmcsActivity()) {
                            $lines[] = 'Δραστηριότητα: '.$act;
                        }

                        return implode("\n", $lines);
                    }),

                // Third-party routing visibility: if the bridge resolved any
                // routed lines for this invoice, list EVERY routed beneficiary
                // (contact + ΑΦΜ + which line) so the operator sees who the
                // customer chose AND can spot a mis-routing before picking the
                // recipient below. Hidden entirely when there's no routing.
                Placeholder::make('third_party_routing')
                    ->label('Δρομολόγηση σε τρίτους')
                    ->visible(fn (PendingWhmcsInvoice $r): bool => self::routedBeneficiaries($r) !== [])
                    ->content(function (PendingWhmcsInvoice $r): string {
                        $rows = self::routedBeneficiaries($r);
                        $out = ['Ο πελάτης έχει δρομολογήσει γραμμές σε:'];
                        foreach ($rows as $b) {
                            $afm = $b['afm'] !== '' ? ' (ΑΦΜ '.$b['afm'].')' : ' (χωρίς ΑΦΜ)';
                            $doc = $b['is_receipt'] ? ' — απόδειξη' : '';
                            $out[] = '• '.$b['name'].$afm.' → '.$b['lines'].' γραμμή(ές)'.$doc;
                        }
                        $out[] = 'Διάλεξε τον σωστό δικαιούχο παρακάτω (ή τον πελάτη, αν είναι λάθος δρομολόγηση).';

                        return implode("\n", $out);
                    }),

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

                // Δεν υπάρχει ο πελάτης; Δημιούργησέ τον επιτόπου από ΑΦΜ χωρίς
                // να φύγεις από το modal: GSIS-authoritative + WHMCS συμπλήρωση
                // (email/τηλέφωνο/διεύθυνση), και το Select πελάτη από πάνω
                // ενημερώνεται αυτόματα. Το ΑΦΜ είναι επεξεργάσιμο (default το
                // ΑΦΜ του WHMCS) ώστε να καλύπτει και γραμμές χωρίς/με λάθος ΑΦΜ.
                TextInput::make('lookup_afm')
                    ->label('Δεν υπάρχει ο πελάτης; Εισαγωγή από ΑΦΜ (ΑΑΔΕ)')
                    ->placeholder('ΑΦΜ')
                    ->default(fn () => $r->whmcsAfm())
                    ->helperText('Γράψε/διόρθωσε ΑΦΜ και πάτα 🔍 — αντλεί επίσημα στοιχεία από ΑΑΔΕ (GSIS), '
                        .'συμπληρώνει email/τηλέφωνο/διεύθυνση από WHMCS, δημιουργεί & συνδέει τον πελάτη εδώ. '
                        .'Αν κάποιο στοιχείο διαφέρει, κρατιέται το επίσημο της ΑΑΔΕ με προειδοποίηση.')
                    ->suffixActions([
                        Action::make('import_from_afm')
                            ->icon('heroicon-m-magnifying-glass')
                            ->label('Εισαγωγή από ΑΑΔΕ')
                            ->action(function (callable $get, callable $set) use ($r) {
                                $afm = Afm::digits((string) $get('lookup_afm'));
                                if (blank($afm)) {
                                    Notification::make()->title('Συμπλήρωσε πρώτα ΑΦΜ')->warning()->send();

                                    return;
                                }
                                $tenant = Filament::getTenant();
                                try {
                                    $result = app(WhmcsCustomerCreator::class)->createForPending($tenant, $r, $afm);
                                } catch (Throwable $e) {
                                    Notification::make()->title('Η εισαγωγή απέτυχε')->body($e->getMessage())->danger()->send();

                                    return;
                                }
                                // Link the new/existing customer into the picker
                                // above (it's ->live(), so the preview re-renders).
                                // A soft-deleted owner is NOT selectable — restore first.
                                if ($result->customer !== null && $result->source !== 'deleted_owner') {
                                    $set('customer_id', $result->customer->id);
                                }
                                self::notifyCustomerCreateResult($result);
                            }),
                    ]),

                // Carry the WHMCS paid/unpaid fact INTO the modal (the inbox badge
                // is toggleable, so it can't be the only signal). Prominent only
                // when unpaid — that's the case where the (cash-term) default type
                // would wrongly settle a real receivable.
                Placeholder::make('whmcs_payment_status_hint')
                    ->hiddenLabel()
                    ->content(fn (): HtmlString => $r->whmcsIsUnpaid()
                        ? new HtmlString('<span style="color:#dc2626; font-weight:600;">⚠️ WHMCS: ΑΠΛΗΡΩΤΟ — έκδοσε επί πιστώσει (ανοιχτή οφειλή), όχι εξοφλημένο στην έκδοση.</span>')
                        : new HtmlString('<span style="color:#6b7280;">WHMCS: '.(strcasecmp((string) $r->whmcsStatus(), 'Paid') === 0 ? 'Πληρωμένο (εξοφλημένο στην έκδοση)' : e((string) ($r->whmcsStatus() ?? '—'))).'</span>'))
                    ->visible(fn (): bool => $r->whmcsStatus() !== null),

                Select::make('invoice_type_id')
                    ->label('Τύπος παραστατικού')
                    // Same favorites-first (⭐) ordering as the normal invoice form
                    // (PickerOptions::invoiceTypeOptions) instead of a flat
                    // alphabetical list — ΤΙΜ/ΤΠΥ surface at the top.
                    ->options(fn () => PickerOptions::invoiceTypeOptions())
                    // Resolve the label WITHOUT the show_on_menu filter so a
                    // pre-selected default that's hidden from the menu still
                    // renders (mirrors InvoiceForm).
                    ->getOptionLabelUsing(function ($value) {
                        $type = InvoiceType::query()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->find($value);

                        return $type ? (($type->is_favorite ? '⭐ ' : '').$type->code.' — '.$type->name) : null;
                    })
                    ->required()
                    ->searchable()
                    ->live()
                    // Pre-select a STATUS- and intent-aware default: unpaid WHMCS
                    // invoice → the «επί πιστώσει» type (open receivable); paid +
                    // receipt-intent → the receipt type; paid otherwise → the paid
                    // invoice type. The operator always overrides.
                    ->default(fn () => ($t = Filament::getTenant()) instanceof Company
                        ? $r->suggestedInvoiceTypeId($t)
                        : null)
                    ->helperText('Επιλέγει σειρά + ΑΑ counter + myDATA mapping. Προ-επιλογή βάσει κατάστασης WHMCS (πληρωμένο/απλήρωτο) + πρόθεσης (τιμολόγιο/απόδειξη).'),

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
            ->modalHeading(fn (PendingWhmcsInvoice $r) => 'Δημιουργία προσχεδίου από WHMCS #'.$r->whmcs_invoice_id)
            ->modalDescription('Επιλέγεις πελάτη και τύπο. Δημιουργείται ΠΡΟΣΧΕΔΙΟ παραστατικό στο ekdosi (δεν υποβάλλεται στην ΑΑΔΕ). Άνοιξέ το από τα Παραστατικά, διόρθωσε ό,τι χρειάζεται (π.χ. περιγραφές) και έκδωσέ το από εκεί.')
            ->modalSubmitActionLabel('Δημιουργία προσχεδίου')
            ->modalCancelActionLabel('Άκυρο')
            ->modalWidth('5xl')
            // A second submit button: create the draft AND jump straight to it
            // (review/issue from the παραστατικό) — vs the plain «Δημιουργία
            // προσχεδίου» which stays in the inbox for creating several in a row.
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('create_and_open', arguments: ['redirect' => true])
                    ->label('Δημιουργία & έλεγχος')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('success'),
            ])
            ->action(function (PendingWhmcsInvoice $r, array $data, array $arguments, Component $livewire) {
                // Refuse a WHMCS consolidated/mass-pay invoice (lines reference
                // other invoices, no VAT of its own): issuing it would double-count
                // the source invoices and file their gross at 0% ΦΠΑ. New rows are
                // held at ingest; this guards a re-staged / legacy pending row.
                if ($r->isConsolidatedPayment()) {
                    Notification::make()
                        ->title('Συγκεντρωτικό τιμολόγιο πληρωμής (mass-pay)')
                        ->body(PendingWhmcsInvoice::consolidatedPaymentReason($r->consolidatedPaymentRefs()))
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                $tenant = Filament::getTenant();
                // withTrashed so a soft-deleted matched customer still resolves
                // (the Select renders it with a "(διαγραμμένος)" suffix); refuse
                // creating a draft under a trashed customer — stale snapshot.
                $customer = Customer::query()
                    ->withTrashed()
                    ->where('company_id', $tenant->getKey())
                    ->whereKey($data['customer_id'])
                    ->firstOrFail();
                if ($customer->trashed()) {
                    Notification::make()
                        ->title('Ο πελάτης είναι διαγραμμένος')
                        ->body('Επανάφερέ τον από τη λίστα πελατών ή επίλεξε άλλον πελάτη.')
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
                    $invoice = app(WhmcsInvoiceFiler::class)->createDraft(
                        tenant: $tenant,
                        pending: $r,
                        customer: $customer,
                        invoiceType: $invoiceType,
                        createdByUserId: auth()->id(),
                    );
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Η δημιουργία προσχεδίου ΑΠΕΤΥΧΕ')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                // «Δημιουργία & έλεγχος» → land straight on the new παραστατικό.
                if ($arguments['redirect'] ?? false) {
                    Notification::make()
                        ->title('Δημιουργήθηκε προσχέδιο '.$invoice->invcode)
                        ->success()
                        ->send();

                    $livewire->redirect(InvoiceResource::getUrl('view', [
                        'record' => $invoice->getKey(),
                        'tenant' => $tenant,
                    ]));

                    return;
                }

                Notification::make()
                    ->title('Δημιουργήθηκε προσχέδιο '.$invoice->invcode)
                    ->body('Άνοιξέ το από τα Παραστατικά, έλεγξε/διόρθωσε τις γραμμές και έκδωσέ το (Οριστικοποίηση → Υποβολή στο myDATA).')
                    ->success()
                    ->persistent()
                    ->send();
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
                    ->options(fn () => static::splitTypeOptions())
                    ->required()
                    ->searchable()
                    ->helperText('Για τους δικαιούχους που χρειάζονται τιμολόγιο.'),

                Select::make('receipt_type_id')
                    ->label('Τύπος απόδειξης')
                    ->options(fn () => static::splitTypeOptions())
                    ->searchable()
                    ->helperText('Υποχρεωτικό μόνο αν κάποιος δικαιούχος έχει σημανθεί ως απόδειξη (βλ. λίστα παρακάτω).'),

                Placeholder::make('groups')
                    ->label('Δικαιούχοι που θα προκύψουν')
                    ->content(function (PendingWhmcsInvoice $r): string {
                        $tenant = Filament::getTenant();
                        try {
                            $groups = app(WhmcsInvoiceSplitter::class)->planGroups($tenant, $r);
                        } catch (\RuntimeException $e) {
                            // The resolver's GUIDED message (e.g. a routed contact whose
                            // ΑΦΜ belongs to a soft-deleted customer) — show it, don't
                            // break the modal. Anything else propagates.
                            return '⚠ '.$e->getMessage();
                        }
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
            ->form(fn (PendingWhmcsInvoice $r) => [
                Textarea::make('hold_reason')
                    ->label('Λόγος αναμονής')
                    ->rows(2)
                    ->maxLength(200)
                    // For the forgetful-customer case the reason is pre-filled —
                    // one click parks it correctly.
                    ->default(fn () => $r->needsAfm() ? 'Αναμονή για ΑΦΜ / στοιχεία τιμολόγησης' : null)
                    ->placeholder('π.χ. αναμονή για ΑΦΜ, να επιβεβαιωθεί ο πελάτης'),
            ])
            ->modalHeading(fn (PendingWhmcsInvoice $r) => 'Αναμονή για WHMCS #'.$r->whmcs_invoice_id)
            ->modalDescription('Κρύβεται από την προεπιλεγμένη λίστα. Άρε το από κατάσταση = "Σε αναμονή" όταν είσαι έτοιμος. Ο λόγος φαίνεται στο tooltip της κατάστασης.')
            ->modalSubmitActionLabel('Σε αναμονή')
            ->action(function (PendingWhmcsInvoice $r, array $data) {
                $r->update([
                    'status' => PendingWhmcsInvoice::STATUS_HELD,
                    'hold_reason' => trim((string) ($data['hold_reason'] ?? '')) ?: null,
                ]);
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
                    'hold_reason' => null,
                ]);
                Notification::make()->title('Επαναφέρθηκε προς έλεγχο')->success()->send();
            });
    }

    /**
     * Re-run third-party resolution against the bridge for an already-staged
     * row, filling the «Τρίτος» column for rows that were ingested while
     * whmcs_third_party_enabled was OFF (or before resolve.php was deployed).
     * Touches ONLY the third-party columns — not status / link / customer
     * (see WhmcsInvoiceIngestor::reResolveThirdParty). Visible only when the
     * feature is enabled for the tenant.
     */
    private static function reResolveThirdPartyAction(): Action
    {
        return Action::make('re_resolve_third_party')
            ->label('Έλεγχος δικαιούχων (τρίτοι)')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->authorize('update')
            ->visible(fn (PendingWhmcsInvoice $r) => (bool) (Filament::getTenant()?->whmcs_third_party_enabled)
                && ! in_array($r->status, [PendingWhmcsInvoice::STATUS_FILED], true))
            ->requiresConfirmation()
            ->modalHeading(fn (PendingWhmcsInvoice $r) => 'Έλεγχος δρομολόγησης τρίτων — WHMCS #'.$r->whmcs_invoice_id)
            ->modalDescription('Ρωτά ξανά τη γέφυρα αν το τιμολόγιο δρομολογείται σε τρίτους δικαιούχους και ενημερώνει τη στήλη «Τρίτος». Δεν αλλάζει κατάσταση ή σύνδεση.')
            ->action(function (PendingWhmcsInvoice $r) {
                $tenant = Filament::getTenant();
                $state = app(WhmcsInvoiceIngestor::class)
                    ->reResolveThirdParty($tenant, $r);

                $label = match ($state) {
                    PendingWhmcsInvoice::TP_MULTI => 'πολλαπλοί δικαιούχοι (χρειάζεται διαχωρισμός)',
                    PendingWhmcsInvoice::TP_SINGLE => 'ένας τρίτος δικαιούχος',
                    PendingWhmcsInvoice::TP_NONE => 'κανένας τρίτος (δικό του)',
                    default => 'χωρίς απάντηση από τη γέφυρα (απενεργοποιημένο ή μη διαθέσιμο)',
                };
                Notification::make()->title('Δρομολόγηση: '.$label)->success()->send();
            });
    }
}
