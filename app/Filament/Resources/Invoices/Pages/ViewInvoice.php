<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Actions\IssueCreditNote;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Jobs\SendInvoiceEmail;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\EInvoiceSubmitterFactory;
use App\Services\InvoicePdfRenderer;
use App\Services\MyDataSubmitter;
use App\Services\Stock\StockService;
use App\Support\InvoiceScope;
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
use Throwable;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        $tenantSupportsMyData = in_array(
            Filament::getTenant()?->mydata_mode,
            ['sandbox', 'production'],
            true,
        );

        return [
            // --- Local lifecycle: Πρόχειρο → Ενεργό → Ακυρωμένο.
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
                    $record->update(['local_status' => 'active']);

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
                    $record->update(['local_status' => 'draft']);
                    Notification::make()->title('Επαναφορά σε πρόχειρο')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record, 'tenant' => $record->company]));
                }),

            Action::make('cancel_local')
                ->label('Ακύρωση')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (Invoice $record) => in_array($record->local_status, ['draft', 'active'], true)
                    && $record->credited_invoice_id === null)
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
                    && (int) ($record->paymentMethod?->due_days ?? 0) > 0)
                ->authorize(fn (Invoice $record) => auth()->user()?->can('update', $record) ?? false)
                ->modalHeading('Καταχώριση πληρωμής')
                ->modalSubmitActionLabel('Καταχώριση')
                ->schema([
                    TextInput::make('amount')
                        ->label('Ποσό')
                        ->numeric()
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
                            'payment_method_id' => $data['payment_method_id'] ?? null,
                            'amount' => $data['amount'],
                            'pay_date' => $data['pay_date'],
                            'notes' => $data['notes'] ?? null,
                        ]);
                    });
                    Notification::make()
                        ->title('Η πληρωμή καταχωρίστηκε')
                        ->success()->send();
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
                ->visible(fn (Invoice $record) => $record->credited_invoice_id === null
                    && $record->mydata_state !== 'CANCELLED'
                    && self::creditTypes($record)->isNotEmpty())
                ->authorize(fn (Invoice $record) => auth()->user()?->can('update', $record) ?? false)
                ->modalHeading('Έκδοση πιστωτικού τιμολογίου')
                ->modalDescription('Επιλέξτε τύπο πιστωτικού και τις ποσότητες προς πίστωση ανά γραμμή (0 = εξαίρεση).')
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
                            Placeholder::make('label')
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
            Action::make('submit_to_mydata')
                ->label('Submit to myDATA')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->visible(fn (Invoice $record) => $tenantSupportsMyData && $record->mydata_state === null)
                ->authorize(fn (Invoice $record) => auth()->user()?->can('update', $record) ?? false)
                ->requiresConfirmation()
                ->modalHeading('File this invoice with AADE myDATA')
                ->modalDescription(fn () => Filament::getTenant()?->mydata_mode === 'production'
                    ? '⚠ Production mode — REAL filing. Legally binding MARK returned. Cannot be edited after; only cancelled + reissued.'
                    : 'Sandbox mode — files to AADE\'s test endpoint. Synthetic MARK.')
                ->modalSubmitActionLabel('Confirm submission')
                ->action(function (Invoice $record) {
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
                        // submitter (single choke-point).
                        Notification::make()
                            ->title('Filed at myDATA')
                            ->body('MARK: '.($mark->mark ?? 'pending'))
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
            Action::make('cancel_at_mydata')
                ->label('Ακύρωση μέσω myDATA')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (Invoice $record) => $tenantSupportsMyData && $record->mydata_state === 'VALID')
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

            // Manual "resend email" — for invoices that already filed
            // but the customer didn't get the mail (typo on email,
            // bounce, asked for a re-send). Always available on
            // tenants with mail config set; for tenants without
            // customer.email the job logs + writes a 'failed' log row
            // so the operator sees WHY nothing happened. Doesn't
            // require the auto-email toggle (manual is opt-in by
            // clicking).
            Action::make('resend_email')
                ->label('Email PDF to customer')
                ->icon('heroicon-o-envelope')
                ->color('gray')
                ->visible(fn (Invoice $record) => $record->customer?->email !== null && $record->customer?->email !== '')
                ->authorize(fn (Invoice $record) => auth()->user()?->can('view', $record) ?? false)
                ->requiresConfirmation()
                ->modalHeading('Send invoice PDF to the customer')
                ->modalDescription(fn (Invoice $record) => 'Queues a mail with the current PDF attached. To: '.($record->customer?->email ?? '—').'. BCC: tenant audit list (if configured). See the Send history section below for the lifecycle.')
                ->modalSubmitActionLabel('Queue email')
                ->action(function (Invoice $record) {
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
        ];
    }

    /** Credit invoice types for the invoice's tenant. */
    protected static function creditTypes(Invoice $invoice): Collection
    {
        return InvoiceType::query()
            ->where('company_id', $invoice->company_id)
            ->where('is_credit', true)
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
