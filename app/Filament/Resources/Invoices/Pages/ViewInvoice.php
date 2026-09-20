<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Actions\IssueCreditNote;
use App\Actions\ReissueInvoiceAsDraft;
use App\Actions\StornoAndReissue;
use App\Filament\Resources\Cmr\CmrResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\BankAccountField;
use App\Jobs\SendInvoiceEmail;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Services\Cmr\CreateCmrFromSource;
use App\Services\Delivery\DeliveryLifecycleService;
use App\Services\EInvoice\AadeInvoiceDocument;
use App\Services\EInvoice\Transports\InvoSignDocument;
use App\Services\EInvoiceSubmitterFactory;
use App\Services\InvoiceNumberer;
use App\Services\InvoicePdfRenderer;
use App\Services\MyDataSubmitter;
use App\Services\Peppol\PeppolInvoiceDocument;
use App\Services\Stock\StockService;
use App\Services\Whmcs\PaymentPushResult;
use App\Services\Whmcs\WhmcsInvoiceFetcher;
use App\Services\Whmcs\WhmcsPaymentPusher;
use App\Services\Whmcs\WhmcsPaymentPusherFactory;
use App\Services\Whmcs\WhmcsPaymentSyncer;
use App\Support\EInvoice\ProviderIssueDateGuard;
use App\Support\InvoiceScope;
use App\Support\Whmcs\WhmcsPaymentSyncCache;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Throwable;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        // The tenant files electronically through SOME channel — direct myDATA OR a
        // certified provider (ΥΠΑΗΕΣ). The submit/cancel actions are the SAME (the
        // factory routes to the right submitter); only the label/wording differs.
        $tenant = Filament::getTenant();
        $tenantSupportsMyData = (bool) $tenant?->submitsElectronically();
        $isProviderChannel = (bool) $tenant?->isLiveProviderTenant();
        $channelLabel = $tenant?->einvoiceChannelLabel() ?? 'myDATA';

        return [
            // «Καρτέλα πελάτη» — an invoice is often reached FROM a customer's ledger
            // (its rows link here); offer a one-click way back instead of navigating
            // the menu. Only when the invoice has a customer (retail has none).
            Action::make('customer_ledger')
                ->label('Καρτέλα πελάτη')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->visible(fn (Invoice $record): bool => $record->customer_id !== null)
                ->url(fn (Invoice $record): ?string => $record->customer_id
                    ? CustomerResource::getUrl('ledger', ['record' => $record->customer_id])
                    : null),
            // «Δημιουργία CMR» — international consignment note for this invoice's
            // goods (e.g. cross-border shipment). Pre-fills a DRAFT CMR (Greek→Latin
            // transliteration) the operator corrects to English, then prints. NOT a
            // myDATA document. Visible only to operators with CMR access.
            Action::make('create_cmr')
                ->label('Δημιουργία CMR')
                ->icon('heroicon-o-clipboard-document-list')
                ->color('gray')
                ->visible(fn () => auth()->user()?->can('View:CmrNote') ?? false)
                ->requiresConfirmation()
                ->modalHeading('Δημιουργία CMR από το παραστατικό')
                ->modalDescription('Δημιουργείται ΠΡΟΧΕΙΡΟ CMR στα Αγγλικά (μεταγραφή από τα ελληνικά). Διορθώστε το πριν την εκτύπωση.')
                ->action(function (Invoice $record) {
                    $cmr = app(CreateCmrFromSource::class)->fromInvoice($record);
                    Notification::make()->success()->title('Δημιουργήθηκε προσχέδιο CMR')->send();

                    return redirect(CmrResource::getUrl('edit', ['record' => $cmr]));
                }),

            // «Επεξεργασία» — straight into the full edit environment (date, type,
            // customer, lines, product, price, add/remove rows). Only on an
            // unissued draft: EditInvoice refuses anything else, so the button
            // mirrors that predicate rather than bouncing the operator. This is
            // the primary in-app entry to editing a draft — the table has the twin
            // pencil, but an operator who lands on the invoice needs it here too.
            Action::make('edit_draft')
                ->label('Επεξεργασία')
                ->icon('heroicon-o-pencil-square')
                ->color('primary')
                ->visible(fn (Invoice $record) => $record->mydata_state === null
                    && $record->local_status === 'draft')
                ->authorize(fn (Invoice $record) => auth()->user()?->can('update', $record) ?? false)
                ->url(fn (Invoice $record) => static::getResource()::getUrl('edit', [
                    'record' => $record,
                    'tenant' => $record->company,
                ])),

            // «Ημερομηνία έκδοσης → σήμερα» — the one-click resolution for the
            // provider's «issue date must be today» rule (InvoSign 238). Only on a
            // provider-channel draft whose date is not already today: for direct
            // myDATA the date is editable in the form and AADE accepts a backdate
            // within its window, so the shortcut would be noise there. A draft's ΑΑ
            // is burned at creation, not per-date, so moving issued_at is safe and
            // creates no gap.
            Action::make('set_issue_date_today')
                ->label('Ημερομηνία έκδοσης → σήμερα')
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->visible(fn (Invoice $record) => $isProviderChannel
                    && $record->mydata_state === null
                    && $record->local_status === 'draft'
                    && ! static::issuedToday($record))
                ->authorize(fn (Invoice $record) => auth()->user()?->can('update', $record) ?? false)
                ->requiresConfirmation()
                ->modalHeading('Ενημέρωση ημερομηνίας έκδοσης')
                ->modalDescription(fn (Invoice $record) => 'Η ημερομηνία έκδοσης θα γίνει η σημερινή ('
                    .now()->setTimezone(ProviderIssueDateGuard::TZ)->format('d/m/Y')
                    .'). Απαιτείται για online έκδοση μέσω παρόχου. Ο αύξων αριθμός (ΑΑ) δεν αλλάζει.')
                ->modalSubmitActionLabel('Ενημέρωση')
                ->action(function (Invoice $record) {
                    // Re-check under a ROW LOCK on a fresh read, not on the
                    // in-memory $record. mountAction does not re-run visible(), and
                    // a concurrent MyDataSubmitter could have filed this invoice
                    // (VALID) between page render and this click — rewriting a
                    // filed invoice's issue date is exactly the legal-freeze write
                    // EditInvoice::beforeSave locks against. Same lockForUpdate
                    // pattern, so the guard holds until the UPDATE commits. Channel
                    // is re-derived from the record's own company (the closure's
                    // captured $isProviderChannel is a page-load snapshot).
                    $ok = DB::transaction(function () use ($record): bool {
                        $current = Invoice::query()
                            ->whereKey($record->getKey())
                            ->lockForUpdate()
                            ->first();

                        if ($current === null
                            || $current->mydata_state !== null
                            || $current->local_status !== 'draft'
                            || ! (bool) $current->company?->isLiveProviderTenant()) {
                            return false;
                        }

                        $current->update(['issued_at' => now()]);

                        return true;
                    });

                    if (! $ok) {
                        Notification::make()
                            ->title('Δεν επιτρέπεται')
                            ->body('Η ημερομηνία αλλάζει μόνο σε πρόχειρο, μη υποβληθέν παραστατικό παρόχου.')
                            ->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title('Ενημερώθηκε η ημερομηνία έκδοσης')
                        ->body('Νέα ημερομηνία: '.now()->setTimezone(ProviderIssueDateGuard::TZ)->format('d/m/Y')
                            .'. Μπορείτε τώρα να το στείλετε στον πάροχο.')
                        ->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record, 'tenant' => $record->company]));
                }),

            // --- Local lifecycle: Πρόχειρο → Ενεργό → Ακυρωμένο.
            /*
             * «Προσφορά στον πελάτη» — turn a draft into a προτιμολόγιο: locked
             * against further editing, visible in the portal, and payable there
             * (or settleable from the customer's existing credit).
             *
             * It stays a DRAFT: no ΑΑ, no myDATA, outside every money total. That is
             * the point. Recurring-service renewals are staged as drafts
             * (StageServiceRenewal); issuing them unilaterally and then cancelling
             * the ones the customer dropped would produce a stream of ΑΚΥ/πιστωτικά,
             * which is exactly the pattern that draws AADE attention. Letting the
             * customer settle the proforma first means those cancellations never
             * need to exist — the document is issued only once the money is there.
             */
            Action::make('offer_to_customer')
                ->label('Προσφορά στον πελάτη')
                ->icon('heroicon-o-paper-airplane')
                ->color('info')
                // Not for a πιστωτικό (you don't ask a customer to settle a credit
                // note) nor for a customer-less retail slip (nobody to offer it to).
                ->visible(fn (Invoice $record): bool => $record->local_status === 'draft'
                    && ! $record->isOffered()
                    && $record->customer_id !== null
                    && $record->credited_invoice_id === null
                    && ! ($record->invoiceType?->is_credit ?? false))
                ->authorize(fn (Invoice $record) => auth()->user()?->can('update', $record) ?? false)
                ->requiresConfirmation()
                ->modalHeading('Προσφορά στον πελάτη (προτιμολόγιο)')
                ->modalDescription('Κλειδώνει για επεξεργασία και γίνεται ορατό στην πύλη, ώστε ο πελάτης να μπορεί να το πληρώσει ή να χρησιμοποιήσει την πίστωσή του. ΔΕΝ εκδίδεται: δεν παίρνει ΑΑ και δεν πάει στο myDATA.')
                ->action(function (Invoice $record) {
                    $record->forceFill(['offered_at' => now()])->save();

                    Notification::make()->success()
                        ->title('Το παραστατικό προσφέρθηκε στον πελάτη')
                        ->body('Ο πελάτης το βλέπει πλέον στην πύλη και μπορεί να το εξοφλήσει.')
                        ->send();
                }),

            // Back to an editable draft. The customer stops seeing it immediately;
            // any money already settled against it stays attached to the document
            // (it is the same row), so nothing has to be unwound.
            Action::make('withdraw_offer')
                ->label('Ανάκληση προσφοράς')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->visible(fn (Invoice $record): bool => $record->isOffered())
                ->authorize(fn (Invoice $record) => auth()->user()?->can('update', $record) ?? false)
                ->requiresConfirmation()
                ->modalHeading('Ανάκληση προσφοράς')
                ->modalDescription(fn (Invoice $record): string => $record->hasRecordedPayments()
                    ? 'ΠΡΟΣΟΧΗ: το παραστατικό έχει ήδη εισπράξεις. Η ανάκληση θα το ξανακάνει επεξεργάσιμο — ΜΗΝ αλλάξεις πελάτη και μην το διαγράψεις όσο κρατά χρήματα τρίτου. Αφαίρεσε πρώτα τις εισπράξεις αν χρειάζεται.'
                    : 'Επιστρέφει σε επεξεργάσιμο πρόχειρο και παύει να είναι ορατό στον πελάτη.')
                ->action(function (Invoice $record) {
                    $record->forceFill(['offered_at' => null])->save();

                    Notification::make()->success()->title('Η προσφορά ανακλήθηκε')->send();
                }),

            // Independent of myDATA (the AADE truth). Reviving an
            // AADE-cancelled invoice is blocked (terminal there).
            Action::make('finalize')
                ->label('Οριστικοποίηση')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (Invoice $record) => $record->local_status === 'draft')
                ->authorize(fn (Invoice $record) => auth()->user()?->can('update', $record) ?? false)
                ->requiresConfirmation()
                ->modalHeading('Οριστικοποίηση παραστατικού')
                ->modalDescription('Γίνεται «Ενεργό» και κλειδώνει για επεξεργασία. Μπορείτε να το υποβάλετε στο myDATA ή να το επαναφέρετε σε πρόχειρο.')
                ->action(function (Invoice $record) {
                    // Gapless-at-send: a tenant that does NOT transmit to AADE has no
                    // submission event, so finalisation IS its issuance — allocate the
                    // real ΑΑ here. Uses the mode-AWARE submitsElectronically() (not
                    // filesToAadeByProvider()): a gr-mydata/gr-provider tenant in mode=off
                    // routes to NullSubmitter and would otherwise stay provisional forever.
                    // Live-filing tenants keep the provisional identity until they transmit
                    // (where InvoiceNumberer::assign runs instead).
                    //
                    // ATOMIC assign + status flip: wrapped in ONE transaction so a failure of
                    // either rolls back BOTH. Neither half-state is possible — not active-but-
                    // unnumbered (no UI path to a number), and not numbered-but-draft (a
                    // reserved ΑΑ the operator could abandon into a gap). A retry re-runs
                    // cleanly (assign no-ops once the invoice carries a code).
                    DB::transaction(function () use ($record): void {
                        if ($record->code === null && ! $record->company->submitsElectronically()) {
                            app(InvoiceNumberer::class)->assign($record);
                        }

                        $record->update(['local_status' => 'active']);
                    });

                    // S2.5: non-blocking heads-up if the sale pushed any tracked
                    // product to negative stock (the issue ALWAYS proceeds).
                    static::warnIfStockWentNegative($record);

                    // G6: auto-email on the non-myDATA issue path. myDATA
                    // tenants get the mail on the VALID response instead;
                    // shouldAutoEmailOnFinalize() guards against a double
                    // send and honours the tenant + per-customer toggles.
                    // Dispatch is best-effort: a queue hiccup must NOT mask
                    // the successful finalize (mirrors MyDataSubmitter::
                    // dispatchAutoEmailIfEnabled). afterCommit runs inline
                    // here (no open transaction), so guard it with try/catch.
                    if ($record->shouldAutoEmailOnFinalize()) {
                        DB::afterCommit(function () use ($record): void {
                            try {
                                SendInvoiceEmail::dispatch($record, trigger: 'auto');
                            } catch (Throwable $e) {
                                Log::warning('SendInvoiceEmail auto-dispatch on finalize failed (finalize succeeded)', [
                                    'invoice_id' => $record->getKey(),
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        });
                    }

                    Notification::make()->title('Έγινε Ενεργό')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record, 'tenant' => $record->company]));
                }),

            Action::make('revert_to_draft')
                ->label('Επαναφορά σε πρόχειρο')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->visible(fn (Invoice $record) => $record->local_status === 'active' && $record->mydata_state === null)
                ->authorize(fn (Invoice $record) => auth()->user()?->can('update', $record) ?? false)
                ->requiresConfirmation()
                ->action(function (Invoice $record) {
                    // Clear any stale offer along with the status. Otherwise a
                    // document that was offered → issued → reverted comes back as an
                    // OFFERED draft: instantly visible and payable in the portal
                    // again, and locked against the very edit it was reverted for.
                    $record->update(['local_status' => 'draft', 'offered_at' => null]);
                    Notification::make()->title('Επαναφορά σε πρόχειρο')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record, 'tenant' => $record->company]));
                }),

            Action::make('cancel_local')
                ->label('Ακύρωση')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                // Hidden on a provider-filed (VALID) invoice: a local-only cancel
                // there desyncs from AADE (the doc stays VALID at the provider) —
                // the rule is to reverse it with a credit note («Ακύρωση μέσω
                // πιστωτικού»), not flip it locally. Direct-myDATA keeps it (the
                // intended local-cancel-then-«Ακύρωση μέσω myDATA» flow).
                ->visible(fn (Invoice $record) => in_array($record->local_status, ['draft', 'active'], true)
                    && $record->credited_invoice_id === null
                    && ! ($isProviderChannel && $record->mydata_state === 'VALID'))
                ->authorize(fn (Invoice $record) => auth()->user()?->can('update', $record) ?? false)
                ->requiresConfirmation()
                ->modalHeading('Ακύρωση παραστατικού')
                ->modalDescription(fn (Invoice $record) => $record->mydata_state === 'VALID'
                    ? '⚠ Έχει υποβληθεί στο myDATA (VALID). Η τοπική ακύρωση ΔΕΝ ακυρώνει στην ΑΑΔΕ — εκτελέστε και «Ακύρωση μέσω myDATA». Τυχόν πληρωμές γίνονται πιστωτικό υπόλοιπο του πελάτη.'
                    : 'Σημειώνεται ως Ακυρωμένο (χωρίς myDATA). Τυχόν πληρωμές γίνονται πιστωτικό υπόλοιπο του πελάτη, διαθέσιμο για επόμενο παραστατικό.')
                ->modalSubmitActionLabel('Ακύρωση')
                ->schema([
                    Textarea::make('reason')
                        ->label('Αιτία (προαιρετικό)')
                        ->rows(2),
                ])
                ->action(function (Invoice $record, array $data) {
                    // Refuse if live credit notes reference this invoice:
                    // cancelling the original removes it from the ledger
                    // while its credit notes keep reducing the balance →
                    // double-removal. Handle the credit notes first.
                    if (InvoiceScope::live(Invoice::where('credited_invoice_id', $record->id))->exists()) {
                        Notification::make()
                            ->title('Δεν είναι δυνατή η ακύρωση')
                            ->body('Το παραστατικό έχει ενεργά πιστωτικά. Ακυρώστε/διαχειριστείτε πρώτα τα πιστωτικά.')
                            ->danger()->persistent()->send();

                        return;
                    }
                    DB::transaction(function () use ($record, $data) {
                        // Detach any payments → on-account customer credit
                        // (each save fires PaymentObserver → recomputes
                        // this invoice's cache). Higher-order ->each.
                        $record->payments()->get()->each->update(['invoice_id' => null]);
                        $record->update([
                            'local_status' => 'cancelled',
                            'cancel_reason' => $data['reason'] ?? null,
                        ]);
                    });
                    Notification::make()
                        ->title('Ακυρώθηκε')
                        ->body('Τυχόν πληρωμές έγιναν πιστωτικό υπόλοιπο του πελάτη.')
                        ->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record, 'tenant' => $record->company]));
                }),

            Action::make('revive')
                ->label('Επαναφορά')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                // Blocked when CANCELLED at myDATA — terminal at AADE;
                // reissue a new invoice instead.
                ->visible(fn (Invoice $record) => $record->local_status === 'cancelled'
                    && $record->mydata_state !== 'CANCELLED')
                ->authorize(fn (Invoice $record) => auth()->user()?->can('update', $record) ?? false)
                ->requiresConfirmation()
                ->modalHeading('Επαναφορά ακυρωμένου')
                ->modalDescription('Επαναφέρεται σε «Ενεργό» αν είχε υποβληθεί στο myDATA, αλλιώς σε «Πρόχειρο». Πληρωμές που έγιναν πιστωτικό υπόλοιπο ΔΕΝ επανασυνδέονται αυτόματα.')
                ->action(function (Invoice $record) {
                    $record->update([
                        'local_status' => $record->mydata_state === 'VALID' ? 'active' : 'draft',
                        // Same stale-offer trap as revert_to_draft: without this a
                        // cancelled-then-revived proforma comes back OFFERED — visible
                        // and payable in the portal again with no operator decision,
                        // and locked against the edit the revive was for.
                        'offered_at' => null,
                        'cancel_reason' => null,
                    ]);
                    Notification::make()->title('Επαναφέρθηκε')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record, 'tenant' => $record->company]));
                }),

            // Record a payment against this invoice. Not shown on credit
            // notes (they're money owed back, not collected). Overpay is
            // allowed (warned, not blocked) — real prepayments/rounding.
            Action::make('record_payment')
                ->label('Καταχώριση πληρωμής')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                // Credit-term only: cash-term invoices are settled at
                // issue (nothing to collect), and credit notes / cancelled
                // docs aren't receivables.
                ->visible(fn (Invoice $record) => $record->credited_invoice_id === null
                    && $record->customer_id !== null
                    && $record->mydata_state !== 'CANCELLED'
                    // MON-5: not on a draft — a πρόχειρο isn't a receivable yet.
                    && $record->local_status !== 'draft'
                    && (int) ($record->paymentMethod?->due_days ?? 0) > 0)
                ->authorize(fn (Invoice $record) => auth()->user()?->can('update', $record) ?? false)
                ->modalHeading('Καταχώριση πληρωμής')
                ->modalSubmitActionLabel('Καταχώριση')
                ->schema([
                    TextInput::make('amount')
                        ->label('Ποσό')
                        ->numeric()
                        // MON-8: record_payment writes kind='payment' with a
                        // positive amount — a negative value would bypass the
                        // refund mechanism. Enforce positivity at the form.
                        ->minValue(0.01)
                        ->required()
                        ->default(fn (Invoice $record) => number_format(max($record->balanceData()->balance, 0), 2, '.', ''))
                        ->helperText(fn (Invoice $record) => 'Υπόλοιπο: '.number_format($record->balanceData()->balance, 2, ',', '.').' €'),
                    DatePicker::make('pay_date')
                        ->label('Ημερομηνία')
                        ->required()
                        ->default(now()),
                    Select::make('payment_method_id')
                        ->label('Τρόπος πληρωμής')
                        ->options(fn (Invoice $record) => PaymentMethod::query()
                            ->where('company_id', $record->company_id)
                            ->pluck('description', 'id'))
                        ->default(fn (Invoice $record) => $record->payment_method_id),
                    BankAccountField::make($this->record->company_id)
                        ->default(fn (Invoice $record) => $record->bank_account_id),
                    TextInput::make('transaction_id')
                        ->label('Κωδικός συναλλαγής')
                        ->maxLength(100)
                        ->helperText('Προαιρετικό — Stripe/PayPal txn ή ref εμβάσματος τράπεζας.'),
                    Textarea::make('notes')
                        ->label('Σημειώσεις')
                        ->rows(2),
                ])
                ->action(function (Invoice $record, array $data) {
                    DB::transaction(function () use ($record, $data) {
                        Payment::create([
                            'company_id' => $record->company_id,
                            'customer_id' => $record->customer_id,
                            'invoice_id' => $record->id,
                            'kind' => 'payment',
                            'payment_method_id' => $data['payment_method_id'] ?? null,
                            'bank_account_id' => $data['bank_account_id'] ?? null,
                            'amount' => $data['amount'],
                            'pay_date' => $data['pay_date'],
                            'transaction_id' => $data['transaction_id'] ?? null,
                            'notes' => $data['notes'] ?? null,
                        ]);
                    });
                    Notification::make()
                        ->title('Η πληρωμή καταχωρίστηκε')
                        ->success()->send();
                }),

            // «Έχει πληρωθεί στο WHMCS;» — on-demand check for THIS invoice.
            // For a filed, WHMCS-linked invoice still open on credit terms, ask
            // WHMCS whether it has been paid and, if so, close the receivable by
            // recording a Payment for the outstanding balance (money-write in
            // ekdosi only). Same idempotent logic as the scheduled/inbox sync —
            // safe to click repeatedly. Shown only when there is actually
            // something to check (open receivable with a live WHMCS link).
            Action::make('check_whmcs_paid')
                ->label('Έχει πληρωθεί στο WHMCS;')
                ->icon('heroicon-o-arrow-down-on-square')
                ->color('gray')
                ->visible(fn (Invoice $record) => static::hasOpenWhmcsLink($record))
                ->authorize(fn (Invoice $record) => auth()->user()?->can('update', $record) ?? false)
                ->requiresConfirmation()
                ->modalHeading('Έλεγχος πληρωμής στο WHMCS')
                ->modalDescription('Ρωτά το WHMCS αν το αντίστοιχο τιμολόγιο έχει πληρωθεί. Αν ναι, καταγράφεται εδώ πληρωμή που κλείνει το υπόλοιπο. Καμία αλλαγή δεν γίνεται στο WHMCS του πελάτη.')
                ->modalSubmitActionLabel('Έλεγχος')
                ->action(function (Invoice $record): void {
                    $fetch = app(WhmcsInvoiceFetcher::class)->for($record->company);
                    if ($fetch === null) {
                        Notification::make()
                            ->title('Δεν έχει ρυθμιστεί WHMCS')
                            ->body('Ο πελάτης δεν έχει ενεργή σύνδεση WHMCS — δεν έγινε έλεγχος.')
                            ->warning()->send();

                        return;
                    }

                    $amount = app(WhmcsPaymentSyncer::class)->syncInvoice($record, $fetch);

                    if ($amount > 0.005) {
                        // Settled now → drop it from the worklist immediately so the
                        // page/tile/badge don't show a phantom row until the next reconcile.
                        WhmcsPaymentSyncCache::removeInbound($record->company, (int) $record->id);
                        Notification::make()
                            ->title('Καταγράφηκε πληρωμή')
                            ->body('Το τιμολόγιο εξοφλήθηκε — καταχωρίστηκε πληρωμή '.number_format($amount, 2, ',', '.').' €.')
                            ->success()->send();
                        $this->redirect(static::getResource()::getUrl('view', ['record' => $record, 'tenant' => $record->company]));
                    } else {
                        Notification::make()
                            ->title('Δεν έχει πληρωθεί ακόμη')
                            ->body('Το WHMCS δεν το επιστρέφει ως πληρωμένο — δεν καταγράφηκε πληρωμή.')
                            ->info()->send();
                    }
                }),

            // OUTBOUND (Phase 2): «Σήμανση Paid στο WHMCS» — for an invoice
            // settled HERE (επί πιστώσει, πληρωμένο στο ekdosi) whose WHMCS side
            // is still open, mark it paid in the customer's WHMCS. Writes to an
            // EXTERNAL system, so it's gated on the tenant's outbound opt-in and
            // carries all four brakes in WhmcsPaymentPusher (idempotent / anti-
            // echo / live-only). Auto-push (opt-in) usually handles this; the
            // button is the manual/retry path (e.g. a WHMCS hiccup).
            Action::make('mark_paid_at_whmcs')
                ->label('Σήμανση Paid στο WHMCS')
                ->icon('heroicon-o-arrow-up-on-square')
                ->color('gray')
                ->visible(fn (Invoice $record) => static::hasUnpushedWhmcsSettlement($record))
                ->authorize(fn (Invoice $record) => auth()->user()?->can('update', $record) ?? false)
                ->requiresConfirmation()
                ->modalHeading('Σήμανση πληρωμένου στο WHMCS')
                ->modalDescription('Ενημερώνει το WHMCS του πελάτη ότι το αντίστοιχο τιμολόγιο εξοφλήθηκε (AddInvoicePayment). Γράφει στο σύστημα του πελάτη — γίνεται μία φορά (idempotent).')
                ->modalSubmitActionLabel('Σήμανση')
                ->action(function (Invoice $record): void {
                    $fetch = app(WhmcsInvoiceFetcher::class)->for($record->company);
                    $push = app(WhmcsPaymentPusherFactory::class)->for($record->company);
                    if ($fetch === null || $push === null) {
                        Notification::make()->title('Δεν έχει ρυθμιστεί WHMCS')->warning()->send();

                        return;
                    }

                    $result = app(WhmcsPaymentPusher::class)->push($record, $fetch, $push);

                    // Settled at WHMCS → drop it from the outbound worklist too
                    // (parity with the console list + the auto-push job).
                    if ($result === PaymentPushResult::Pushed || $result === PaymentPushResult::AlreadyPaid) {
                        WhmcsPaymentSyncCache::removeOutbound($record->company, (int) $record->id);
                    }

                    match ($result) {
                        PaymentPushResult::Pushed => Notification::make()
                            ->title('Ενημερώθηκε το WHMCS')
                            ->body('Το τιμολόγιο σημάνθηκε πληρωμένο στο WHMCS.')
                            ->success()->send(),
                        PaymentPushResult::AlreadyPaid => Notification::make()
                            ->title('Ήταν ήδη πληρωμένο')
                            ->body('Το WHMCS το είχε ήδη ως πληρωμένο — δεν χρειάστηκε αλλαγή.')
                            ->info()->send(),
                        PaymentPushResult::Failed => Notification::make()
                            ->title('Απέτυχε η σήμανση')
                            ->body('Το WHMCS απέρριψε το αίτημα — δοκιμάστε ξανά αργότερα.')
                            ->danger()->persistent()->send(),
                        PaymentPushResult::Skipped => Notification::make()
                            ->title('Δεν έγινε σήμανση')
                            ->body('Δεν πληροί τις προϋποθέσεις (opt-in / εξόφληση / σύνδεση WHMCS).')
                            ->warning()->send(),
                    };
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record, 'tenant' => $record->company]));
                }),

            // Issue a credit note (πιστωτικό) against this invoice —
            // full or partial return. Hidden on credit notes themselves,
            // on cancelled invoices, and when the tenant has no credit
            // invoice type configured. Mirrors CreateInvoice: persist in
            // a transaction, then submit to myDATA AFTER commit.
            Action::make('issue_credit_note')
                ->label('Έκδοση πιστωτικού')
                ->icon('heroicon-o-receipt-refund')
                ->color('warning')
                // Only on an ISSUED original — one that declares income/VAT a credit
                // note can reverse: finalised locally (active) OR filed at AADE
                // (mydata_state VALID, which a doc filed via an external channel can be
                // while its local_status is still 'draft' after reconciliation). Hidden
                // on a genuine πρόχειρο (edit/delete it instead) and on anything
                // cancelled (locally or at AADE — its reversal is handled elsewhere).
                ->visible(fn (Invoice $record) => ($record->local_status === 'active' || $record->mydata_state === 'VALID')
                    && $record->local_status !== 'cancelled'
                    && $record->credited_invoice_id === null
                    && $record->mydata_state !== 'CANCELLED'
                    && ! $record->isFullyCredited()
                    && self::creditTypes($record)->isNotEmpty())
                ->authorize(fn (Invoice $record) => auth()->user()?->can('update', $record) ?? false)
                ->modalHeading('Έκδοση πιστωτικού τιμολογίου')
                ->modalDescription(fn (Invoice $record) => ($isProviderChannel && $record->mydata_state === 'VALID')
                    ? 'Επιλέξτε τύπο πιστωτικού και ποσότητες ανά γραμμή (0 = εξαίρεση). Το εκδοθέν τιμολόγιο ΔΕΝ ακυρώνεται στον πάροχο — το πιστωτικό (5.1) είναι ο τρόπος αναστροφής: παίρνει δικό του ΜΑΡΚ, συσχετισμένο με το αρχικό, και το μηδενίζει λογιστικά. Αν ο πελάτης ζητήσει επιστροφή χρημάτων, καταχωρίστε «Πληρωμή» τύπου επιστροφής μετά την έκδοση.'
                    : 'Επιλέξτε τύπο πιστωτικού και τις ποσότητες προς πίστωση ανά γραμμή (0 = εξαίρεση).')
                ->modalSubmitActionLabel('Έκδοση')
                ->schema([
                    Select::make('credit_type_id')
                        ->label('Τύπος πιστωτικού')
                        ->options(fn (Invoice $record) => self::creditTypes($record)
                            ->mapWithKeys(fn (InvoiceType $t) => [$t->id => $t->code.' — '.$t->name]))
                        ->required(),
                    Repeater::make('lines')
                        ->label('Γραμμές')
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->default(fn (Invoice $record) => $record->lines
                            ->map(fn ($l) => [
                                'line_id' => $l->id,
                                'label' => ($l->product_descr ?? '#'.$l->id).' (×'.rtrim(rtrim((string) $l->qty, '0'), '.').')',
                                'qty' => (float) $l->qty,
                            ])->all())
                        ->schema([
                            Hidden::make('line_id'),
                            // Carry the display text as REAL (dehydrated) state and show
                            // it via a Placeholder with a DIFFERENT name. A Placeholder
                            // named «label» reading $get('label') is a SELF-REFERENCE:
                            // under Filament v5 it recurses (the field resolves its own
                            // content → $get('label') → …), which hung the modal mount →
                            // «Error while loading page» with no PHP exception logged.
                            Hidden::make('label'),
                            Placeholder::make('line_label')
                                ->label('')
                                ->content(fn (Get $get) => $get('label') ?? ''),
                            TextInput::make('qty')
                                ->label('Ποσότητα πίστωσης')
                                ->numeric()
                                ->minValue(0)
                                ->default(0),
                        ])
                        ->columns(2),
                    // Filing to myDATA is OPT-IN per issuance, default OFF.
                    // Early rollout: issue the credit note locally (it
                    // already reduces the balance) and file it later with
                    // the existing "Submit to myDATA" action when ready.
                    // Hidden for off-mode / non-Greek tenants.
                    Toggle::make('submit_now')
                        ->label('Υποβολή στο myDATA τώρα')
                        ->helperText('Αν είναι ανενεργό, το πιστωτικό αποθηκεύεται ως πρόχειρο και υποβάλλεται αργότερα χειροκίνητα.')
                        ->default(false)
                        ->visible($tenantSupportsMyData),
                ])
                ->action(function (Invoice $record, array $data) {
                    try {
                        $creditType = InvoiceType::query()
                            ->where('company_id', $record->company_id)
                            ->whereKey($data['credit_type_id'])
                            ->firstOrFail();

                        $selections = collect($data['lines'] ?? [])
                            ->map(fn ($row) => ['line_id' => (int) $row['line_id'], 'qty' => (float) $row['qty']])
                            ->all();

                        $credit = app(IssueCreditNote::class)($record, $creditType, $selections);

                        // Only file when the operator opted in. Submission
                        // runs AFTER the IssueCreditNote transaction
                        // committed, mirroring CreateInvoice's post-commit
                        // submit + correlated-MARK build in MyDataSubmitter.
                        if ($data['submit_now'] ?? false) {
                            try {
                                $submitter = app(EInvoiceSubmitterFactory::class)->for($record->company);
                                $submitter->submit($credit);
                            } catch (Throwable $e) {
                                Notification::make()
                                    ->title('Το πιστωτικό δημιουργήθηκε, αλλά η υποβολή στο myDATA απέτυχε')
                                    ->body($e->getMessage().' Υποβάλετέ το ξανά από τη σελίδα του πιστωτικού.')
                                    ->danger()->persistent()->send();
                                $this->redirect(static::getResource()::getUrl('view', ['record' => $credit, 'tenant' => $record->company]));

                                return;
                            }
                        }

                        Notification::make()
                            ->title('Το πιστωτικό εκδόθηκε')
                            ->body('Κωδικός: '.$credit->invcode
                                .(($data['submit_now'] ?? false) ? '' : ' (πρόχειρο — δεν υποβλήθηκε στο myDATA)'))
                            ->success()->send();
                        $this->redirect(static::getResource()::getUrl('view', ['record' => $credit, 'tenant' => $record->company]));
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Αποτυχία έκδοσης πιστωτικού')
                            ->body($e->getMessage())
                            ->danger()->persistent()->send();
                    }
                }),

            // Submit a draft invoice to myDATA. Visible only for drafts
            // (no mydata_state) on tenants in sandbox/production mode.
            // Off-mode + non-Greek tenants get no submission UI here.
            // MYD-3 (AUDIT): NEVER on a locally-cancelled invoice — filing a
            // sale the operator voided would over-declare income at AADE
            // (persistResponse deliberately keeps local_status=cancelled, so
            // the mismatch would only surface at reconciliation). Mirrors the
            // bulk submit's skip in InvoicesTable.
            Action::make('submit_to_mydata')
                ->label($isProviderChannel ? 'Αποστολή στον Πάροχο' : 'Υποβολή στο myDATA')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->visible(fn (Invoice $record) => $tenantSupportsMyData
                    && $record->mydata_state === null
                    && $record->local_status !== 'cancelled')
                ->authorize(fn (Invoice $record) => auth()->user()?->can('update', $record) ?? false)
                ->requiresConfirmation()
                ->modalHeading('Αποστολή παραστατικού — '.$channelLabel)
                ->modalDescription(function (Invoice $record) use ($isProviderChannel, $tenant, $channelLabel) {
                    $base = $isProviderChannel
                        ? ('Αποστολή μέσω '.$channelLabel.'. Ο πάροχος υποβάλλει στο myDATA και επιστρέφει το ΜΑΡΚ + QR. '
                            .(($tenant?->einvoice_provider_mode === 'production') ? '⚠ ΠΑΡΑΓΩΓΗ — πραγματική, νομικά δεσμευτική έκδοση.' : 'Δοκιμαστικό περιβάλλον.'))
                        : (($tenant?->mydata_mode === 'production')
                            ? '⚠ Production mode — REAL filing. Legally binding MARK returned. Cannot be edited after; only cancelled + reissued.'
                            : 'Sandbox mode — files to AADE\'s test endpoint. Synthetic MARK.');

                    // PROV-019 soft-warn: this is a replacement whose reversed original
                    // is still standing at AADE (its cancelling credit is an un-filed
                    // draft). Filing now would declare the turnover twice. Non-blocking
                    // — the operator can still confirm — but impossible to miss.
                    if ($record->replacementReversalPending()) {
                        $orig = $record->reissuedFrom?->invcode ?? '—';

                        return '⚠ ΠΡΟΣΟΧΗ: αντικαθιστά το '.$orig.', που ΔΕΝ έχει ακυρωθεί νόμιμα ακόμη — '
                            .'το πιστωτικό ακύρωσης είναι πρόχειρο/ανυπόβλητο. Αν υποβάλεις τώρα, ο τζίρος θα δηλωθεί '
                            .'ΔΙΠΛΑ στην ΑΑΔΕ (αρχικό + αντικατάσταση). Υπόβαλε πρώτα το πιστωτικό ακύρωσης του '.$orig.".\n\n".$base;
                    }

                    return $base;
                })
                ->modalSubmitActionLabel('Επιβεβαίωση αποστολής')
                ->action(function (Invoice $record) {
                    // Hard guard mirroring the visibility check — mountAction
                    // does NOT re-check visible(), so a stale page / crafted
                    // Livewire call could still reach here (same pattern as
                    // ManageTenantRoleAction).
                    if ($record->local_status === 'cancelled') {
                        Notification::make()
                            ->title('Το παραστατικό είναι ακυρωμένο')
                            ->body('Δεν υποβάλλεται στο myDATA παραστατικό που έχει ακυρωθεί τοπικά.')
                            ->danger()->send();

                        return;
                    }

                    // PROV-019: leave a durable trace when an operator files a
                    // replacement despite the soft-warn — so «έγινε από αντικατάσταση
                    // ενώ το αρχικό στεκόταν ακόμη» is answerable later (log_tail /
                    // OBS-001), beyond the reissued_from link the record already keeps.
                    if ($record->replacementReversalPending()) {
                        Log::warning('PROV-019: filing a replacement while its reversed original is still standing at AADE', [
                            'company_id' => $record->company_id,
                            'replacement' => $record->invcode,
                            'original' => $record->reissuedFrom?->invcode,
                            'original_id' => $record->reissued_from_invoice_id,
                        ]);
                    }

                    try {
                        // Resolve the tenant from the record's own
                        // company relation, not Filament::getTenant().
                        // The record always has a company_id (NOT NULL
                        // FK), but Filament::getTenant() can return
                        // null if the action is invoked outside a
                        // tenant-bound page context — passing null to
                        // ->for(Company $tenant) is a hard TypeError
                        // operators can't decipher. $record->company
                        // is the source of truth either way.
                        $submitter = app(EInvoiceSubmitterFactory::class)->for($record->company);
                        $mark = $submitter->submit($record);
                        // local_status draft→active is synced inside the
                        // submitter (single choke-point). Derive the channel from
                        // $record->company (the closure can't see the page-scope
                        // $isProviderChannel — and the company is the source of truth).
                        $viaProvider = (bool) $record->company->isLiveProviderTenant();
                        Notification::make()
                            ->title($viaProvider ? 'Εκδόθηκε μέσω παρόχου' : 'Filed at myDATA')
                            ->body('ΜΑΡΚ: '.($mark->mark ?? 'pending'))
                            ->success()->send();
                        // Bounce to a fresh view so mydata_state /
                        // mydata_mark + the audit-history relation
                        // manager re-query. refreshFormData([]) is a
                        // no-op (Filament only(...) of an empty array
                        // returns []) so it doesn't actually re-pull
                        // the record.
                        $this->redirect(static::getResource()::getUrl('view', [
                            'record' => $record,
                            'tenant' => $record->company,
                        ]));
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Submission failed')
                            ->body($e->getMessage())
                            ->danger()->persistent()->send();
                    }
                }),

            // Cancel a previously-filed invoice. Visible only for VALID
            // invoices on myDATA-capable tenants. Confirmation modal
            // mandatory — cancellation is legally significant.
            //
            // PROVIDER caveat (general ΥΠΑΗΕΣ rule, NOT InvoSign-specific):
            // «Για τη διαβίβαση μέσω Παρόχου Ηλεκτρονικής Τιμολόγησης δεν είναι
            // προς το παρόν εφικτή η ακύρωση παραστατικών που έχουν λάβει ΜΑΡΚ
            // παρά μόνο η έκδοση Πιστωτικού Τιμολογίου.» So a MARKed invoice
            // (2.1/11.x) is reversed by a credit note, never cancelled — every
            // provider rejects the cancel (InvoSign returns [283]). The lone
            // exception is a 9.3 δελτίο αποστολής, which is a διακίνηση doc, not
            // an invoice, and IS cancellable (InvoSign: CancelDeliveryNote). So
            // on ANY provider channel we offer this button for 9.3 only. Direct
            // myDATA keeps it for everything (AADE's CancelInvoice cancels a 2.1).
            Action::make('cancel_at_mydata')
                ->label('Ακύρωση μέσω '.$channelLabel)
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (Invoice $record) => $tenantSupportsMyData && $record->mydata_state === 'VALID'
                    && (! $isProviderChannel || $record->invoiceType?->mydata_type === '9.3'))
                ->authorize(fn (Invoice $record) => auth()->user()?->can('update', $record) ?? false)
                ->requiresConfirmation()
                ->modalHeading('Ακύρωση παραστατικού στην ΑΑΔΕ')
                ->modalDescription(fn (Invoice $record) => 'Αποστέλλεται αίτημα ΑΚΥΡΩΣΗΣ στην ΑΑΔΕ για το MARK '.($record->mydata_mark ?? '?').'. Το MARK διατηρείται στο ιστορικό· η κατάσταση γίνεται CANCELLED. Για διόρθωση περιεχομένου, εκδώστε πιστωτικό/διορθωτικό.')
                ->modalSubmitActionLabel('Επιβεβαίωση ακύρωσης')
                ->schema([
                    Textarea::make('reason')
                        ->label('Αιτία (καταγράφεται τοπικά)')
                        ->rows(3)
                        ->placeholder('Γιατί ακυρώνεται το παραστατικό;'),
                ])
                ->action(function (Invoice $record, array $data) {
                    try {
                        // See Submit-action comment above on why
                        // $record->company beats Filament::getTenant().
                        $submitter = app(EInvoiceSubmitterFactory::class)->for($record->company);
                        // Factory returns NullSubmitter for off-mode — but
                        // we guard visibility above so we're guaranteed
                        // a real MyDataSubmitter here.
                        if (! method_exists($submitter, 'cancel')) {
                            throw new \RuntimeException('Submitter does not support cancellation.');
                        }
                        $submitter->cancel($record, $data['reason'] ?? '');
                        // local_status → cancelled is synced inside the submitter.
                        Notification::make()
                            ->title('Ακυρώθηκε στο myDATA')
                            ->body('Η κατάσταση ΑΑΔΕ είναι πλέον CANCELLED· το αρχικό MARK διατηρείται στο ιστορικό.')
                            ->success()->send();
                        $this->redirect(static::getResource()::getUrl('view', [
                            'record' => $record,
                            'tenant' => $record->company,
                        ]));
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Η ακύρωση απέτυχε')
                            ->body($e->getMessage())
                            ->danger()->persistent()->send();
                    }
                }),

            // PROVIDER, MARKed invoice (2.1/11.x…): there is NO cancel via the
            // provider — only a credit note reverses it. This button sits exactly
            // where the operator looks for «Ακύρωση»: its modal EXPLAINS why (the
            // help text), and — when a credit type is configured — ISSUES a full
            // credit note that reverses the original in one step. The two documents
            // are bound via credited_invoice_id and shown on both under «Σχετικά
            // παραστατικά». When no credit type exists the modal is info-only (no
            // submit) and points to Setup. Gated to non-9.3 provider invoices; 9.3
            // keeps the real «Ακύρωση μέσω παρόχου».
            Action::make('cancel_via_credit')
                ->label('Ακύρωση μέσω πιστωτικού')
                ->icon('heroicon-o-receipt-refund')
                ->color('danger')
                ->visible(fn (Invoice $record) => $isProviderChannel
                    && $record->mydata_state === 'VALID'
                    && $record->credited_invoice_id === null
                    && ! $record->isFullyCredited()
                    && $record->invoiceType?->mydata_type !== '9.3')
                ->authorize(fn (Invoice $record) => auth()->user()?->can('update', $record) ?? false)
                ->modalHeading('Ακύρωση μέσω πιστωτικού')
                ->modalDescription(fn (Invoice $record) => 'Για διαβίβαση μέσω Παρόχου ΔΕΝ ακυρώνεται παραστατικό που έχει λάβει ΜΑΡΚ — η ακύρωση γίνεται με ΟΛΙΚΟ πιστωτικό. Έτσι μένουν σωστά έσοδα/ΦΠΑ: το αρχικό μένει VALID στην ΑΑΔΕ, το πιστωτικό το μηδενίζει. Τα δύο παραστατικά δένονται μεταξύ τους (βλ. «Σχετικά παραστατικά»). '
                    .(self::creditTypes($record)->isEmpty()
                        ? '⚠ Δεν υπάρχει ρυθμισμένος τύπος πιστωτικού — ρυθμίστε έναν στο Setup → Τύποι Παραστατικών (is_credit) και ξαναδοκιμάστε.'
                        : 'Για ταυτόχρονη επανέκδοση διορθωμένου, χρησιμοποιήστε «Ακύρωση & επανέκδοση». Αν ο πελάτης ζητήσει επιστροφή χρημάτων, καταχωρίστε «Πληρωμή» τύπου επιστροφής μετά.'))
                // No credit type → info-only modal (hide the submit button).
                ->modalSubmitAction(fn (Invoice $record) => self::creditTypes($record)->isEmpty() ? false : null)
                ->modalSubmitActionLabel('Έκδοση πιστωτικού ακύρωσης')
                ->modalCancelActionLabel('Κλείσιμο')
                ->schema(fn (Invoice $record) => self::creditTypes($record)->isEmpty() ? [] : [
                    Select::make('credit_type_id')
                        ->label('Τύπος πιστωτικού')
                        ->options(fn (Invoice $record) => self::creditTypes($record)
                            ->mapWithKeys(fn (InvoiceType $t) => [$t->id => $t->code.' — '.$t->name]))
                        ->required(),
                    Toggle::make('submit_now')
                        ->label('Υποβολή πιστωτικού στον πάροχο τώρα')
                        ->helperText('Αν είναι ανενεργό, το πιστωτικό μένει πρόχειρο και υποβάλλεται αργότερα χειροκίνητα.')
                        ->default(false)
                        ->visible($tenantSupportsMyData),
                ])
                ->action(function (Invoice $record, array $data) {
                    // No credit type → the modal was info-only; nothing to do.
                    if (empty($data['credit_type_id'])) {
                        return;
                    }
                    try {
                        $creditType = InvoiceType::query()
                            ->where('company_id', $record->company_id)
                            ->whereKey($data['credit_type_id'])
                            ->firstOrFail();

                        // Reverse every line's REMAINING qty (PROV-018): full qty
                        // minus what earlier credit notes already returned, so this
                        // still works after a partial credit — on a provider channel
                        // this is the ONLY way to cancel a MARKed invoice, so it must
                        // never dead-end on «Επιστροφή > διαθέσιμη ποσότητα».
                        // IssueCreditNote writes credited_invoice_id (the bind shown
                        // under «Σχετικά παραστατικά» on both records).
                        $credit = app(IssueCreditNote::class)->reverseRemaining($record, $creditType);

                        if ($data['submit_now'] ?? false) {
                            try {
                                app(EInvoiceSubmitterFactory::class)->for($record->company)->submit($credit);
                            } catch (Throwable $e) {
                                Notification::make()
                                    ->title('Το πιστωτικό δημιουργήθηκε, αλλά η υποβολή απέτυχε')
                                    ->body($e->getMessage().' Υποβάλετέ το ξανά από τη σελίδα του πιστωτικού.')
                                    ->danger()->persistent()->send();
                            }
                        }

                        Notification::make()
                            ->title('Εκδόθηκε πιστωτικό ακύρωσης')
                            ->body('Πιστωτικό '.$credit->invcode.' — αντιστρέφει το '.$record->invcode.'.'
                                .(($data['submit_now'] ?? false) ? '' : ' (πρόχειρο — δεν υποβλήθηκε)'))
                            ->success()->send();

                        $this->redirect(static::getResource()::getUrl('view', ['record' => $credit, 'tenant' => $record->company]));
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Αποτυχία ακύρωσης μέσω πιστωτικού')
                            ->body($e->getMessage())
                            ->danger()->persistent()->send();
                    }
                }),

            // PROVIDER storno & reissue: the one-click correction for a MARKed
            // invoice. Issues a FULL credit note (reversal) AND opens a fresh
            // draft copy to fix — saves doing the two steps by hand. Same gate as
            // the info action; needs a credit invoice type configured. Filing the
            // credit note now is opt-in (mirrors «Έκδοση πιστωτικού»); the
            // corrected reissue is filed later via the normal lifecycle.
            Action::make('storno_and_reissue')
                ->label('Ακύρωση & επανέκδοση')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color('danger')
                ->visible(fn (Invoice $record) => $isProviderChannel
                    && $record->mydata_state === 'VALID'
                    && $record->credited_invoice_id === null
                    && ! $record->isFullyCredited()
                    && $record->invoiceType?->mydata_type !== '9.3'
                    && self::creditTypes($record)->isNotEmpty())
                ->authorize(fn (Invoice $record) => auth()->user()?->can('update', $record) ?? false)
                ->requiresConfirmation()
                ->modalHeading('Ακύρωση & επανέκδοση')
                ->modalDescription('Εκδίδεται ΟΛΙΚΟ πιστωτικό (αναστρέφει το αρχικό) και δημιουργείται νέο ΠΡΟΧΕΙΡΟ αντίγραφο για διόρθωση. Διορθώστε το πρόχειρο και εκδώστε το κανονικά.')
                ->modalSubmitActionLabel('Ακύρωση & επανέκδοση')
                ->schema([
                    Select::make('credit_type_id')
                        ->label('Τύπος πιστωτικού')
                        ->options(fn (Invoice $record) => self::creditTypes($record)
                            ->mapWithKeys(fn (InvoiceType $t) => [$t->id => $t->code.' — '.$t->name]))
                        ->required(),
                    Toggle::make('submit_now')
                        ->label('Υποβολή πιστωτικού στο myDATA τώρα')
                        ->helperText('Αν είναι ανενεργό, το πιστωτικό μένει πρόχειρο και υποβάλλεται αργότερα χειροκίνητα.')
                        ->default(false)
                        ->visible($tenantSupportsMyData),
                ])
                ->action(function (Invoice $record, array $data) {
                    try {
                        $creditType = InvoiceType::query()
                            ->where('company_id', $record->company_id)
                            ->whereKey($data['credit_type_id'])
                            ->firstOrFail();

                        $result = app(StornoAndReissue::class)($record, $creditType);

                        // Opt-in filing of the credit note — runs AFTER the
                        // StornoAndReissue transaction committed (same post-commit
                        // submit pattern as «Έκδοση πιστωτικού»).
                        if ($data['submit_now'] ?? false) {
                            try {
                                app(EInvoiceSubmitterFactory::class)->for($record->company)->submit($result['credit']);
                            } catch (Throwable $e) {
                                Notification::make()
                                    ->title('Δημιουργήθηκαν, αλλά η υποβολή του πιστωτικού απέτυχε')
                                    ->body($e->getMessage().' Υποβάλετέ το ξανά από τη σελίδα του πιστωτικού.')
                                    ->danger()->persistent()->send();
                            }
                        }

                        // PROV-019: don't claim a completed «ακύρωση» while the credit
                        // is still a draft — the original stays VALID at AADE until the
                        // credit is filed. Say what actually happened.
                        $creditFiled = ($data['submit_now'] ?? false) && $result['credit']->mydata_state === 'VALID';
                        Notification::make()
                            ->title($creditFiled ? 'Έγινε ακύρωση & επανέκδοση' : 'Δημιουργήθηκαν πιστωτικό (πρόχειρο) & επανέκδοση')
                            ->body('Πιστωτικό: '.$result['credit']->invcode
                                .($creditFiled ? '' : ' (πρόχειρο — υπόβαλέ το για να ολοκληρωθεί η ακύρωση στην ΑΑΔΕ)')
                                .' · Νέο πρόχειρο: '.$result['reissue']->invcode.' — διορθώστε & εκδώστε το.')
                            ->success()->send();

                        // Land on the new draft so the operator fixes it right away.
                        $this->redirect(static::getResource()::getUrl('edit', ['record' => $result['reissue'], 'tenant' => $record->company]));
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Αποτυχία ακύρωσης & επανέκδοσης')
                            ->body($e->getMessage())
                            ->danger()->persistent()->send();
                    }
                }),

            // Already reversed by a credit note (fully credited) → the credit/cancel
            // actions are gone (nothing left to reverse). This is the only action
            // that makes sense now: re-bill via a fresh draft copy. Universal — a
            // fully-credited invoice on any channel can be re-issued.
            Action::make('reissue_only')
                ->label('Επανέκδοση')
                ->icon('heroicon-o-document-duplicate')
                ->color('warning')
                ->visible(fn (Invoice $record) => $record->isFullyCredited())
                ->authorize(fn (Invoice $record) => auth()->user()?->can('update', $record) ?? false)
                ->requiresConfirmation()
                ->modalHeading('Επανέκδοση παραστατικού')
                ->modalDescription(fn (Invoice $record) => ($record->isLegallyReversed()
                    ? 'Το παραστατικό έχει ακυρωθεί με πιστωτικό. '
                    : '⚠ Το πιστωτικό ακύρωσης είναι ακόμη πρόχειρο (δεν έχει υποβληθεί στην ΑΑΔΕ) — υπόβαλέ το πρώτα, αλλιώς η υποβολή της επανέκδοσης θα δηλώσει διπλό τζίρο. ')
                    .'Δημιουργείται νέο ΠΡΟΧΕΙΡΟ αντίγραφο (ίδιος πελάτης/γραμμές) για να το επανεκδώσετε διορθωμένο — δεν εκδίδεται άλλο πιστωτικό.')
                ->modalSubmitActionLabel('Επανέκδοση')
                ->action(function (Invoice $record) {
                    try {
                        $reissue = app(ReissueInvoiceAsDraft::class)($record);
                        Notification::make()
                            ->title('Δημιουργήθηκε νέο πρόχειρο')
                            ->body('Νέο πρόχειρο: '.$reissue->invcode.' — διορθώστε & εκδώστε το.')
                            ->success()->send();
                        $this->redirect(static::getResource()::getUrl('edit', ['record' => $reissue, 'tenant' => $record->company]));
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Αποτυχία επανέκδοσης')
                            ->body($e->getMessage())
                            ->danger()->persistent()->send();
                    }
                }),

            // Preview the would-be myDATA XML without submitting. Safe
            // on any mode — uses MyDataSubmitter::previewXml() which
            // never reaches initFirebed(). Helpful for spec-debugging
            // before going live.
            Action::make('dry_run_submit')
                ->label('Preview submission XML')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->visible(fn () => Filament::getTenant()?->einvoice_provider === 'gr-mydata')
                ->authorize(fn (Invoice $record) => auth()->user()?->can('view', $record) ?? false)
                ->requiresConfirmation()
                ->modalHeading('Preview the XML this invoice would send to myDATA')
                ->modalDescription('Builds the AADE payload and records it as a DRY_RUN row in the audit history. Does NOT contact AADE. Safe on any mode.')
                ->modalSubmitActionLabel('Generate preview')
                ->action(function (Invoice $record) {
                    // Gapless-at-send: a provisional draft has no ΑΑ yet, so the payload
                    // can't be built (it carries the real code/series). Show a clear
                    // «issue first» message instead of the raw «has no ΑΑ number» error.
                    if ($record->code === null) {
                        Notification::make()
                            ->title('Δεν έχει δοθεί ακόμη ΑΑ')
                            ->body('Η προεπισκόπηση XML είναι διαθέσιμη μόλις το παραστατικό πάρει αριθμό — '
                                .'στην αποστολή στο myDATA ή στην οριστικοποίηση. Όσο είναι πρόχειρο κρατά '
                                .'προσωρινή ταυτότητα («ΠΡΟΣ-…») χωρίς ΑΑ.')
                            ->warning()->send();

                        return;
                    }

                    try {
                        // Same reasoning as Submit/Cancel: derive the
                        // tenant from the record's own company FK
                        // (always present) rather than from
                        // Filament::getTenant() (nullable in non-panel
                        // contexts).
                        (new MyDataSubmitter($record->company))->previewXml($record);
                        Notification::make()
                            ->title('Dry-run recorded')
                            ->body('Open the new DRY_RUN row in the myDATA submission history (below) and click "Request XML".')
                            ->success()->send();
                        $this->redirect(static::getResource()::getUrl('view', [
                            'record' => $record,
                            'tenant' => $record->company,
                        ]));
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Dry-run failed')
                            ->body($e->getMessage())
                            ->danger()->send();
                    }
                }),

            // Provider tenants: show the EXACT payload that would be sent to the
            // provider (AADE InvoicesDoc + the InvoSign extension), read-only, with
            // NO network call and NO persistence. The token is NOT part of the
            // payload (it's a separate field at send time), so nothing secret leaks.
            Action::make('preview_provider_payload')
                ->label('Προεπισκόπηση παρόχου (XML)')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->visible(fn () => $isProviderChannel)
                ->authorize(fn (Invoice $record) => auth()->user()?->can('view', $record) ?? false)
                ->modalHeading('Τι θα σταλεί στον Πάροχο')
                ->modalDescription('Το ακριβές περιεχόμενο που θα φύγει — χωρίς αποστολή/δίκτυο. Ο μυστικός κωδικός (token) ΔΕΝ περιλαμβάνεται· στέλνεται ξεχωριστά.')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Κλείσιμο')
                ->fillForm(function (Invoice $record): array {
                    // Gapless-at-send: a provisional draft has no ΑΑ, so the payload can't
                    // be built yet — say so plainly instead of a raw builder error.
                    if ($record->code === null) {
                        return ['payload' => 'Το παραστατικό δεν έχει ακόμη ΑΑ (προσωρινό «ΠΡΟΣ-…»). '
                            .'Η προεπισκόπηση παρόχου είναι διαθέσιμη μόλις δοθεί αριθμός στην αποστολή.'];
                    }

                    try {
                        $doc = new AadeInvoiceDocument($record->company);
                        $aade = $doc->toXml($doc->build($record));
                        $key = (string) $record->company->einvoice_provider_key;

                        return ['payload' => $key === 'invosign' ? InvoSignDocument::augment($aade, $record) : $aade];
                    } catch (Throwable $e) {
                        return ['payload' => 'Σφάλμα δημιουργίας payload: '.$e->getMessage()];
                    }
                })
                ->schema([
                    Textarea::make('payload')->label(false)->rows(22)->columnSpanFull()->readOnly(),
                ]),

            // Manual "resend email" — for invoices that already filed
            // but the customer didn't get the mail (typo on email,
            // bounce, asked for a re-send). Always available on
            // tenants with mail config set; for tenants without
            // customer.email the job logs + writes a 'failed' log row
            // so the operator sees WHY nothing happened. Doesn't
            // require the auto-email toggle (manual is opt-in by
            // clicking).
            Action::make('resend_email')
                ->label('Αποστολή PDF στον πελάτη')
                ->icon('heroicon-o-envelope')
                ->color('gray')
                // DOC-6: only an ISSUED, non-cancelled document may be emailed —
                // the mail body asserts «…που εκδόθηκε…» (that it was issued), so
                // sending a draft or a cancelled invoice would state a falsehood.
                // Same fail-closed predicate as the public PDF route.
                ->visible(fn (Invoice $record) => $record->customer?->email !== null
                    && $record->customer?->email !== ''
                    && $record->isPubliclyViewable())
                ->authorize(fn (Invoice $record) => auth()->user()?->can('view', $record) ?? false)
                ->requiresConfirmation()
                ->modalHeading('Send invoice PDF to the customer')
                ->modalDescription(fn (Invoice $record) => 'Queues a mail with the current PDF attached. To: '.($record->customer?->email ?? '—').'. BCC: tenant audit list (if configured). See the Send history section below for the lifecycle.')
                ->modalSubmitActionLabel('Queue email')
                ->action(function (Invoice $record) {
                    // Defence-in-depth: the invoice could have been cancelled
                    // between page render and click (visible() is not re-checked
                    // on submit). Never email a non-issued document.
                    if (! $record->isPubliclyViewable()) {
                        Notification::make()
                            ->title('Δεν στάλθηκε')
                            ->body('Μόνο εκδοθέντα (ενεργά, μη ακυρωμένα) παραστατικά αποστέλλονται με email.')
                            ->warning()->send();

                        return;
                    }

                    SendInvoiceEmail::dispatch(
                        $record,
                        trigger: 'manual',
                        triggeredByUserId: auth()->id(),
                    );
                    Notification::make()
                        ->title('Email queued')
                        ->body('The mail is in the queue; check the Send history section in a moment for status.')
                        ->success()->send();
                }),

            // ---- Combined ΤΔΑ movement lifecycle (Slice 3d-b) -------------------
            // A ΤΔΑ (is_delivery_note, tracking ON) drives the SAME issuer lifecycle as a
            // 9.x δελτίο, via the contract-typed DeliveryLifecycleService (§4-A1). These
            // mirror ViewDeliveryNote's actions but pass the Invoice; ALL are hidden for a
            // plain invoice AND for a tracking-OFF ΤΔΑ (no qrUrl → no lifecycle). Cancel is
            // NOT here — a ΤΔΑ cancels through the monetary «Ακύρωση» (cancel_at_mydata), §7.

            // «Έναρξη διακίνησης» — RegisterTransfer. VALID + delivery_state=registered.
            Action::make('register_transfer')
                ->label('Έναρξη διακίνησης')
                ->icon('heroicon-o-truck')
                ->color('primary')
                ->visible(fn (Invoice $record) => $record->is_delivery_note
                    && ! $record->without_digital_transport_tracking
                    && $record->mydata_state === 'VALID'
                    && $record->delivery_state === 'registered')
                ->authorize(fn (Invoice $record) => auth()->user()?->can('update', $record) ?? false)
                ->requiresConfirmation()
                ->modalHeading('Έναρξη διακίνησης (myDATA)')
                ->modalDescription('Δηλώνεται η παραλαβή των αγαθών και η έναρξη της διακίνησης. Το παραστατικό περνά σε κατάσταση «Σε διακίνηση».')
                ->modalSubmitActionLabel('Έναρξη')
                ->action(fn (Invoice $record) => $this->runMovementLifecycle(
                    $record,
                    fn (DeliveryLifecycleService $svc) => $svc->registerTransfer($record),
                    'Δηλώθηκε η έναρξη διακίνησης',
                )),

            // «Δήλωση επιστροφής» — ConfirmDeliveryReturn (§3.2.7). Sources per
            // CONFIRM_RETURN_FROM_STATES (rejected/partial/failed/in_transit_return). No
            // issuer «Δήλωση παράδοσης» — the outcome is the recipient's/carrier's [833].
            Action::make('confirm_return')
                ->label('Δήλωση επιστροφής')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn (Invoice $record) => $record->is_delivery_note
                    && ! $record->without_digital_transport_tracking
                    && in_array($record->delivery_state, DeliveryLifecycleService::CONFIRM_RETURN_FROM_STATES, true))
                ->authorize(fn (Invoice $record) => auth()->user()?->can('update', $record) ?? false)
                ->requiresConfirmation()
                ->modalHeading('Δήλωση επιστροφής (myDATA)')
                ->modalDescription('Δηλώνεται ότι ο μεταφορέας δεν παρέδωσε το σύνολο των αγαθών και τα επέστρεψε στον εκδότη. Η διακίνηση ολοκληρώνεται ως «Επιστράφηκε».')
                ->modalSubmitActionLabel('Δήλωση επιστροφής')
                ->action(fn (Invoice $record) => $this->runMovementLifecycle(
                    $record,
                    fn (DeliveryLifecycleService $svc) => $svc->confirmReturn($record),
                    'Δηλώθηκε η επιστροφή',
                )),

            // «Έλεγχος κατάστασης διακίνησης (ΑΑΔΕ)» — RequestDeliveryNoteStatus
            // (read-only). A remote CANCELLED routes through the monetary choke-point
            // (SyncInvoiceStateFromAade) for a ΤΔΑ invoice — see the service (§7).
            Action::make('refresh_movement_status')
                ->label('Έλεγχος κατάστασης διακίνησης (ΑΑΔΕ)')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (Invoice $record) => $record->is_delivery_note
                    && ! $record->without_digital_transport_tracking
                    && ! empty($record->mydata_mark))
                ->authorize(fn (Invoice $record) => auth()->user()?->can('view', $record) ?? false)
                ->action(function (Invoice $record) {
                    try {
                        $svc = app(DeliveryLifecycleService::class, ['tenant' => $record->company]);
                        $result = $svc->refreshStatus($record);

                        $eventsLine = ($result['events_synced'] ?? 0) > 0
                            ? ' Ιστορικό διακίνησης: '.$result['events_synced'].' γεγονότα.'
                            : '';

                        if ($result['state_synced'] ?? false) {
                            Notification::make()
                                ->title('Το ΤΔΑ ΑΚΥΡΩΘΗΚΕ στην ΑΑΔΕ')
                                ->body('Εντοπίστηκε ακύρωση εκτός ekdosi και συγχρονίστηκε: κατάσταση → Ακυρώθηκε '
                                    .'(τοπικά + myDATA), το απόθεμα επιστράφηκε. Δες το «Ιστορικό myDATA».'.$eventsLine)
                                ->warning()
                                ->persistent()
                                ->send();
                        } else {
                            $stateLine = $result['changed']
                                ? 'Η κατάσταση διακίνησης ενημερώθηκε.'
                                : 'Καμία αλλαγή — η τοπική κατάσταση διακίνησης συμφωνεί με την ΑΑΔΕ.';

                            Notification::make()
                                ->title('Κατάσταση διακίνησης ΑΑΔΕ: '.($result['aade_label'] ?? '—'))
                                ->body($stateLine.$eventsLine)
                                ->success()
                                ->send();
                        }

                        $this->refreshFormData(['delivery_state', 'mydata_state', 'local_status']);
                    } catch (Throwable $e) {
                        $this->movementLifecycleError($e);
                    }
                }),

            // PDF download. Works for any invoice regardless of state —
            // operators may want a paper trail of drafts too.
            Action::make('download_pdf')
                ->label('Download PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->authorize(fn (Invoice $record) => auth()->user()?->can('view', $record) ?? false)
                ->action(function (Invoice $record) {
                    // Render the PDF UP-FRONT so any error surfaces as a
                    // proper exception (visible Filament notification)
                    // rather than a half-streamed corrupt download. The
                    // legacy `fn () => print(...)` shape ran inside
                    // Symfony's streamDownload callback where exceptions
                    // are caught and swallowed by the response writer.
                    // `print` also coerces the PDF byte-string through
                    // PHP's bool return semantics; safe in practice but
                    // `echo` is the idiomatic stream emitter.
                    $pdfBytes = app(InvoicePdfRenderer::class)->render($record);

                    return response()->streamDownload(
                        function () use ($pdfBytes): void {
                            echo $pdfBytes;
                        },
                        'invoice-'.$record->invcode.'.pdf',
                        ['Content-Type' => 'application/pdf'],
                    );
                }),

            // UBL / PEPPOL BIS Billing 3.0 (EN 16931) preview. Phase 1: show +
            // download only — no transport/Access-Point send yet. Deliberately
            // NOT gated on einvoice_provider: we produce the standard document
            // for every tenant (GR mainland included), so a provider can be
            // plugged in later without touching the mapping.
            Action::make('preview_ubl')
                ->label('Προβολή UBL')
                ->icon('heroicon-o-code-bracket')
                ->color('gray')
                ->authorize(fn (Invoice $record) => auth()->user()?->can('view', $record) ?? false)
                ->modalHeading('UBL — PEPPOL BIS Billing 3.0')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Κλείσιμο')
                ->modalWidth('5xl')
                ->modalContent(function (Invoice $record): HtmlString {
                    $doc = app(PeppolInvoiceDocument::class);

                    try {
                        $xml = $doc->xml($record);
                        $error = $doc->validate($record);
                    } catch (Throwable $e) {
                        return new HtmlString(
                            '<div style="padding:.75rem 1rem;border-radius:.5rem;background:#fee2e2;color:#991b1b;">'
                            .'Αδυναμία παραγωγής UBL: '.e($e->getMessage()).'</div>'
                        );
                    }

                    $status = $error === null
                        ? '<div style="padding:.5rem .75rem;border-radius:.5rem;background:#dcfce7;color:#166534;'
                            .'font-size:.8125rem;margin-bottom:.75rem;">✓ Πέρασε τον έλεγχο EN 16931 + PEPPOL '
                            .'(υποσύνολο). Ο οριστικός έλεγχος γίνεται από το Access Point (Phase 2).</div>'
                        : '<div style="padding:.5rem .75rem;border-radius:.5rem;background:#fef9c3;color:#854d0e;'
                            .'font-size:.8125rem;margin-bottom:.75rem;">⚠ '.e($error).'</div>';

                    return new HtmlString(
                        $status
                        .'<div style="max-height:60vh;overflow:auto;border:1px solid #e5e7eb;border-radius:.5rem;">'
                        .'<pre style="margin:0;padding:.75rem;font-size:.75rem;line-height:1.4;'
                        .'white-space:pre-wrap;word-break:break-word;">'.e($xml).'</pre></div>'
                    );
                }),

            // UBL download — same document as the preview, as a .xml file.
            Action::make('download_ubl')
                ->label('Λήψη UBL')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->authorize(fn (Invoice $record) => auth()->user()?->can('view', $record) ?? false)
                ->action(function (Invoice $record) {
                    // Build UP-FRONT so a mapping/validation error surfaces as a
                    // Filament notification, not a half-streamed corrupt file
                    // (same reasoning as download_pdf above).
                    $xml = app(PeppolInvoiceDocument::class)->xml($record);

                    return response()->streamDownload(
                        function () use ($xml): void {
                            echo $xml;
                        },
                        'invoice-'.$record->invcode.'.xml',
                        ['Content-Type' => 'application/xml'],
                    );
                }),
        ];
    }

    /**
     * Combined ΤΔΑ (3d-b): run a movement-lifecycle call on the invoice (resolved for
     * its own company), show a Greek success notification with the new movement state,
     * and refresh the view. Mirrors ViewDeliveryNote::runLifecycle — the service is
     * contract-typed, so it accepts the Invoice. Any RuntimeException/Throwable surfaces
     * as a persistent danger notification (no 500).
     *
     * @param  callable(DeliveryLifecycleService):mixed  $call
     */
    private function runMovementLifecycle(Invoice $record, callable $call, string $successTitle): void
    {
        try {
            $svc = app(DeliveryLifecycleService::class, ['tenant' => $record->company]);
            $call($svc);

            Notification::make()
                ->title($successTitle)
                ->body('Κατάσταση διακίνησης: '.(DeliveryLifecycleService::stateLabel($record->fresh()->delivery_state) ?? '—'))
                ->success()
                ->send();

            $this->redirect(static::getResource()::getUrl('view', [
                'record' => $record,
                'tenant' => $record->company,
            ]));
        } catch (Throwable $e) {
            $this->movementLifecycleError($e);
        }
    }

    private function movementLifecycleError(Throwable $e): void
    {
        Notification::make()
            ->title('Η ενέργεια διακίνησης απέτυχε')
            ->body($e->getMessage())
            ->danger()
            ->persistent()
            ->send();
    }

    /**
     * True iff this invoice is a live, still-open receivable that carries a
     * FILED WHMCS link — i.e. there is actually something for the «Έχει
     * πληρωθεί στο WHMCS;» action to check. Cheap in-memory gates first
     * (credit-term, non-cancelled, non-credit, balance > 0), then the single
     * linked-row exists() query. Mirrors WhmcsPaymentSyncer's own eligibility.
     */
    protected static function hasOpenWhmcsLink(Invoice $invoice): bool
    {
        if ($invoice->credited_invoice_id !== null
            || $invoice->isCreditNote()
            || $invoice->local_status === 'cancelled'
            || $invoice->mydata_state === 'CANCELLED'
            || (int) ($invoice->paymentMethod?->due_days ?? 0) <= 0
            || $invoice->balanceData()->balance <= 0.005) {
            return false;
        }

        return PendingWhmcsInvoice::query()
            ->where('company_id', $invoice->company_id)
            ->where('invoice_id', $invoice->id)
            ->where('status', PendingWhmcsInvoice::STATUS_FILED)
            ->whereNotNull('whmcs_invoice_id')
            ->exists();
    }

    /**
     * True iff «Σήμανση Paid στο WHMCS» applies: the tenant opted into outbound,
     * the invoice is a live, credit-term, locally-SETTLED receivable settled by a
     * REAL (non-inbound) payment, and its FILED WHMCS link has not yet been
     * pushed. Fully mirrors WhmcsPaymentPusher's eligibility (incl. the anti-echo
     * real-payment check) so the button shows only when a push would actually act.
     */
    protected static function hasUnpushedWhmcsSettlement(Invoice $invoice): bool
    {
        if (! (bool) $invoice->company?->whmcs_push_payments
            || $invoice->credited_invoice_id !== null
            || $invoice->isCreditNote()
            || $invoice->local_status === 'cancelled'
            || $invoice->mydata_state === 'CANCELLED'
            || (int) ($invoice->paymentMethod?->due_days ?? 0) <= 0
            || $invoice->balanceData()->balance > 0.005) {
            return false;
        }

        // Anti-echo (mirror WhmcsPaymentPusher::hasRealPayment): a receivable
        // settled ONLY by the inbound sync (whmcs-paid:*) must not offer a push
        // back — the button would just no-op. Require a real ekdosi payment.
        $hasRealPayment = Payment::query()
            ->where('invoice_id', $invoice->id)
            ->where('kind', 'payment')
            ->where(function ($q) {
                $q->whereNull('transaction_id')
                    ->orWhere('transaction_id', 'not like', 'whmcs-paid:%');
            })
            ->exists();
        if (! $hasRealPayment) {
            return false;
        }

        return PendingWhmcsInvoice::query()
            ->where('company_id', $invoice->company_id)
            ->where('invoice_id', $invoice->id)
            ->where('status', PendingWhmcsInvoice::STATUS_FILED)
            ->whereNotNull('whmcs_invoice_id')
            ->whereNull('whmcs_payment_pushed_at')
            ->exists();
    }

    /**
     * Is the invoice's issue date already today in Greece-local time? Uses the
     * SAME timezone as ProviderIssueDateGuard so the button hides exactly when the
     * guard would pass — no off-by-a-timezone mismatch between the two.
     */
    protected static function issuedToday(Invoice $invoice): bool
    {
        if ($invoice->issued_at === null) {
            return false;
        }

        $tz = ProviderIssueDateGuard::TZ;

        return $invoice->issued_at->copy()->setTimezone($tz)->toDateString()
            === now()->setTimezone($tz)->toDateString();
    }

    /** Credit invoice types for the invoice's tenant. */
    protected static function creditTypes(Invoice $invoice): Collection
    {
        return InvoiceType::query()
            ->where('company_id', $invoice->company_id)
            ->where('is_credit', true)
            // Defence-in-depth: a credit type is monetary — exclude a 9.x series
            // mis-flagged is_credit so it can never seed a credit note (MYD-003).
            ->monetary()
            ->orderBy('code')
            ->get();
    }

    /**
     * S2.5: after activation, if any tracked product on the invoice is now at
     * negative stock, show a NON-blocking warning. The issue already proceeded
     * (warn-only by design) — this is purely a heads-up so the operator knows a
     * backorder exists.
     */
    protected static function warnIfStockWentNegative(Invoice $invoice): void
    {
        $stock = app(StockService::class);
        $negatives = [];

        foreach ($invoice->lines()->with('product')->get() as $line) {
            $product = $line->product;
            if (! $product || ! $product->track_stock) {
                continue;
            }
            $level = $stock->currentStock($product);
            if ($level < 0) {
                $n = rtrim(rtrim(number_format($level, 3, '.', ''), '0'), '.');
                $negatives[] = "{$product->description_short} ({$n})";
            }
        }

        if ($negatives !== []) {
            Notification::make()
                ->warning()
                ->title('Αρνητικό απόθεμα')
                ->body('Σε αρνητικό: '.implode(', ', $negatives).'. Η έκδοση προχώρησε κανονικά (backorder).')
                ->persistent()
                ->send();
        }
    }
}
