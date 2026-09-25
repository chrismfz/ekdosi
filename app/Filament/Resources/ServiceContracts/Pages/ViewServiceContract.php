<?php

namespace App\Filament\Resources\ServiceContracts\Pages;

use App\Actions\StageServiceRenewal;
use App\Enums\ServiceContractStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\ServiceContracts\ServiceContractResource;
use App\Models\Invoice;
use App\Models\ServiceContract;
use App\Services\ServiceContractBilling;
use App\Support\InvoiceScope;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * View + lifecycle for a service contract. Each transition action is gated by
 * ServiceContractStatus::canTransitionTo() (the single source for what's
 * allowed) AND mutates only the lifecycle columns — never money. Cancellation
 * and termination CASCADE to the contract's UNISSUED draft renewals (drafts
 * with no MARK): those get local_status='cancelled'. A renewal that already
 * has a MARK or is no longer a draft is a legal document the operator handles
 * separately and is left untouched.
 */
class ViewServiceContract extends ViewRecord
{
    protected static string $resource = ServiceContractResource::class;

    /** Memoised per request so the infolist entries share one query pass. */
    private ?ServiceContractBilling $billing = null;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    TextEntry::make('customer.name')->label('Πελάτης'),
                    TextEntry::make('description')->label('Περιγραφή'),
                    TextEntry::make('billing_cycle')
                        ->label('Κύκλος')
                        ->badge()
                        ->formatStateUsing(fn ($state) => $state?->label()),
                    TextEntry::make('status')
                        ->label('Κατάσταση')
                        ->badge()
                        ->formatStateUsing(fn (ServiceContractStatus $state) => $state->label())
                        ->color(fn (ServiceContractStatus $state) => $state->color()),
                    TextEntry::make('amount')->label('Ποσό (καθαρό)')->money('EUR'),
                    TextEntry::make('vat_percent')->label('ΦΠΑ %')->suffix('%'),
                    TextEntry::make('invoiceType.code')->label('Τύπος ανανέωσης')->placeholder('— (δεν έχει οριστεί)'),
                    TextEntry::make('next_due_date')->label('Επόμενη χρέωση')->date('Y-m-d'),
                    TextEntry::make('start_date')->label('Έναρξη')->date('Y-m-d'),
                    TextEntry::make('end_date')->label('Λήξη')->date('Y-m-d'),
                    TextEntry::make('last_invoiced_at')->label('Τελευταία χρέωση')->dateTime('Y-m-d H:i'),
                    TextEntry::make('server.name')->label('Server')->placeholder('—'),
                    TextEntry::make('domain')->label('Domain')->placeholder('—'),
                ]),

            // #11 per-contract billing analytics — how many times billed, the net
            // revenue it produced (live, minus credits), the billing window, and
            // its list-price timeline. All figures via ServiceContractBilling.
            Section::make('Στατιστικά χρέωσης')
                ->columns(3)
                ->schema([
                    TextEntry::make('billed_count')
                        ->label('Φορές τιμολογήθηκε')
                        ->state(fn (): string => (string) $this->billing()->billedCount()),
                    TextEntry::make('net_revenue')
                        ->label('Συνολικό έσοδο (καθαρό)')
                        ->state(fn (): string => Money::eur($this->billing()->netRevenue()))
                        ->helperText('Live παραστατικά, μείον πιστωτικά'),
                    TextEntry::make('gross_billed')
                        ->label('Μικτό σύνολο')
                        ->state(fn (): string => Money::eur($this->billing()->grossBilled()))
                        ->helperText('Live παραστατικά, μείον πιστωτικά'),
                    TextEntry::make('first_billed')
                        ->label('Πρώτη χρέωση')
                        ->state(fn (): ?string => $this->billing()->firstBilledAt()?->format('d/m/Y'))
                        ->placeholder('—'),
                    TextEntry::make('last_billed')
                        ->label('Τελευταία χρέωση')
                        ->state(fn (): ?string => $this->billing()->lastBilledAt()?->format('d/m/Y'))
                        ->placeholder('—'),
                    TextEntry::make('pending_drafts')
                        ->label('Εκκρεμή πρόχειρα')
                        ->state(fn (): string => (string) $this->billing()->pendingDraftCount()),
                    TextEntry::make('price_history')
                        ->label('Ιστορικό τιμής καταλόγου')
                        ->state(fn (): array => $this->billing()->priceHistory())
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->placeholder('Καμία καταγεγραμμένη αλλαγή τιμής')
                        ->columnSpanFull(),
                ]),

            // The contract's free-text notes (IPs, hostnames…) — shown here too, not
            // only in the edit form. Line breaks kept; the text is escaped first.
            Section::make('Σημειώσεις')
                ->columnSpanFull()
                ->visible(fn (ServiceContract $record): bool => filled($record->notes))
                ->schema([
                    TextEntry::make('notes')
                        ->hiddenLabel()
                        ->formatStateUsing(fn (?string $state): HtmlString => new HtmlString(nl2br(e((string) $state)))),
                ]),
        ]);
    }

    /** The per-contract billing analytics for the viewed record (memoised). */
    private function billing(): ServiceContractBilling
    {
        /** @var ServiceContract $record */
        $record = $this->getRecord();

        return $this->billing ??= new ServiceContractBilling($record);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Επεξεργασία'),

            // #11 retro-link: attach an ALREADY-ISSUED invoice of the same customer
            // to this contract (the «I sold it manually and forgot to make it a
            // subscription» case). Sets only invoices.service_contract_id — never
            // money/lifecycle — so the invoice then counts in the billing history.
            Action::make('link_invoice')
                ->label('Σύνδεση υπάρχοντος παραστατικού')
                ->icon('heroicon-o-link')
                ->color('gray')
                ->modalHeading('Σύνδεση υπάρχοντος παραστατικού')
                ->modalDescription('Συνδέει ένα ήδη εκδομένο παραστατικό του ίδιου πελάτη σε αυτή τη σύμβαση, ώστε να μετρά στο ιστορικό/έσοδο. Δεν αλλάζει ποσά ούτε την υποβολή στο myDATA.')
                ->modalSubmitActionLabel('Σύνδεση')
                ->schema([
                    Select::make('invoice_id')
                        ->label('Παραστατικό')
                        ->required()
                        ->searchable()
                        // Server-side search over the eligible set (no cap): a
                        // customer with hundreds of invoices can still find an old
                        // one by code. The write re-checks the same predicate.
                        ->getSearchResultsUsing(fn (string $search, ServiceContract $record): array => static::linkableInvoiceQuery($record)
                            ->where(fn (Builder $q) => $q->where('invcode', 'like', "%{$search}%")
                                ->orWhere('code', 'like', "%{$search}%"))
                            ->orderByDesc('issued_at')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (Invoice $i): array => [$i->id => static::invoiceOptionLabel($i)])
                            ->all())
                        ->getOptionLabelUsing(fn ($value, ServiceContract $record): ?string => ($invoice = static::linkableInvoiceQuery($record)->whereKey($value)->first())
                            ? static::invoiceOptionLabel($invoice)
                            : null)
                        ->helperText('Μόνο live εκδομένα παραστατικά του πελάτη, μη δεμένα σε σύμβαση. Πληκτρολόγησε κωδικό.'),
                    Toggle::make('stamp_last_invoiced')
                        ->label('Θεώρησέ το ως την τελευταία χρέωση')
                        ->helperText('Ενημερώνει το «Τελευταία χρέωση» ώστε η επόμενη ανανέωση να μην ξαναβάλει τέλος εγκατάστασης.')
                        ->default(true),
                ])
                ->action(function (ServiceContract $record, array $data): void {
                    // Re-resolve UNDER the same tenant+customer+unlinked predicate
                    // as the options list (a crafted request can POST any id), so a
                    // foreign or already-linked invoice can never be attached.
                    $invoice = static::linkableInvoiceQuery($record)
                        ->whereKey((int) ($data['invoice_id'] ?? 0))
                        ->first();
                    if ($invoice === null) {
                        Notification::make()
                            ->title('Το παραστατικό δεν είναι διαθέσιμο για σύνδεση')
                            ->body('Ανήκει σε άλλον πελάτη/εταιρεία ή είναι ήδη δεμένο σε σύμβαση.')
                            ->warning()->send();

                        return;
                    }

                    // Both writes in one transaction: the link + the «last invoiced»
                    // stamp commit together. Once linked the invoice is no longer
                    // «linkable», so a half-applied state (linked but unstamped)
                    // could never be finished by re-running the action.
                    DB::transaction(function () use ($record, $invoice, $data): void {
                        $invoice->service_contract_id = $record->id;
                        $invoice->save();

                        // Optionally advance the contract's «τελευταία χρέωση» cursor
                        // so the next staged renewal knows a bill already happened
                        // (no repeated setup fee). Only ever move it FORWARD.
                        if (! empty($data['stamp_last_invoiced']) && $invoice->issued_at !== null
                            && ($record->last_invoiced_at === null || $invoice->issued_at->gt($record->last_invoiced_at))) {
                            $record->last_invoiced_at = $invoice->issued_at;
                            $record->save();
                        }
                    });

                    Notification::make()
                        ->title('Το παραστατικό συνδέθηκε — '.$invoice->invcode)
                        ->success()->send();
                    $this->redirectToView($record);
                }),

            // Ενεργοποίηση (Pending → Active). If no next_due_date set, seed
            // it from start_date or today so the contract starts billing.
            Action::make('activate')
                ->label('Ενεργοποίηση')
                ->icon('heroicon-o-play')
                ->color('success')
                ->visible(fn (ServiceContract $record) => $record->status->canTransitionTo(ServiceContractStatus::Active)
                    && $record->status === ServiceContractStatus::Pending)
                ->requiresConfirmation()
                ->action(function (ServiceContract $record) {
                    $record->update([
                        'status' => ServiceContractStatus::Active,
                        'next_due_date' => $record->next_due_date
                            ?? $record->start_date
                            ?? today(),
                    ]);
                    Notification::make()->title('Η υπηρεσία ενεργοποιήθηκε')->success()->send();
                    $this->redirectToView($record);
                }),

            // Αναστολή (Active → Suspended).
            Action::make('suspend')
                ->label('Αναστολή')
                ->icon('heroicon-o-pause')
                ->color('warning')
                ->visible(fn (ServiceContract $record) => $record->status->canTransitionTo(ServiceContractStatus::Suspended))
                ->requiresConfirmation()
                ->action(function (ServiceContract $record) {
                    $record->update([
                        'status' => ServiceContractStatus::Suspended,
                        'suspended_at' => now(),
                    ]);
                    Notification::make()->title('Η υπηρεσία τέθηκε σε αναστολή')->success()->send();
                    $this->redirectToView($record);
                }),

            // Επαναφορά λειτουργίας (Suspended → Active).
            Action::make('unsuspend')
                ->label('Επαναφορά λειτουργίας')
                ->icon('heroicon-o-play')
                ->color('success')
                ->visible(fn (ServiceContract $record) => $record->status === ServiceContractStatus::Suspended
                    && $record->status->canTransitionTo(ServiceContractStatus::Active))
                ->requiresConfirmation()
                ->action(function (ServiceContract $record) {
                    $record->update([
                        'status' => ServiceContractStatus::Active,
                        'suspended_at' => null,
                        'dunning_suspended_at' => null,
                    ]);
                    Notification::make()->title('Η υπηρεσία επανήλθε σε λειτουργία')->success()->send();
                    $this->redirectToView($record);
                }),

            // Ακύρωση (→ Cancelled). Clears the billing cursor + cascades to
            // unissued draft renewals.
            Action::make('cancel')
                ->label('Ακύρωση')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (ServiceContract $record) => $record->status->canTransitionTo(ServiceContractStatus::Cancelled))
                ->requiresConfirmation()
                ->modalHeading('Ακύρωση υπηρεσίας')
                ->modalDescription('Σταματά η χρέωση. Τυχόν πρόχειρα παραστατικά ανανέωσης (χωρίς ΜΑΡΚ) ακυρώνονται. Εκδομένα/υποβληθέντα παραστατικά ΔΕΝ θίγονται.')
                ->schema([
                    Textarea::make('reason')->label('Αιτία (προαιρετικό)')->rows(2),
                ])
                ->action(function (ServiceContract $record, array $data) {
                    $cancelled = static::cancelUnissuedDrafts($record);
                    $record->update([
                        'status' => ServiceContractStatus::Cancelled,
                        'next_due_date' => null,
                        'cancel_reason' => $data['reason'] ?? null,
                    ]);
                    static::notifyCascade('Η υπηρεσία ακυρώθηκε', $cancelled);
                    $this->redirectToView($record);
                }),

            // Τερματισμός (→ Terminated, terminal). Stamps terminated_at +
            // same draft cascade.
            Action::make('terminate')
                ->label('Τερματισμός')
                ->icon('heroicon-o-stop-circle')
                ->color('danger')
                ->visible(fn (ServiceContract $record) => $record->status->canTransitionTo(ServiceContractStatus::Terminated))
                ->requiresConfirmation()
                ->modalHeading('Τερματισμός υπηρεσίας')
                ->modalDescription('Οριστικός τερματισμός (δεν επαναφέρεται). Τυχόν πρόχειρα παραστατικά ανανέωσης (χωρίς ΜΑΡΚ) ακυρώνονται.')
                ->action(function (ServiceContract $record) {
                    $cancelled = static::cancelUnissuedDrafts($record);
                    $record->update([
                        'status' => ServiceContractStatus::Terminated,
                        'next_due_date' => null,
                        'terminated_at' => now(),
                    ]);
                    static::notifyCascade('Η υπηρεσία τερματίστηκε', $cancelled);
                    $this->redirectToView($record);
                }),

            // Επαναφορά (Cancelled → Active revive).
            Action::make('revive')
                ->label('Επαναφορά')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (ServiceContract $record) => $record->status === ServiceContractStatus::Cancelled
                    && $record->status->canTransitionTo(ServiceContractStatus::Active))
                ->requiresConfirmation()
                ->action(function (ServiceContract $record) {
                    $record->update([
                        'status' => ServiceContractStatus::Active,
                        'cancel_reason' => null,
                        // Cancel nulled the cursor — reseed it (no surprise
                        // back-bill) so the revived service bills again from
                        // here. Mirrors «Ενεργοποίηση».
                        'next_due_date' => $record->next_due_date
                            ?? $record->start_date
                            ?? now()->toDateString(),
                    ]);
                    Notification::make()->title('Η υπηρεσία επανήλθε')->success()->send();
                    $this->redirectToView($record);
                }),

            // «Δημιουργία παραστατικού τώρα» — manual bill-now. Stages a draft
            // renewal immediately via the same action the scheduler will use.
            Action::make('bill_now')
                ->label('Δημιουργία παραστατικού τώρα')
                ->icon('heroicon-o-document-plus')
                ->color('primary')
                ->visible(fn (ServiceContract $record) => $record->status === ServiceContractStatus::Active)
                ->requiresConfirmation()
                ->modalHeading('Δημιουργία πρόχειρου παραστατικού ανανέωσης')
                ->modalDescription('Δημιουργείται πρόχειρο παραστατικό για τον τρέχοντα κύκλο. Δεν υποβάλλεται στο myDATA — εκδίδετε εσείς από τα Παραστατικά.')
                ->action(function (ServiceContract $record) {
                    try {
                        $invoice = app(StageServiceRenewal::class)($record);
                        if ($invoice === null) {
                            Notification::make()
                                ->title('Δεν δημιουργήθηκε παραστατικό')
                                ->body('Δεν υπάρχει εκκρεμής χρέωση για τον τρέχοντα κύκλο (ή υπάρχει ήδη ανοιχτό πρόχειρο).')
                                ->warning()->send();

                            return;
                        }
                        Notification::make()
                            ->title('Δημιουργήθηκε πρόχειρο παραστατικό')
                            ->body('Κωδικός: '.$invoice->invcode.' — εκδώστε το από τα Παραστατικά.')
                            ->success()->send();
                        $this->redirect(InvoiceResource::getUrl('view', ['record' => $invoice, 'tenant' => $record->company]));
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Αποτυχία δημιουργίας παραστατικού')
                            ->body($e->getMessage())
                            ->danger()->persistent()->send();
                    }
                }),
        ];
    }

    /**
     * The invoices eligible to be retro-linked to $record: same tenant + same
     * customer, LIVE + ISSUED (not a draft, not cancelled/AADE-cancelled), NOT a
     * credit note, and NOT already linked to any contract. One predicate shared
     * by the picker AND the write, so a crafted id can never attach a foreign/
     * ineligible invoice. Excluding cancelled matters: stamping «last invoiced»
     * from a cancelled document would wrongly suppress the first renewal's setup
     * fee (StageServiceRenewal gates that on last_invoiced_at === null).
     */
    private static function linkableInvoiceQuery(ServiceContract $record): Builder
    {
        $query = Invoice::query()
            ->where('company_id', $record->company_id)
            ->where('customer_id', $record->customer_id)
            ->whereNull('service_contract_id')
            ->where('local_status', '!=', 'draft');
        InvoiceScope::live($query);
        InvoiceScope::excludeCreditNotes($query);

        return $query;
    }

    /** «ΤΠΥ5 — 17/09/2026 — 5.952,00 €» — one label for both the picker and the selected value. */
    private static function invoiceOptionLabel(Invoice $invoice): string
    {
        return ($invoice->invcode ?? ('#'.$invoice->id))
            .' — '.($invoice->issued_at?->format('d/m/Y') ?? '—')
            .' — '.Money::eur($invoice->gross_total);
    }

    /**
     * Cascade: cancel the contract's UNISSUED draft renewals (draft +
     * no MARK). Returns the number cancelled. Legal documents (MARK'd or
     * non-draft) are left untouched. Explicit company_id scope for safety.
     */
    public static function cancelUnissuedDrafts(ServiceContract $record): int
    {
        return Invoice::query()
            ->where('company_id', $record->company_id)
            ->where('service_contract_id', $record->id)
            ->where('local_status', 'draft')
            ->whereNull('mydata_mark')
            ->update(['local_status' => 'cancelled']);
    }

    protected static function notifyCascade(string $title, int $cancelled): void
    {
        $body = $cancelled > 0
            ? "Ακυρώθηκαν $cancelled πρόχειρα παραστατικά ανανέωσης."
            : 'Δεν υπήρχαν πρόχειρα παραστατικά ανανέωσης προς ακύρωση.';
        Notification::make()->title($title)->body($body)->success()->send();
    }

    protected function redirectToView(ServiceContract $record): void
    {
        $this->redirect(static::getResource()::getUrl('view', ['record' => $record, 'tenant' => $record->company]));
    }
}
