<?php

namespace App\Filament\Resources\Quotes\Pages;

use App\Actions\ConvertQuoteToInvoice;
use App\Actions\ConvertQuoteToServiceContract;
use App\Enums\BillingCycle;
use App\Enums\QuoteStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Filament\Resources\ServiceContracts\ServiceContractResource;
use App\Jobs\SendQuoteEmail;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\Quote;
use App\Services\QuotePdfRenderer;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Carbon;
use Throwable;

class ViewQuote extends ViewRecord
{
    protected static string $resource = QuoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Επεξεργασία — only while not yet converted.
            EditAction::make()
                ->label('Επεξεργασία')
                ->visible(fn (Quote $record) => ! $record->isConverted()),

            // Αποδοχή — mark the offer accepted (enables convert).
            Action::make('accept')
                ->label('Αποδοχή')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (Quote $record) => in_array(
                    $record->status,
                    [QuoteStatus::Draft, QuoteStatus::Sent],
                    true,
                ))
                ->requiresConfirmation()
                ->modalHeading('Αποδοχή προσφοράς')
                ->modalDescription('Σημειώνεται ως «Αποδεκτή». Στη συνέχεια μπορείτε να τη μετατρέψετε σε παραστατικό.')
                ->action(function (Quote $record) {
                    $record->update(['status' => QuoteStatus::Accepted]);
                    Notification::make()->title('Η προσφορά έγινε Αποδεκτή')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record, 'tenant' => $record->company]));
                }),

            // Απόρριψη — with a reason captured in admin_notes.
            Action::make('reject')
                ->label('Απόρριψη')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (Quote $record) => in_array(
                    $record->status,
                    [QuoteStatus::Draft, QuoteStatus::Sent, QuoteStatus::Accepted],
                    true,
                ) && ! $record->isConverted())
                ->requiresConfirmation()
                ->modalHeading('Απόρριψη προσφοράς')
                ->schema([
                    Textarea::make('reason')
                        ->label('Αιτία (προαιρετικό, κρατείται στις ιδιωτικές σημειώσεις)')
                        ->rows(2),
                ])
                ->action(function (Quote $record, array $data) {
                    $note = trim((string) ($data['reason'] ?? ''));
                    $record->update([
                        'status' => QuoteStatus::Rejected,
                        'admin_notes' => $note !== ''
                            ? trim(($record->admin_notes ? $record->admin_notes."\n" : '').'Απόρριψη: '.$note)
                            : $record->admin_notes,
                    ]);
                    Notification::make()->title('Η προσφορά απορρίφθηκε')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record, 'tenant' => $record->company]));
                }),

            // Επαναφορά σε πρόχειρο (από Αποδεκτή/Απορριφθείσα/Έληξε).
            Action::make('reopen')
                ->label('Επαναφορά σε πρόχειρο')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->visible(fn (Quote $record) => in_array(
                    $record->status,
                    [QuoteStatus::Accepted, QuoteStatus::Rejected, QuoteStatus::Expired],
                    true,
                ) && ! $record->isConverted())
                ->requiresConfirmation()
                ->action(function (Quote $record) {
                    $record->update(['status' => QuoteStatus::Draft]);
                    Notification::make()->title('Επαναφορά σε πρόχειρο')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record, 'tenant' => $record->company]));
                }),

            // Μετατροπή σε Παραστατικό — gated to Accepted + not already converted.
            Action::make('convert_to_invoice')
                ->label('Μετατροπή σε Παραστατικό')
                ->icon('heroicon-o-document-plus')
                ->color('primary')
                ->visible(fn (Quote $record) => $record->status === QuoteStatus::Accepted
                    && ! $record->isConverted())
                ->modalHeading('Μετατροπή προσφοράς σε παραστατικό')
                ->modalDescription('Δημιουργείται ΠΡΟΧΕΙΡΟ παραστατικό με τις ίδιες γραμμές. Δεν υποβάλλεται στο myDATA — το εκδίδετε κανονικά από τη σελίδα του παραστατικού.')
                ->modalSubmitActionLabel('Δημιουργία προχείρου')
                ->schema([
                    Select::make('invoice_type_id')
                        ->label('Τύπος παραστατικού')
                        ->options(fn (Quote $record) => InvoiceType::query()
                            ->where('company_id', $record->company_id)
                            ->where('show_on_menu', true)
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn ($t) => [$t->id => $t->code.' — '.$t->name])
                            ->toArray())
                        ->searchable()
                        ->required(),
                ])
                ->action(function (Quote $record, array $data) {
                    try {
                        $type = InvoiceType::query()
                            ->where('company_id', $record->company_id)
                            ->whereKey($data['invoice_type_id'])
                            ->firstOrFail();

                        $invoice = app(ConvertQuoteToInvoice::class)($record, $type);

                        Notification::make()
                            ->title('Δημιουργήθηκε πρόχειρο παραστατικό')
                            ->body('Κωδικός: '.$invoice->invcode.' — εκδώστε το από τη σελίδα του παραστατικού.')
                            ->success()->send();

                        $this->redirect(InvoiceResource::getUrl('view', [
                            'record' => $invoice,
                            'tenant' => $record->company,
                        ]));
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Αποτυχία μετατροπής')
                            ->body($e->getMessage())
                            ->danger()->persistent()->send();
                    }
                }),

            // Μετατροπή σε Υπηρεσία — accepted + not converted. Creates a recurring
            // contract (for the future renewals) AND the first draft invoice with
            // ALL the quote's lines (one-time + the recurring first period).
            Action::make('convert_to_service')
                ->label('Μετατροπή σε Υπηρεσία')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color('primary')
                ->visible(fn (Quote $record) => $record->status === QuoteStatus::Accepted
                    && ! $record->isConverted())
                ->modalHeading('Μετατροπή προσφοράς σε υπηρεσία (συνδρομή)')
                ->modalDescription('Δημιουργείται συμβόλαιο για τις μελλοντικές ανανεώσεις + ΠΡΟΧΕΙΡΟ πρώτο παραστατικό με ΟΛΕΣ τις γραμμές της προσφοράς. Δεν υποβάλλεται στο myDATA.')
                ->modalSubmitActionLabel('Δημιουργία υπηρεσίας + προχείρου')
                ->schema([
                    Select::make('invoice_type_id')
                        ->label('Τύπος παραστατικού (πρώτο + ανανεώσεις)')
                        ->options(fn (Quote $record) => InvoiceType::query()
                            ->where('company_id', $record->company_id)
                            ->where('show_on_menu', true)
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn ($t) => [$t->id => $t->code.' — '.$t->name])
                            ->toArray())
                        ->searchable()
                        ->required(),
                    Select::make('billing_cycle')
                        ->label('Κύκλος χρέωσης')
                        ->options(collect(BillingCycle::options())->except(BillingCycle::OneTime->value)->toArray())
                        ->default(fn (Quote $record) => (
                            $record->loadMissing('lines.product')->firstRecurringLine()?->product?->default_billing_cycle
                        ) ?: BillingCycle::Annual->value)
                        ->required(),
                    TextInput::make('recurring_amount')
                        ->label('Επαναλαμβανόμενο ποσό (καθαρό, ανά κύκλο)')
                        ->numeric()->step('0.01')->minValue(0.01)->required()
                        ->prefix('€')
                        ->default(fn (Quote $record) => (function () use ($record) {
                            $sum = $record->loadMissing('lines.product')->recurringLinesNetTotal();

                            return $sum > 0 ? number_format($sum, 2, '.', '') : null;
                        })())
                        ->helperText('Προ-συμπληρώνεται από τις γραμμές με recurring προϊόν· οι υπόλοιπες (εφάπαξ/ελεύθερο κείμενο) μπαίνουν ΜΟΝΟ στο πρώτο παραστατικό.'),
                    Select::make('payment_method_id')
                        ->label('Τρόπος πληρωμής (προαιρετικό)')
                        ->options(fn (Quote $record) => PaymentMethod::query()
                            ->where('company_id', $record->company_id)
                            ->orderBy('description')
                            ->pluck('description', 'id'))
                        ->helperText('Επί πιστώσει → οι ανανεώσεις μένουν οφειλή/ληξιπρόθεσμες (για dunning).'),
                    DatePicker::make('start_date')
                        ->label('Ημερομηνία έναρξης')
                        ->default(now()),
                ])
                ->action(function (Quote $record, array $data) {
                    try {
                        $type = InvoiceType::query()
                            ->where('company_id', $record->company_id)
                            ->whereKey($data['invoice_type_id'])
                            ->firstOrFail();

                        $contract = app(ConvertQuoteToServiceContract::class)(
                            $record,
                            $type,
                            BillingCycle::from($data['billing_cycle']),
                            (float) $data['recurring_amount'],
                            $data['payment_method_id'] ?? null,
                            ! empty($data['start_date']) ? Carbon::parse($data['start_date']) : null,
                        );

                        Notification::make()
                            ->title('Δημιουργήθηκε υπηρεσία + πρόχειρο παραστατικό')
                            ->body('Εκδώστε το πρώτο παραστατικό από τη σελίδα του· οι ανανεώσεις τρέχουν αυτόματα.')
                            ->success()->send();

                        $this->redirect(ServiceContractResource::getUrl('view', [
                            'record' => $contract,
                            'tenant' => $record->company,
                        ]));
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Αποτυχία μετατροπής')
                            ->body($e->getMessage())
                            ->danger()->persistent()->send();
                    }
                }),

            // Download the quote PDF — any state.
            Action::make('download_pdf')
                ->label('Λήψη PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function (Quote $record) {
                    // Render up-front so an error surfaces as a notification,
                    // not a half-streamed corrupt download (same as ViewInvoice).
                    $pdfBytes = app(QuotePdfRenderer::class)->render($record);

                    return response()->streamDownload(
                        function () use ($pdfBytes): void {
                            echo $pdfBytes;
                        },
                        'quote-'.$record->code.'.pdf',
                        ['Content-Type' => 'application/pdf'],
                    );
                }),

            // Email the quote PDF to the customer (queued; history below).
            Action::make('send_email')
                ->label('Αποστολή με email')
                ->icon('heroicon-o-envelope')
                ->color('gray')
                ->visible(fn (Quote $record) => $record->customer?->email !== null && $record->customer?->email !== '')
                ->requiresConfirmation()
                ->modalHeading('Αποστολή προσφοράς με email')
                ->modalDescription(fn (Quote $record) => 'Μπαίνει στην ουρά email με το PDF συνημμένο. Προς: '.($record->customer?->email ?? '—').'. Δείτε το «Ιστορικό αποστολών» πιο κάτω για την κατάσταση.')
                ->modalSubmitActionLabel('Αποστολή')
                ->action(function (Quote $record) {
                    SendQuoteEmail::dispatch(
                        $record,
                        trigger: 'manual',
                        triggeredByUserId: auth()->id(),
                    );
                    Notification::make()
                        ->title('Η προσφορά μπήκε στην ουρά αποστολής')
                        ->body('Δείτε το «Ιστορικό αποστολών» σε λίγο για την κατάσταση.')
                        ->success()->send();
                }),

            // Link to the produced invoice (bidirectional history).
            Action::make('open_invoice')
                ->label('Προβολή παραστατικού')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->visible(fn (Quote $record) => $record->isConverted())
                ->url(fn (Quote $record) => $record->converted_invoice_id
                    ? InvoiceResource::getUrl('view', [
                        'record' => $record->converted_invoice_id,
                        'tenant' => $record->company,
                    ])
                    : null),
        ];
    }
}
