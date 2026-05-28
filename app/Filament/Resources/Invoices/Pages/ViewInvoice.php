<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Jobs\SendInvoiceEmail;
use App\Models\Invoice;
use App\Services\EInvoiceSubmitterFactory;
use App\Services\InvoicePdfRenderer;
use App\Services\MyDataSubmitter;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
            // Record a payment against this invoice. Not shown on credit
            // notes (they're money owed back, not collected). Overpay is
            // allowed (warned, not blocked) — real prepayments/rounding.
            Action::make('record_payment')
                ->label('Καταχώριση πληρωμής')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->visible(fn (Invoice $record) => $record->credited_invoice_id === null && $record->customer_id !== null)
                ->authorize(fn (Invoice $record) => auth()->user()?->can('update', $record) ?? false)
                ->modalHeading('Καταχώριση πληρωμής')
                ->modalSubmitActionLabel('Καταχώριση')
                ->schema([
                    \Filament\Forms\Components\TextInput::make('amount')
                        ->label('Ποσό')
                        ->numeric()
                        ->required()
                        ->default(fn (Invoice $record) => number_format(max($record->balanceData()->balance, 0), 2, '.', ''))
                        ->helperText(fn (Invoice $record) => 'Υπόλοιπο: '.number_format($record->balanceData()->balance, 2, ',', '.').' €'),
                    \Filament\Forms\Components\DatePicker::make('pay_date')
                        ->label('Ημερομηνία')
                        ->required()
                        ->default(now()),
                    \Filament\Forms\Components\Select::make('payment_method_id')
                        ->label('Τρόπος πληρωμής')
                        ->options(fn (Invoice $record) => \App\Models\PaymentMethod::query()
                            ->where('company_id', $record->company_id)
                            ->pluck('description', 'id'))
                        ->default(fn (Invoice $record) => $record->payment_method_id),
                    \Filament\Forms\Components\Textarea::make('notes')
                        ->label('Σημειώσεις')
                        ->rows(2),
                ])
                ->action(function (Invoice $record, array $data) {
                    \Illuminate\Support\Facades\DB::transaction(function () use ($record, $data) {
                        \App\Models\Payment::create([
                            'company_id'        => $record->company_id,
                            'customer_id'       => $record->customer_id,
                            'invoice_id'        => $record->id,
                            'payment_method_id' => $data['payment_method_id'] ?? null,
                            'amount'            => $data['amount'],
                            'pay_date'          => $data['pay_date'],
                            'notes'             => $data['notes'] ?? null,
                        ]);
                    });
                    Notification::make()
                        ->title('Η πληρωμή καταχωρίστηκε')
                        ->success()->send();
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
                ->label('Cancel via myDATA')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (Invoice $record) => $tenantSupportsMyData && $record->mydata_state === 'VALID')
                ->authorize(fn (Invoice $record) => auth()->user()?->can('update', $record) ?? false)
                ->requiresConfirmation()
                ->modalHeading('Cancel this invoice at AADE')
                ->modalDescription(fn (Invoice $record) => 'This sends a CANCEL request to AADE for MARK '.($record->mydata_mark ?? '?').'. The MARK is preserved in the audit trail; state becomes CANCELLED. Issue a correction invoice for the actual content fix.')
                ->modalSubmitActionLabel('Confirm cancellation')
                ->schema([
                    \Filament\Forms\Components\Textarea::make('reason')
                        ->label('Reason (recorded locally)')
                        ->rows(3)
                        ->placeholder('Why is this invoice being cancelled?'),
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
                        Notification::make()
                            ->title('Cancelled at myDATA')
                            ->body('AADE state is now CANCELLED; the original MARK is preserved in the audit trail.')
                            ->success()->send();
                        $this->redirect(static::getResource()::getUrl('view', [
                            'record' => $record,
                            'tenant' => $record->company,
                        ]));
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Cancellation failed')
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
}
